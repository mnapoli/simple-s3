Simple, single-file and dependency-free AWS S3 client.

## Why?

In some scenarios we want the **simplest and lightest S3 client** possible. For example in [Bref](https://bref.sh)'s runtime core we don't want to embed the full AWS SDK.

If you need more, you can use the official AWS SDK for PHP, or this great alternative: [Async AWS](https://async-aws.com).

## Installation

This package can be installed via Composer:

```sh
composer require mnapoli/simple-s3
```

However, this package offers a guarantee that **all logic will be self-contained** into the `src/SimpleS3.php` file. If you want to take advantage of that, you can either:

- download the `SimpleS3.php` file and copy it into your application,
- or install the package via Composer and copy `vendor/mnapoli/simple-s3/src/SimpleS3.php` in your application (best because Composer will let you constrain the major version).

## Usage

```php
use Mnapoli\SimpleS3;

$region = 'us-east-1'; // or whatever region you prefer

// Instantiate from AWS credentials in environment variables, for example on AWS Lambda
$s3 = SimpleS3::fromEnvironmentVariables($region);

$s3->put('my-bucket', '/object.json', json_encode(['hello' => 'world']));

[$status, $objectBody] = $s3->get('my-bucket', '/object.json');
echo $objectBody;

$s3->delete('my-bucket', '/object.json');
```

You can also instantiate the class by providing AWS credentials explicitly:

```php
$s3 = new SimpleS3($accessKeyId, $secretKey, $sessionToken, $region);
```

Any error (400, 403, 404, 500…) will be thrown as an exception. Sometimes a 404 is expected and we don't want a generic exception: look at the `getIfExists()` example below.

Note: only a subset of the AWS S3 API is supported by this package (CRUD files in a bucket basically).

## Examples

`$s3->get()` will throw an exception if the key doesn't exist. You can use `getIfExists()` to get an empty `$body` instead:

```php
[$status, $body] = $s3->getIfExists('my-bucket', $key);
if ($status === 404) {
    echo 'Not found';
} else {
    echo $body;
}
```

Get an object only if it was changed:

```php
[$status, $body, $responseHeaders] = $s3->get('my-bucket', $key, [
    'If-None-Match' => $previousEtag,
]);
if ($status === 304) {
    echo 'Object up to date!';
} else {
    $newObjectBody = $body;
    $newEtag = $responseHeaders['ETag'];
}
```

### Body/Stream Helpers

`getBody()` and `getStream()` were added for convenience when fetching objects.

Get a file’s contents as a string:

```php
$contents = $s3->getBody($bucket, 'path/to/file.jpg');
// $contents is the raw object body (string).
```

Stream a file directly to output:

```php
[$status, $stream, $headers] = $s3->getStream($bucket, 'path/to/video.mp4');

header('Content-Type: ' . ($headers['Content-Type'] ?? 'application/octet-stream'));
header('Content-Length: ' . ($headers['Content-Length'] ?? ''));
fpassthru($stream);
fclose($stream); // important to close the file the function opened
```

Upload from a resource (stream):

```php
$fp = fopen('/path/to/local/file.mp4', 'rb');

$headers = [
    'Content-Type' => 'video/mp4',
    // Optional but recommended if size is known:
    'content-length' => (string) filesize('/path/to/local/file.mp4'),
];

$s3->put($bucket, 'uploads/file.mp4', $fp, $headers);
fclose($fp); // close the file you opened
```

Upload from a string:

```php
$s3->put($bucket, 'uploads/hello.txt', 'Hello world', [
    'Content-Type' => 'text/plain',
]);
```



