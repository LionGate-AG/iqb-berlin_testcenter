-- Drop single-column indexes that a composite index or unique constraint already leads with. Each of them only
-- adds work to every insert into these tables, which happens on every login and every test start. Lookups and
-- the foreign keys' cascading deletes use the remaining index:
--   login_sessions (workspace_id)       -> login_sessions_groups_fk (workspace_id, group_name)
--   login_sessions (name)               -> unique_login_session (name, workspace_id)
--   person_sessions (login_sessions_id) -> unique_person_session (login_sessions_id, name_suffix)
--   tests (person_id)                   -> tests_person_id_name_key (person_id, name)

DROP INDEX IF EXISTS index_fk_login_workspace;
DROP INDEX IF EXISTS index_fk_logins;
DROP INDEX IF EXISTS index_fk_person_login;
DROP INDEX IF EXISTS index_fk_booklet_person;
