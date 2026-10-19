<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class S3DriverTest extends TestCase {
  function setUp(): void {
    SystemConfig::$storage_s3Endpoint = 'http://s3.internal:8333';
    SystemConfig::$storage_s3PublicEndpoint = 'https://s3.example.org';
    SystemConfig::$storage_s3Region = 'eu01';
    SystemConfig::$storage_s3Bucket = 'tc-files';
    SystemConfig::$storage_s3PathStyle = true;
    SystemConfig::$storage_s3AccessKey = 'key';
    SystemConfig::$storage_s3SecretKey = 'secret';
  }

  function test_presignGet_signsAgainstThePublicEndpoint(): void {
    $url = (new S3Driver())->presignGet('ws_1/Resource/player.html', 600);

    $this->assertStringStartsWith('https://s3.example.org/tc-files/ws_1/Resource/player.html?', $url);
  }

  function test_presignGet_disablesOnTheFlyCompression(): void {
    $url = (new S3Driver())->presignGet('ws_1/Resource/player.html', 600);
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $this->assertSame('identity', $query['response-content-encoding'] ?? null);
    $this->assertArrayHasKey('X-Amz-Signature', $query);
  }
}
