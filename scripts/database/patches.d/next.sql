-- Give the append-only log tables a real PRIMARY KEY.
--
-- Both `test_logs` and `unit_logs` were created without a PRIMARY KEY and without
-- any UNIQUE index, so InnoDB fell back to its hidden GEN_CLUST_INDEX over a
-- synthetic 6-byte DB_ROW_ID. That row-id is drawn from a counter that is
-- SERIALISED and shared across every such table in the instance, which makes it a
-- single global mutex on the hottest insert path in the system.
--
-- Measured in a 90k-user load test (2026-08-20): `INSERT INTO test_logs` degraded
-- to a 69ms MEDIAN (avg 252ms, max 7.3s, 5.8% of executions over 1s) against a
-- 0.05ms floor, while the two reads on the same request path were unchanged at
-- 0.12ms and 0.22ms medians. The database was not saturated -- CPU 46% of its
-- limit, Innodb_row_lock_current_waits 0, all Innodb_data_pending_* 0 -- so the
-- cost was contention on row-id allocation, not I/O, locking or CPU.
--
-- Consequence chain that made this the fleet-wide bottleneck: each insert held its
-- ProxySQL backend connection ~85x longer than at 40k users, which exhausted the
-- pool (ConnUsed 600 / ConnFree 0 on all three ProxySQL pods, MySQL pinned at
-- max_connections), so PHP-FPM workers blocked on connection acquisition, filled
-- the 300-worker pool, and nginx shed the overflow as 503 (~810/s fleet-wide).
--
-- An AUTO_INCREMENT surrogate is the right key here: there is no natural candidate
-- (`logentry` is TEXT and (booklet_id, timestamp) is not unique), and with
-- innodb_autoinc_lock_mode = 2 (interleaved, the MySQL 8 default) the counter is a
-- lightweight per-table mutex rather than the global row-id one.
--
-- Placed FIRST for readability. Safe: every INSERT in the codebase names its
-- columns explicitly and nothing does `SELECT *` on either table, so column order
-- is not depended upon.
--
-- NOTE ON APPLYING THIS: adding an AUTO_INCREMENT column is one of the few ALTERs
-- that does NOT permit concurrent DML, so it rebuilds the table with writes
-- blocked. On the ~5M-row test_logs that is tens of seconds. Run it while no load
-- test is active.

ALTER TABLE test_logs
  ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;

ALTER TABLE unit_logs
  ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;

-- Drop secondary indexes that duplicate the PRIMARY KEY or are a left prefix of
-- another index on the same table.
--
-- Every INSERT maintains every B-tree on its table, and the onboarding path inserts
-- one row each into login_sessions, person_sessions and tests. In the 2026-09-23
-- fresh-onboarding run (97,886 logins from an empty database) those inserts
-- averaged 170ms, 190ms and 334ms under contention. This removes 3 of the 7
-- B-trees on login_sessions, 2 of the 5 on person_sessions and 1 of the 3 on tests.
--
-- Each dropped index is covered by a remaining one, so lookups and foreign keys keep
-- an index with the same leading column:
--   login_sessions.index_fk_login_session_login (id)         -> PRIMARY (id)
--   login_sessions.index_fk_logins (name)                    -> unique_login_session (name, workspace_id)
--   login_sessions.index_fk_login_workspace (workspace_id)   -> login_sessions_groups_fk (workspace_id, group_name)
--                                                               (also serves FK fk_login_workspace)
--   person_sessions.person_sessions_id_uindex (id)           -> PRIMARY (id)
--   person_sessions.index_fk_person_login (login_sessions_id) -> unique_person_session (login_sessions_id, name_suffix)
--                                                               (also serves FK fk_person_login)
--   tests.index_fk_booklet_person (person_id)                -> person_id (person_id, name)
--                                                               (also serves FK fk_booklet_person)
--
-- Dropping a secondary index is an in-place, metadata-only change in InnoDB; it does
-- not rebuild the table or block DML.

ALTER TABLE login_sessions
  DROP INDEX index_fk_login_session_login,
  DROP INDEX index_fk_logins,
  DROP INDEX index_fk_login_workspace;

ALTER TABLE person_sessions
  DROP INDEX person_sessions_id_uindex,
  DROP INDEX index_fk_person_login;

ALTER TABLE tests
  DROP INDEX index_fk_booklet_person;
