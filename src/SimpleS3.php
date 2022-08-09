<?php declare(strict_types=1);

namespace Mnapoli\SimpleS3;

use DOMDocument;
use RuntimeException;

class SimpleS3
{
    public static $connect_timeout = 10;
    public static $timeout = 60;
    public static $auto_retry_delays = [0, 1, 2]; // set to empty array to disable auto-retrying

    private string $accessKeyId;
    private string $secretKey;
    private string $sessionToken;
    private string $region;
    private ?string $endpoint;

    public function __construct(string $accessKeyId, string $secretKey, string $sessionToken, string $region = 'us-east-1', string $endpoint = null, bool $https = true, int $port = null)
    {
        $this->accessKeyId = $accessKeyId;
        $this->secretKey = $secretKey;
        $this->sessionToken = $sessionToken;
        $this->region = $region;
        $this->endpoint = $endpoint;
    }

    public function get(
        string $bucketName,
        string $objectKey
    ): array {
        $httpVerb = 'GET';
        $uriPath = str_replace('%2F', '/', rawurlencode($objectKey));
        $uriPath = '/' . ltrim($uriPath, '/');
        $body = '';
        $queryString = '';
        $hostname = $this->getHostname($bucketName);
        $headers = [
            'host' => $hostname,
        ];

        // Sign the request via headers
        $headers = array_merge($headers, $this->signRequest($httpVerb, $uriPath, $queryString, $headers, $body));

        if ($this->endpoint) {
            $url = $this->endpoint;
        } else {
            $url = "https://$hostname";
        }
        $url = "$url{$uriPath}?$queryString";

        [$status, $response] = $this->curlRequest($httpVerb, $url, $headers);

        $dom = new DOMDocument();
        if (! $dom->loadXML($response)) {
            throw new RuntimeException('Could not parse the AWS S3 response: ' . $response);
        }
        $errorMessage = '';
        if ($dom->childNodes->item(0)->nodeName === 'Error') {
            $errorMessage = $dom->childNodes->item(0)->textContent;
        }
        if ($status < 200 || $status >= 300) {
            throw $this->httpError($status, $errorMessage);
        }

        return [$status, $response];
    }

    /**
     * @param Array<string, string> $headers
     */
    private function curlRequest(string $httpVerb, string $url, array $headers): array
    {
        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = "$name: $value";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $httpVerb,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $success = $status !== 0 && $status !== 100 && $status !== 500 && $status !== 502 && $status !== 503;
        if ($response === false || ! $success || curl_errno($ch) > 0) {
            throw $this->httpError($status, curl_error($ch));
        }

        return [$status, $response];
    }

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

        ksort($headers, SORT_STRING | SORT_FLAG_CASE);
        $headerNamesAsString = implode("\n", array_map('strtolower', array_keys($headers)));
        $headerString = '';
        foreach ($headers as $key => $value) {
            $headerString .= strtolower($key) . ':' . trim($value);
        }

        $canonicalRequest = "$httpVerb\n$uriPath\n$queryString\n$headerString\n$headerNamesAsString\nUNSIGNED-PAYLOAD";

        $stringToSign = "AWS4-HMAC-SHA256\n$timeAsText\n$scope\n" . hash('sha256', $canonicalRequest);
        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $this->region,
                    hash_hmac('sha256', $dateAsText, 'AWS4' . $this->secretKey, true),
                    true),
                true),
            true
        );
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return [
            'x-amz-content-sha256' => hash('sha256', $body),
            'x-amz-date' => $timeAsText,
            'authorization' => "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/$scope,SignedHeaders=$headerNamesAsString,Signature=$signature",
        ];
    }

    private function getHostname(string $bucketName): ?string
    {
        if ($this->region === 'us-east-1') return "$bucketName.s3.amazonaws.com";
        return "$bucketName.s3-{$this->region}.amazonaws.com";
    }

    private function httpError(?int $status, ?string $message): RuntimeException
    {
        return new RuntimeException("AWS S3 request failed: $status $message");
    }
}
