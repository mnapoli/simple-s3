<?php declare(strict_types=1);

namespace Mnapoli\SimpleS3;

use DOMDocument;
use RuntimeException;

/**
 * @phpstan-type Response array{ int, string, array<string, string> }
 */
class SimpleS3
{
    private string $accessKeyId;
    private string $secretKey;
    private ?string $sessionToken;
    private string $region;
    private ?string $endpoint;
    private int $timeoutInSeconds = 5;

    public static function fromEnvironmentVariables(string $region): self
    {
        return new self(
            $_SERVER['AWS_ACCESS_KEY_ID'],
            $_SERVER['AWS_SECRET_ACCESS_KEY'],
            $_SERVER['AWS_SESSION_TOKEN'],
            $region,
        );
    }

    public function __construct(string $accessKeyId, string $secretKey, ?string $sessionToken, string $region, ?string $endpoint = null)
    {
        $this->accessKeyId = $accessKeyId;
        $this->secretKey = $secretKey;
        $this->sessionToken = $sessionToken;
        $this->region = $region;
        $this->endpoint = $endpoint;
    }

    public function setTimeout(int $timeoutInSeconds): SimpleS3
    {
        $this->timeoutInSeconds = $timeoutInSeconds;
        return $this;
    }

    /**
     * @param Array<string, string> $headers
     * @return Response
     * @throws RuntimeException If the request failed.
     */
    public function get(string $bucket, string $key, array $headers = []): array
    {
        return $this->s3Request('GET', $bucket, $key, $headers);
    }

    /**
     * Convenience helper to return only the response body.
     *
     * @param Array<string, string> $headers
     * @throws RuntimeException If the request failed.
     */
    public function getBody(string $bucket, string $key, array $headers = []): string
    {
        [, $body] = $this->get($bucket, $key, $headers);
        return $body;
    }

    /**
     * Stream the object into a temporary stream for direct output (e.g., fpassthru).
     * Caller is responsible for closing the returned stream.
     *
     * @param Array<string, string> $headers
     * @return array{int, resource, array<string, string>}
     * @throws RuntimeException If the request failed.
     */
    public function getStream(string $bucket, string $key, array $headers = []): array
    {
        $fp = fopen('php://temp', 'w+');
        if (! $fp) {
            throw new RuntimeException('Could not open temporary stream');
        }

        [$status, , $responseHeaders] = $this->s3Request('GET', $bucket, $key, $headers, '', true, $fp);
        rewind($fp);

        return [$status, $fp, $responseHeaders];
    }

    /**
     * Stream the object directly to a file to avoid loading into memory.
     *
     * @param Array<string, string> $headers
     * @return Response
     * @throws RuntimeException If the request failed.
     */
    public function getToFile(string $bucket, string $key, string $destPath, array $headers = []): array
    {
        $fp = fopen($destPath, 'wb');
        if (! $fp) {
            throw new RuntimeException('Could not open destination file for writing');
        }

        try {
            return $this->s3Request('GET', $bucket, $key, $headers, '', true, $fp);
        } finally {
            fclose($fp);
        }
    }

    /**
     * `get()` will throw if the object doesn't exist.
     * This method will return a 404 status and not throw instead.
     *
     * @param Array<string, string> $headers
     * @return Response
     * @throws RuntimeException If the request failed.
     */
    public function getIfExists(string $bucket, string $key, array $headers = []): array
    {
        return $this->s3Request('GET', $bucket, $key, $headers, '', false);
    }

    /**
     * @param Array<string, string> $headers
     * @return Response
     * @throws RuntimeException If the request failed.
     */
    /**
     * @param string|resource $content
     */
    public function put(string $bucket, string $key, $content, array $headers = []): array
    {
        return $this->s3Request('PUT', $bucket, $key, $headers, $content);
    }

    /**
     * @param Array<string, string> $headers
     * @return Response
     * @throws RuntimeException If the request failed.
     */
    public function delete(string $bucket, string $key, array $headers = []): array
    {
        return $this->s3Request('DELETE', $bucket, $key, $headers);
    }

    /**
     * @param Array<string, string> $headers
     * @param string|resource $body
     * @return Response
     * @throws RuntimeException If the request failed.
     */
    private function s3Request(string $httpVerb, string $bucket, string $key, array $headers, $body = '', bool $throwOn404 = true, $responseBodyStream = null): array
    {
        $uriPath = str_replace('%2F', '/', rawurlencode($key));
        $uriPath = '/' . ltrim($uriPath, '/');
        $queryString = '';
        $hostname = $this->getHostname($bucket);
        $headers['host'] = $hostname;

        $isStream = \is_resource($body);
        $bodyHash = $isStream ? 'UNSIGNED-PAYLOAD' : hash('sha256', $body);

        if ($isStream && !isset($headers['content-length'])) {
            $stat = fstat($body);
            if (is_array($stat) && isset($stat['size']) && $stat['size'] >= 0) {
                $headers['content-length'] = (string) $stat['size'];
            }
        }

        // Sign the request via headers
        $headers = $this->signRequest($httpVerb, $uriPath, $queryString, $headers, $bodyHash);

        if ($this->endpoint) {
            $url = $this->endpoint;
        } else {
            $url = "https://$hostname";
        }
        $url = "$url{$uriPath}?$queryString";

        [$status, $body, $responseHeaders] = $this->curlRequest($httpVerb, $url, $headers, $body, $isStream, $responseBodyStream);

        $shouldThrow404 = $throwOn404 && ($status === 404);
        if ($shouldThrow404 || $status < 200 || ($status >= 400 && $status !== 404)) {
            $errorMessage = '';
            if ($body) {
                $dom = new DOMDocument;
                $priorUseInternalErrors = libxml_use_internal_errors(true);
                $loaded = $dom->loadXML($body);
                libxml_clear_errors();
                libxml_use_internal_errors($priorUseInternalErrors);

                if ($loaded && $dom->childNodes->item(0)->nodeName === 'Error') {
                    $errorMessage = $dom->childNodes->item(0)->textContent;
                }
            }
            throw $this->httpError($status, $errorMessage);
        }

        return [$status, $body, $responseHeaders];
    }

