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
    public function put(string $bucket, string $key, string $content, array $headers = []): array
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
     * @return Response
     * @throws RuntimeException If the request failed.
     */
    private function s3Request(string $httpVerb, string $bucket, string $key, array $headers, string $body = '', bool $throwOn404 = true): array
    {
        $uriPath = str_replace('%2F', '/', rawurlencode($key));
        $uriPath = '/' . ltrim($uriPath, '/');
        $queryString = '';
        $hostname = $this->getHostname($bucket);
        $headers['host'] = $hostname;

        // Sign the request via headers
        $headers = $this->signRequest($httpVerb, $uriPath, $queryString, $headers, $body);

        if ($this->endpoint) {
            $url = $this->endpoint;
        } else {
            $url = "https://$hostname";
        }
        $url = "$url{$uriPath}?$queryString";

        [$status, $body, $responseHeaders] = $this->curlRequest($httpVerb, $url, $headers, $body);

        $shouldThrow404 = $throwOn404 && ($status === 404);
        if ($shouldThrow404 || $status < 200 || ($status >= 400 && $status !== 404)) {
            $errorMessage = '';
            if ($body) {
                $dom = new DOMDocument;
                if (! $dom->loadXML($body)) {
                    throw new RuntimeException('Could not parse the AWS S3 response: ' . $body);
                }
                if ($dom->childNodes->item(0)->nodeName === 'Error') {
                    $errorMessage = $dom->childNodes->item(0)->textContent;
                }
            }
            throw $this->httpError($status, $errorMessage);
        }

        return [$status, $body, $responseHeaders];
    }

    /**
     * @param Array<string, string> $headers
     * @return Response
     * @throws RuntimeException If the request failed.
     */
    private function curlRequest(string $httpVerb, string $url, array $headers, string $body): array
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
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $httpVerb,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutInSeconds,
            CURLOPT_POSTFIELDS => $body,
            // So that `curl_exec` returns the response body
            CURLOPT_RETURNTRANSFER => true,
            // Retrieve the response headers
            CURLOPT_HEADERFUNCTION => function ($c, $data) use (&$responseHeadersAsString) {
                $responseHeadersAsString .= $data;
                return strlen($data);
            },
        ]);
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
        string $body
    ): array {
        $dateAsText = gmdate('Ymd');
        $timeAsText = gmdate('Ymd\THis\Z');
        $scope = "$dateAsText/{$this->region}/s3/aws4_request";
        $bodySignature = hash('sha256', $body);

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
