<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Slim\Http\Response;
use Slim\Http\ServerRequest as Request;

/**
 * Read endpoints for the TBA-Portal (server-to-server, guarded by RequireStaticToken('ANSWERS_TOKEN') in
 * routes.php). The portal has no database access to the testcenter.
 */
class PortalController extends Controller {
  protected static ?PortalDAO $_portalDAO = null;
  private const GEOGEBRA_FOLDER = 'GeoGebra';

  protected static function portalDAO(): PortalDAO {
    return self::$_portalDAO ??= new PortalDAO();
  }

  public static function getWorkspaces(Request $request, Response $response): Response {
    return $response->withJson(self::portalDAO()->getWorkspaceOverview());
  }

  /**
   * GET /workspace/{ws_id}/player-resource/{path} - what the portal's replay (Korrektur) loads into its iframes:
   * a Verona player of the workspace (by file name) or a file below Resource/GeoGebra/. Nothing else of the
   * workspace is reachable here. Read from the active storage (disk or object store). The path check works on the
   * string, as an object store has no realpath; on disk the resolved path must also stay inside its folder, so a
   * symlink cannot lead out of it.
   * Deliberately not `new Workspace()`, which would create a missing workspace folder.
   */
  public static function getPlayerResource(Request $request, Response $response, array $args): Response {
    $workspaceId = (int) $request->getAttribute('ws_id');
    $path = (string) ($args['path'] ?? '');
    $segments = explode('/', $path);
    $isPlainRelativePath = !str_contains($path, '\\')
      && !str_contains($path, "\0")
      && !array_intersect($segments, ['', '.', '..']);

    $logicalPath = null;
    if ($workspaceId > 0 && $isPlainRelativePath) {
      $isGeoGebraFile = $segments[0] === self::GEOGEBRA_FOLDER && count($segments) > 1;
      $isPlayer = count($segments) === 1 && self::portalDAO()->isPlayer($workspaceId, $path);
      $folder = $isGeoGebraFile ? 'Resource/' . self::GEOGEBRA_FOLDER : 'Resource';
      if (($isGeoGebraFile || $isPlayer) && self::isContainedOnDisk("ws_$workspaceId/$folder", "ws_$workspaceId/Resource/$path")) {
        $logicalPath = "ws_$workspaceId/Resource/$path";
      }
    }

    if ($logicalPath === null || !Storage::driver()->exists($logicalPath)) {
      throw new HttpNotFoundException($request, 'No player resource `' . htmlspecialchars($path) . "` in workspace $workspaceId");
    }

    // Players and GeoGebra files are versioned by name; the browser may reuse them across the replay's iframes.
    return FileResponse::streamResource(
      $response,
      Storage::driver()->getStream($logicalPath),
      null,
      FileExt::getMimeType($path),
      Storage::driver()->size($logicalPath)
    )
      ->withHeader('Cache-Control', 'private, max-age=3600');
  }

  /** Disk only: whether $logicalPath, symlinks resolved, lies below $logicalFolder. The object store has no symlinks. */
  private static function isContainedOnDisk(string $logicalFolder, string $logicalPath): bool {
    if (Storage::isObjectStore()) {
      return true;
    }
    $relativePath = substr($logicalPath, strlen($logicalFolder) + 1);
    return Folder::getContainedRealPath(DATA_DIR . "/$logicalFolder", $relativePath) !== null;
  }

  public static function getTestBookletIds(Request $request, Response $response): Response {
    $workspaceId = (int) $request->getAttribute('ws_id');
    return $response->withJson(self::portalDAO()->getTestBookletIds($workspaceId));
  }

  public static function getBookletUnits(Request $request, Response $response): Response {
    $workspaceId = (int) $request->getAttribute('ws_id');
    return $response->withJson(self::portalDAO()->getBookletUnits($workspaceId));
  }

  public static function getTesteeTokens(Request $request, Response $response): Response {
    $workspaceId = (int) $request->getAttribute('ws_id');
    $codes = RequestHelper::getRequiredField($request, 'codes');
    if (!is_array($codes)) {
      throw new HttpBadRequestException($request, '`codes` must be an array of strings');
    }
    return $response->withJson(self::portalDAO()->getTesteeTokens($workspaceId, $codes));
  }
}
