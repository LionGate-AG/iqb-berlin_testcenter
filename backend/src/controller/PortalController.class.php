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
   * workspace is reachable here. Deliberately not `new Workspace()`, which would create a missing workspace folder.
   */
  public static function getPlayerResource(Request $request, Response $response, array $args): Response {
    $workspaceId = (int) $request->getAttribute('ws_id');
    $path = (string) ($args['path'] ?? '');
    $segments = explode('/', $path);
    $isPlainRelativePath = !str_contains($path, '\\')
      && !str_contains($path, "\0")
      && !array_intersect($segments, ['', '.', '..']);

    $filePath = null;
    if ($workspaceId > 0 && $isPlainRelativePath) {
      $resourceDir = DATA_DIR . "/ws_$workspaceId/Resource";
      if ($segments[0] === self::GEOGEBRA_FOLDER && count($segments) > 1) {
        $filePath = Folder::getContainedRealPath("$resourceDir/" . self::GEOGEBRA_FOLDER, implode('/', array_slice($segments, 1)));
      } elseif (count($segments) === 1 && self::portalDAO()->isPlayer($workspaceId, $path)) {
        $filePath = Folder::getContainedRealPath($resourceDir, $path);
      }
    }

    if ($filePath === null || !is_file($filePath)) {
      throw new HttpNotFoundException($request, 'No player resource `' . htmlspecialchars($path) . "` in workspace $workspaceId");
    }

    // Players and GeoGebra files are versioned by name; the browser may reuse them across the replay's iframes.
    return FileResponse::stream($response, $filePath)
      ->withHeader('Cache-Control', 'private, max-age=3600');
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
