<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

class DB {
  private static PDO $pdo;

  /**
   * With `DB_PERSISTENT_CONNECTIONS`, each server process keeps its connection open across requests instead of
   * opening a new one (TCP and authentication handshake) for every request and closing it afterwards. PDO rolls back
   * a transaction left open at the end of a request, and the backend keeps no other session state.
   */
  static function connect(): void {
    self::$pdo = self::open(SystemConfig::$database_name, SystemConfig::$database_persistentConnections);
  }

  /**
   * Never persistent: the test database is dropped and re-created between test runs, which a persistent
   * connection could outlive.
   */
  static function connectToTestDB(): void {
    self::$pdo = self::open(self::testDatabaseName());
  }

  static function connectWithRetries(int $attempts = 5, int $delaySeconds = 20): void {
    self::$pdo = self::openWithRetries(SystemConfig::$database_name, $attempts, $delaySeconds);
  }

  static function connectToTestDBWithRetries(int $attempts = 5, int $delaySeconds = 20): void {
    self::$pdo = self::openWithRetries(self::testDatabaseName(), $attempts, $delaySeconds);
  }

  static function getConnection(): PDO {
    if (!isset(self::$pdo)) {
      throw new Exception("DB connection not set up yet.");
    }
    return self::$pdo;
  }

  private static function testDatabaseName(): string {
    return 'TEST_' . SystemConfig::$database_name;
  }

  /**
   * Named prepared statements are disabled on every connection, so the backend works behind a connection pooler in
   * transaction mode (e.g. PgBouncer): PDO frees named statements with the SQL command `DEALLOCATE`, which such a
   * pooler may send to a different server connection than the one that prepared the statement. The failing
   * `DEALLOCATE` aborts the surrounding transaction. Without named statements, PDO sends each query together with its
   * parameters as an unnamed statement; the parameters are still bound by the server.
   *
   * @throws DbConnectionException
   */
  private static function open(string $databaseName, bool $persistent = false): PDO {
    $dsn = "pgsql:host=" . SystemConfig::$database_host
      . ";port=" . SystemConfig::$database_port
      . ";dbname=" . $databaseName;
    $options = [
      PDO::ATTR_PERSISTENT => $persistent,
      PDO::PGSQL_ATTR_DISABLE_PREPARES => true
    ];

    try {
      $pdo = new PDO($dsn, SystemConfig::$database_user, SystemConfig::$database_password, $options);
    } catch (PDOException $exception) {
      throw new DbConnectionException($databaseName, $exception);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
  }

  /**
   * Every failure is retried: the reason is reported, never interpreted. A wrong password therefore costs the full
   * set of attempts, just as an unreachable host does.
   *
   * @throws DbConnectionException on the last failed attempt
   */
  private static function openWithRetries(string $databaseName, int $attempts, int $delaySeconds): PDO {
    for ($attempt = 1; ; $attempt++) {
      try {
        CLI::p("Database connection attempt $attempt of $attempts.");
        $pdo = self::open($databaseName);
        CLI::success("Database connection successful!");
        return $pdo;

      } catch (DbConnectionException $exception) {
        CLI::warning($exception->getMessage());

        if ($attempt >= $attempts) {
          throw $exception;
        }

        CLI::warning("Retrying in $delaySeconds seconds.");
        sleep($delaySeconds); // give database container time to come up
      }
    }
  }
}
