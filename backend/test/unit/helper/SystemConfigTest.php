<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * readEnvironment() rewrites the global configuration, so every test runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SystemConfigTest extends TestCase {
  private const array S3_VARIABLES = [
    'S3_ENDPOINT', 'S3_PUBLIC_ENDPOINT', 'S3_REGION', 'S3_BUCKET', 'S3_USE_PATH_STYLE', 'S3_USE_SSL',
    'S3_ACCESS_KEY', 'S3_SECRET_KEY', 'S3_PRESIGN_TTL'
  ];

  function setUp(): void {
    putenv('STORAGE_DRIVER=s3');
  }

  function test_objectStore_unsetVariablesFallBackToTheirDefaults(): void {
    foreach (self::S3_VARIABLES as $name) {
      putenv($name);
    }

    SystemConfig::readEnvironment();

    $this->assertSame('s3', SystemConfig::$storage_driver);
    $this->assertSame('us-east-1', SystemConfig::$storage_s3Region);
    $this->assertSame('', SystemConfig::$storage_s3Endpoint);
    $this->assertSame(3600, SystemConfig::$storage_presignTtl);
  }

  function test_objectStore_emptyVariablesFallBackToTheirDefaults(): void {
    foreach (self::S3_VARIABLES as $name) {
      putenv("$name=");
    }

    SystemConfig::readEnvironment();

    $this->assertSame('us-east-1', SystemConfig::$storage_s3Region);
    $this->assertSame(3600, SystemConfig::$storage_presignTtl);
  }

  function test_objectStore_setVariablesAreRead(): void {
    putenv('S3_REGION=eu01');
    putenv('S3_BUCKET=tc-files');
    putenv('S3_PRESIGN_TTL=600');

    SystemConfig::readEnvironment();

    $this->assertSame('eu01', SystemConfig::$storage_s3Region);
    $this->assertSame('tc-files', SystemConfig::$storage_s3Bucket);
    $this->assertSame(600, SystemConfig::$storage_presignTtl);
  }

  function test_objectStore_malformedPresignTtlIsRejected(): void {
    putenv('S3_PRESIGN_TTL=1h');

    $this->expectExceptionMessage('S3_PRESIGN_TTL');
    SystemConfig::readEnvironment();
  }
}
