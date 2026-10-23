<?php
declare(strict_types=1);

/**
 * In-memory object store for tests of the object-store code paths. Records every call, so a test can assert that a
 * request was answered without touching the store.
 */
class StorageDriverFake implements StorageDriver {
  /** @var array<string, string> key => contents */
  public array $objects = [];
  /** @var list<string> "<method> <key>" of every call */
  public array $calls = [];

  /**
   * @param array<string, string> $objects
   */
  public function __construct(array $objects = []) {
    $this->objects = $objects;
  }

  private function call(string $method, string $key): void {
    $this->calls[] = "$method $key";
  }

  public function put(string $logicalPath, string $localSourcePath): void {
    $this->call('put', $logicalPath);
    $this->objects[$logicalPath] = (string) file_get_contents($localSourcePath);
  }

  public function putContents(string $logicalPath, string $contents): void {
    $this->call('putContents', $logicalPath);
    $this->objects[$logicalPath] = $contents;
  }

  public function get(string $logicalPath): string {
    $this->call('get', $logicalPath);
    return $this->objects[$logicalPath] ?? throw new RuntimeException("No such key: $logicalPath");
  }

  /** @return resource */
  public function getStream(string $logicalPath) {
    $handle = fopen('php://memory', 'r+b');
    if ($handle === false) {
      throw new RuntimeException('Could not open php://memory');
    }
    fwrite($handle, $this->get($logicalPath));
    rewind($handle);
    return $handle;
  }

  public function exists(string $logicalPath): bool {
    $this->call('exists', $logicalPath);
    return array_key_exists($logicalPath, $this->objects);
  }

  public function delete(string $logicalPath): void {
    $this->call('delete', $logicalPath);
    unset($this->objects[$logicalPath]);
  }

  public function copy(string $from, string $to): void {
    $this->call('copy', $from);
    $this->objects[$to] = $this->objects[$from];
  }

  /** @return list<string> */
  public function list(string $prefix): array {
    $this->call('list', $prefix);
    return array_values(array_filter(array_keys($this->objects), fn(string $key): bool => str_starts_with($key, $prefix)));
  }

  public function size(string $logicalPath): int {
    $this->call('size', $logicalPath);
    return strlen($this->objects[$logicalPath] ?? '');
  }

  public function mtime(string $logicalPath): int {
    $this->call('mtime', $logicalPath);
    return 0;
  }

  public function presignGet(string $logicalPath, int $ttlSeconds): string {
    $this->call('presignGet', $logicalPath);
    return "https://s3.example.org/tc-files/$logicalPath?X-Amz-Expires=$ttlSeconds";
  }
}
