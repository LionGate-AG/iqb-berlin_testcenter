<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

/**
 * Read queries for the TBA-Portal integration (server-to-server, static ANSWERS_TOKEN). The portal has
 * no database access to the testcenter; everything it needs from the testcenter's tables comes from here.
 * Maps keyed by id are returned as objects so that empty results serialize as {} instead of [].
 */
class PortalDAO extends DAO {
  /** All workspaces with their booklets: {"<wsId>": {name, booklets: {"<fileId>": {label, description}}}} */
  public function getWorkspaceOverview(): object {
    $workspaces = $this->_('select id, name from workspaces order by id', [], true);
    $booklets = $this->_(
      "select workspace_id as \"workspaceId\", id, label, description
         from files
        where type = 'Booklet'
        order by workspace_id, id",
      [],
      true
    );

    $result = [];
    foreach ($workspaces as $workspace) {
      $result[(string) $workspace['id']] = ['name' => $workspace['name'], 'booklets' => []];
    }
    foreach ($booklets as $booklet) {
      $workspaceKey = (string) $booklet['workspaceId'];
      if (!isset($result[$workspaceKey])) {
        continue;
      }
      $result[$workspaceKey]['booklets'][$booklet['id']] = [
        'label' => $booklet['label'],
        'description' => $booklet['description'],
      ];
    }
    foreach ($result as &$workspace) {
      $workspace['booklets'] = (object) $workspace['booklets'];
    }
    unset($workspace);

    return (object) $result;
  }

  /** Whether `$fileName` is a Verona player of the workspace (a Resource file with verona_module_type 'player'). */
  public function isPlayer(int $workspaceId, string $fileName): bool {
    return $this->_(
      "select 1 as found
         from files
        where workspace_id = :workspaceId
          and type = 'Resource'
          and name = :name
          and verona_module_type = 'player'
        limit 1",
      [':workspaceId' => $workspaceId, ':name' => $fileName]
    ) !== null;
  }

  /** Booklet file id of every test of the workspace: {"<testId>": "<bookletFileId>"} */
  public function getTestBookletIds(int $workspaceId): object {
    $rows = $this->_(
      'select t.id as "testId", t.file_id as "fileId"
         from tests t
         join person_sessions ps on t.person_id = ps.id
         join login_sessions ls on ps.login_sessions_id = ls.id
        where ls.workspace_id = :workspaceId
          and t.file_id is not null
        order by t.id',
      [':workspaceId' => $workspaceId],
      true
    );

    $result = [];
    foreach ($rows as $row) {
      $result[(string) $row['testId']] = $row['fileId'];
    }
    return (object) $result;
  }

  /** Codable units (with a coding scheme) per booklet: {"<bookletFileId>": ["<unitFileId>", ...]} */
  public function getBookletUnits(int $workspaceId): object {
    $rows = $this->_(
      "select distinct booklet.id as \"bookletId\", unit.id as \"unitId\"
         from files booklet
         join file_relations fr_contains
           on fr_contains.workspace_id = booklet.workspace_id
          and fr_contains.subject_name = booklet.name
          and fr_contains.subject_type = booklet.type
          and fr_contains.relationship_type = 'containsUnit'
         join files unit
           on unit.workspace_id = fr_contains.workspace_id
          and unit.name = fr_contains.object_name
          and unit.type = fr_contains.object_type
          and unit.type = 'Unit'
        where booklet.workspace_id = :workspaceId
          and booklet.type = 'Booklet'
          and exists (
                select 1
                  from file_relations fr_scheme
                 where fr_scheme.workspace_id = unit.workspace_id
                   and fr_scheme.subject_name = unit.name
                   and fr_scheme.subject_type = unit.type
                   and fr_scheme.relationship_type = 'usesScheme'
              )
        order by booklet.id, unit.id",
      [':workspaceId' => $workspaceId],
      true
    );

    $result = [];
    foreach ($rows as $row) {
      $result[$row['bookletId']][] = $row['unitId'];
    }
    return (object) $result;
  }

  /**
   * Person-session tokens (name_suffix) per testee code and booklet, for the QUA-LiS export.
   * @param string[] $codes person_sessions.code values (= portal testperson UUIDs)
   * @return array<int, array{code: string, bookletFileId: string, nameSuffix: string}>
   */
  public function getTesteeTokens(int $workspaceId, array $codes): array {
    if (!$codes) {
      return [];
    }

    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    return $this->_(
      "select distinct ps.code, t.file_id as \"bookletFileId\", ps.name_suffix as \"nameSuffix\"
         from person_sessions ps
         join login_sessions ls on ps.login_sessions_id = ls.id
         join tests t on t.person_id = ps.id
        where ls.workspace_id = ?
          and ps.code in ($placeholders)
          and t.file_id is not null",
      array_merge([$workspaceId], array_values($codes)),
      true
    );
  }
}