    /**
     * @param Array<string, string> $headers
     * @param string|resource $body
     * @return Response
     * @throws RuntimeException If the request failed.
     */
    private function curlRequest(string $httpVerb, string $url, array $headers, $body, bool $isStream = false, $responseBodyStream = null): array
    {
        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = "$name: $value";
        }

        $ch = curl_init($url);
        if (! $ch) {
            throw $this->httpError(null, 'could not create a CURL request for an unknown reason');
        }

        $responseHeadersAsString = '';
        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => $httpVerb,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutInSeconds,
            // Retrieve the response headers
            CURLOPT_HEADERFUNCTION => function ($c, $data) use (&$responseHeadersAsString) {
                $responseHeadersAsString .= $data;
                return strlen($data);
            },
        ];

        if ($responseBodyStream) {
            $curlOptions[CURLOPT_FILE] = $responseBodyStream;
            $curlOptions[CURLOPT_RETURNTRANSFER] = false;
        } else {
            // So that `curl_exec` returns the response body
            $curlOptions[CURLOPT_RETURNTRANSFER] = true;
        }

        if ($isStream) {
            $curlOptions[CURLOPT_UPLOAD] = true;
            $curlOptions[CURLOPT_INFILE] = $body;
            if (isset($headers['content-length'])) {
                $curlOptions[CURLOPT_INFILESIZE] = (int) $headers['content-length'];
            }
        } else {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $curlOptions);
        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $success = $status !== 0 && $status !== 100 && $status !== 500 && $status !== 502 && $status !== 503;
        if ($responseBody === false || ! $success || curl_errno($ch) > 0) {
            throw $this->httpError($status, curl_error($ch));
        }

        $responseHeaders = iconv_mime_decode_headers(
            $responseHeadersAsString,
            ICONV_MIME_DECODE_CONTINUE_ON_ERROR,
            'UTF-8',
        ) ?: [];

        if ($responseBodyStream) {
            return [$status, '', $responseHeaders];
        }

        return [$status, (string) $responseBody, $responseHeaders];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string> Modified headers
     */
    private function signRequest(
        string $httpVerb,
        string $uriPath,
        string $queryString,
        array $headers,
        string $bodyHash
    ): array {
        $dateAsText = gmdate('Ymd');
        $timeAsText = gmdate('Ymd\THis\Z');
        $scope = "$dateAsText/{$this->region}/s3/aws4_request";
        $bodySignature = $bodyHash;

        $headers['x-amz-date'] = $timeAsText;
        $headers['x-amz-content-sha256'] = $bodySignature;
        if ($this->sessionToken) {
            $headers['x-amz-security-token'] = $this->sessionToken;
        }

        // Ensure the headers always have the same order to have a valid AWS signature
        $headers = $this->sortHeadersByName($headers);

        // https://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html
        $headerNamesAsString = implode(';', array_map('strtolower', array_keys($headers)));
        $headerString = '';
        foreach ($headers as $key => $value) {
            $headerString .= strtolower($key) . ':' . trim($value) . "\n";
        }

        $canonicalRequest = "$httpVerb\n$uriPath\n$queryString\n$headerString\n$headerNamesAsString\n$bodySignature";

        $stringToSign = "AWS4-HMAC-SHA256\n$timeAsText\n$scope\n" . hash('sha256', $canonicalRequest);
        $signingKey = hash_hmac(
            'sha256',
            'aws4_request',
            hash_hmac(
                'sha256',
                's3',
                hash_hmac(
                    'sha256',
                    $this->region,
                    hash_hmac('sha256', $dateAsText, 'AWS4' . $this->secretKey, true),
                    true,
                ),
                true,
            ),
            true,
        );
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/$scope,SignedHeaders=$headerNamesAsString,Signature=$signature";

        return $headers;
    }

    private function getHostname(string $bucketName): string
    {
        if ($this->region === 'us-east-1') return "$bucketName.s3.amazonaws.com";

        return "$bucketName.s3-{$this->region}.amazonaws.com";
    }

    private function httpError(?int $status, ?string $message): RuntimeException
    {
        return new RuntimeException("AWS S3 request failed: $status $message");
    }

    /**
     * @param Array<string, string> $headers
     * @return Array<string, string>
     */
    private function sortHeadersByName(array $headers): array
    {
        ksort($headers, SORT_STRING | SORT_FLAG_CASE);
        return $headers;
    }
}
