<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Http\ServerRequest as Request;

/**
 * Guards the server-to-server endpoints of the TBA-Portal integration with a static bearer token read
 * from the environment variable `$envName` (ANSWERS_TOKEN for reads, PORTAL_SESSION_TOKEN for sessions).
 * 401 without Authorization header; 403 if the token is not configured or does not match (timing-safe).
 */
class RequireStaticToken {
  public function __construct(private readonly string $envName) {}

  function __invoke(Request $request, RequestHandler $handler) {
    if ($request->isOptions()) {
      return $handler->handle($request);
    }

    $authHeaders = $request->getHeader('Authorization');
    if (!$authHeaders) {
      throw new HttpUnauthorizedException($request, 'Authorization header missing');
    }

    $token = $_ENV[$this->envName] ?? getenv($this->envName);
    if (!is_string($token) || $token === '') {
      throw new HttpForbiddenException($request, 'Static token not configured');
    }

    foreach ($authHeaders as $headerValue) {
      if (hash_equals('Bearer ' . $token, $headerValue)) {
        return $handler->handle($request);
      }
    }

    throw new HttpForbiddenException($request, 'Invalid static token');
  }
}
