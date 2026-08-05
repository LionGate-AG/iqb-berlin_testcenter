<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

class DB {
  private static PDO $pdo;

  static function connect(): void {
    // Persistent: without this, every single request opens a brand-new PDO
    // connection (fresh TCP + MySQL/ProxySQL auth handshake) and tears it down
    // at request end -- under load that's a connect-storm through ProxySQL, not
    // a steady pool. PHP-FPM keeps one persistent connection per worker process
    // and reuses it across that worker's requests instead.
    self::$pdo = self::connectWithRetry(
      "mysql:host=" . SystemConfig::$database_host . ";port=" . SystemConfig::$database_port . ";dbname=" . SystemConfig::$database_name,
      SystemConfig::$database_user,
      SystemConfig::$database_password,
      [PDO::ATTR_PERSISTENT => true]
    );
  }

  static function connectToTestDB(): void {
    // Deliberately NOT persistent: this connects to a throwaway TEST_* schema
    // that test runs create/drop around it. A persistent connection could
    // survive a schema reset and hand a later test a stale/dangling reference.
    self::$pdo = new PDO(
      "mysql:host=" . SystemConfig::$database_host . ";port=" . SystemConfig::$database_port . ";dbname=TEST_" . SystemConfig::$database_name,
      SystemConfig::$database_user,
      SystemConfig::$database_password
    );
  }

  // Absorbs a transient connect failure (e.g. a momentary ProxySQL/MySQL
  // contention spike) instead of surfacing it straight to the user as a 500.
  // Does not fix capacity -- just rides out short blips.
  private static function connectWithRetry($dsn, $user, $password, array $options, int $attempts = 3, int $backoffMs = 100): PDO {
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
      try {
        return new PDO($dsn, $user, $password, $options);
      } catch (PDOException $e) {
        if ($attempt === $attempts) {
          throw $e;
        }
        usleep($backoffMs * 1000);
      }
    }
  }

  static function getConnection(): PDO {
    if (!isset(self::$pdo)) {
      throw new Exception("DB connection not set up yet.");
    }
    return self::$pdo;
  }
}
