<?php declare(strict_types=1);

namespace Mnapoli\SimpleS3\Test;

use Mnapoli\SimpleS3\SimpleS3;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class SimpleS3Test extends TestCase
{
    private static Process $s3ServerProcess;

    public function test with wrong access keys(): void
    {
        $s3 = new SimpleS3('FOO', 'BAR', '', 'us-east-1', 'http://localhost:4568');
        $this->expectExceptionMessage('AWS S3 request failed: 403 InvalidAccessKeyIdThe AWS Access Key Id you provided does not exist in our records.FOO');
        $s3->get('bucket-name', 'key');
    }

    public function test get unknown bucket(): void
    {
        $s3 = new SimpleS3('S3RVER', 'S3RVER', '', 'us-east-1', 'http://localhost:4568');
        $this->expectExceptionMessage('AWS S3 request failed: 404 NoSuchBucketThe specified bucket does not existbucket-name');
        $s3->get('bucket-name', 'key');
    }

    public function test get unknown object(): void
    {
        $s3 = new SimpleS3('S3RVER', 'S3RVER', '', 'us-east-1', 'http://localhost:4568');
        $this->expectExceptionMessage('AWS S3 request failed: 404 NoSuchKeyThe specified key does not exist.key');
        [$status, $response] = $s3->get('my-bucket', 'key');
        $this->assertEquals('200', $status);
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::startFakeS3Server();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        if (self::$s3ServerProcess) self::$s3ServerProcess->stop();
    }

    private static function startFakeS3Server(): void
    {
        $command = ['npm', 'run', 's3-server', '--', '--directory', __DIR__ . '/fixtures', '--configure-bucket', 'my-bucket'];
        self::$s3ServerProcess = new Process($command, __DIR__);
        self::$s3ServerProcess->start(function ($type, $output) {
            if (trim($output)) echo $output;
        });
        self::$s3ServerProcess->waitUntil(function ($type, $output) {
            if (trim($output)) echo $output;
            return str_contains($output, 'S3rver listening');
        });
    }
}
