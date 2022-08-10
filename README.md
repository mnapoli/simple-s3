Simple, single-file and dependency-free AWS S3 client.

## Why?

In some scenarios we want the **simplest and lightest S3 client** possible. For example in [Bref](https://bref.sh)'s runtime core we don't want to embed the full AWS SDK.

## Installation

```sh
composer require mnapoli/simple-s3
```

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
