<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Factory\AppFactory;

/**
 * Proves that routes.php puts every TBA-Portal route behind the right static token: the read endpoints behind
 * ANSWERS_TOKEN, the session endpoints (no password, no challenge) behind PORTAL_SESSION_TOKEN. Loads the real
 * routes.php into a Slim app like index.php does and replaces every route handler by a stub, so only the
 * middleware runs (no database, no files).
 */
final class PortalRoutesTest extends TestCase {
  private const TOKENS = ['ANSWERS_TOKEN' => 'answers-token', 'PORTAL_SESSION_TOKEN' => 'portal-session-token'];

  /** [method, uri, environment variable of the token that must open the route] */
  private const ROUTES = [
    ['POST', '/workspace/1/answers', 'ANSWERS_TOKEN'],
    ['POST', '/workspace/1/booklet-files', 'ANSWERS_TOKEN'],
    ['GET', '/workspace/1/test-ids', 'ANSWERS_TOKEN'],
    ['GET', '/workspace/1/test-booklet-ids', 'ANSWERS_TOKEN'],
    ['GET', '/workspace/1/booklet-units', 'ANSWERS_TOKEN'],
    ['POST', '/workspace/1/testee-tokens', 'ANSWERS_TOKEN'],
    ['GET', '/workspace/1/player-resource/verona-player-simple-6.0.html', 'ANSWERS_TOKEN'],
    ['GET', '/workspace/1/player-resource/GeoGebra/js/deployggb.js', 'ANSWERS_TOKEN'],
    ['GET', '/portal/workspaces', 'ANSWERS_TOKEN'],
    ['PUT', '/portal/session/login', 'PORTAL_SESSION_TOKEN'],
    ['PUT', '/portal/session/person', 'PORTAL_SESSION_TOKEN'],
  ];

  private App $app;

  function setUp(): void {
    require_once "test/unit/test-helper/RequestCreator.class.php";
    foreach (self::TOKENS as $envName => $token) {
      $_ENV[$envName] = $token;
    }

    global $app;
    AppFactory::setContainer(new Container());
    $app = AppFactory::create();
    $app->addRoutingMiddleware();
    require ROOT_DIR . '/backend/routes.php';
    foreach ($app->getRouteCollector()->getRoutes() as $route) {
      $route->setCallable(fn($request, $response) => $response->withStatus(204));
    }
    $this->app = $app;
  }

  function tearDown(): void {
    foreach (array_keys(self::TOKENS) as $envName) {
      unset($_ENV[$envName]);
    }
  }

  private function handle(string $method, string $uri, ?string $token): ResponseInterface {
    $request = RequestCreator::create($method, $uri);
    if ($token !== null) {
      $request = $request->withHeader('Authorization', "Bearer $token");
    }
    return $this->app->handle($request);
  }

  private static function otherEnvName(string $envName): string {
    return $envName === 'ANSWERS_TOKEN' ? 'PORTAL_SESSION_TOKEN' : 'ANSWERS_TOKEN';
  }

  function test_theMatchingTokenOpensTheRoute(): void {
    foreach (self::ROUTES as [$method, $uri, $envName]) {
      $this->assertEquals(204, $this->handle($method, $uri, self::TOKENS[$envName])->getStatusCode(), "$method $uri");
    }
  }

  function test_401WithoutAuthorizationHeader(): void {
    foreach (self::ROUTES as [$method, $uri]) {
      try {
        $this->handle($method, $uri, null);
        $this->fail("$method $uri must require a token");
      } catch (HttpUnauthorizedException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  function test_403WithTheOtherPortalToken(): void {
    foreach (self::ROUTES as [$method, $uri, $envName]) {
      $otherEnvName = self::otherEnvName($envName);
      try {
        $this->handle($method, $uri, self::TOKENS[$otherEnvName]);
        $this->fail("$method $uri must reject the $otherEnvName token");
      } catch (HttpForbiddenException) {
        $this->addToAssertionCount(1);
      }
    }
  }
}
