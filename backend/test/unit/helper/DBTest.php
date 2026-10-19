<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class DBTest extends TestCase {
  private bool $persistentConnections;

  function setUp(): void {
    $this->persistentConnections = SystemConfig::$database_persistentConnections;
  }

  function tearDown(): void {
    SystemConfig::$database_persistentConnections = $this->persistentConnections;
    DB::connectToTestDBWithRetries(10, 5);
  }

  /**
   * A connection pooler in transaction mode cannot handle the `DEALLOCATE` PDO sends for named prepared statements,
   * so no statement may leave a named prepared statement on the server.
   */
  function test_connectionsLeaveNoNamedPreparedStatementsOnTheServer(): void {
    DB::connectToTestDBWithRetries(10, 5);
    $pdo = DB::getConnection();

    $statement = $pdo->prepare('select :value::int');
    $this->assertNotFalse($statement);
    $statement->execute([':value' => 42]);
    $this->assertSame(42, $statement->fetchColumn());

    $preparedStatements = $pdo->query('select count(*) from pg_prepared_statements');
    $this->assertNotFalse($preparedStatements);
    $this->assertSame(0, $preparedStatements->fetchColumn());
  }

  function test_connectIsPersistentOnlyWhenConfigured(): void {
    SystemConfig::$database_persistentConnections = true;
    DB::connect();
    $this->assertTrue(DB::getConnection()->getAttribute(PDO::ATTR_PERSISTENT));

    SystemConfig::$database_persistentConnections = false;
    DB::connect();
    $this->assertFalse(DB::getConnection()->getAttribute(PDO::ATTR_PERSISTENT));
  }

  function test_testDatabaseConnectionIsNeverPersistent(): void {
    SystemConfig::$database_persistentConnections = true;
    DB::connectToTestDB();
    $this->assertFalse(DB::getConnection()->getAttribute(PDO::ATTR_PERSISTENT));
  }
}
