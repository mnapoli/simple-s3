<?php declare(strict_types=1);

namespace Mnapoli\SimpleS3\Test;

use Mnapoli\SimpleS3\SimpleS3;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class SimpleS3Test extends TestCase
{
    private static ?Process $s3ServerProcess;
    private SimpleS3 $s3;

    public function setUp(): void
    {
        parent::setUp();
        // Connect to our fake S3 server running locally
        $this->s3 = new SimpleS3('S3RVER', 'S3RVER', '', 'us-east-1', 'http://localhost:4568');
    }

    public function test with wrong access keys(): void
    {
        $s3 = new SimpleS3('FOO', 'BAR', '', 'us-east-1', 'http://localhost:4568');
        $this->expectExceptionMessage('AWS S3 request failed: 403 InvalidAccessKeyIdThe AWS Access Key Id you provided does not exist in our records.FOO');
        $s3->get('bucket-name', 'key');
    }

    public function test get unknown bucket(): void
    {
        $this->expectExceptionMessage('AWS S3 request failed: 404 NoSuchBucketThe specified bucket does not existbucket-name');
        $this->s3->get('bucket-name', 'key');
    }

    public function test get unknown object(): void
    {
        $this->expectExceptionMessage('AWS S3 request failed: 404 NoSuchKeyThe specified key does not exist.unknown-key');
        [$status] = $this->s3->get('my-bucket', 'unknown-key');
        $this->assertEquals(200, $status);
    }

    public function test put(): void
    {
        [$status] = $this->s3->put('my-bucket', 'test-key', 'foo bar');
        $this->assertEquals(200, $status);
    }

    /**
     * @depends test put
     */
    public function test get(): void
    {
        [$status, $body, $headers] = $this->s3->get('my-bucket', 'test-key');
        $this->assertEquals(200, $status);
        $this->assertEquals('foo bar', $body);
        $this->assertEquals('application/x-www-form-urlencoded', $headers['Content-Type']);
        $this->assertEquals('"327b6f07435811239bc47e1544353273"', $headers['ETag']);
    }

    /**
     * @depends test put
     */
    public function test get with headers(): void
    {
        [$status, $body] = $this->s3->get('my-bucket', 'test-key', [
            'If-Modified-Since' => gmdate('D, d M Y H:i:s T'),
        ]);
        $this->assertEquals(304, $status);
        $this->assertEquals('', $body);
    }

    /**
     * @depends test put
     */
    public function test get if exists(): void
    {
        [$status, $body] = $this->s3->getIfExists('my-bucket', 'test-key');
        $this->assertEquals(200, $status);
        $this->assertEquals('foo bar', $body);

        [$status] = $this->s3->getIfExists('my-bucket', 'unknown-key');
        $this->assertEquals(404, $status);
    }

    /**
     * @depends test put
     */
    public function test delete(): void
    {
        [$status] = $this->s3->delete('my-bucket', 'test-key');
        $this->assertEquals(204, $status);
        [$status] = $this->s3->getIfExists('my-bucket', 'test-key');
        $this->assertEquals(404, $status);
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
        $fs = new Filesystem;
        $fs->remove(__DIR__ . '/fixtures/my-bucket');

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
