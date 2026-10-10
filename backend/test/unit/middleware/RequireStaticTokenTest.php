<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;

final class RequireStaticTokenTest extends TestCase {
  private RequestHandlerInterface $handler;

  function setUp(): void {
    require_once "test/unit/test-helper/RequestCreator.class.php";
    require_once "test/unit/test-helper/ResponseCreator.class.php";
    $_ENV['STATIC_TOKEN_UNDER_TEST'] = 'secret';
    $this->handler = new class implements RequestHandlerInterface {
      public function handle(ServerRequestInterface $request): ResponseInterface {
        return ResponseCreator::createEmpty()->withStatus(204);
      }
    };
  }

  function tearDown(): void {
    unset($_ENV['STATIC_TOKEN_UNDER_TEST']);
  }

  function test_passesWithMatchingBearerToken(): void {
    $request = RequestCreator::create('GET', '/portal/workspaces')->withHeader('Authorization', 'Bearer secret');
    $response = (new RequireStaticToken('STATIC_TOKEN_UNDER_TEST'))($request, $this->handler);
    $this->assertEquals(204, $response->getStatusCode());
  }

  function test_401WithoutAuthorizationHeader(): void {
    $this->expectException(HttpUnauthorizedException::class);
    (new RequireStaticToken('STATIC_TOKEN_UNDER_TEST'))(RequestCreator::create('GET', '/portal/workspaces'), $this->handler);
  }

  function test_403WithWrongToken(): void {
    $this->expectException(HttpForbiddenException::class);
    $request = RequestCreator::create('GET', '/portal/workspaces')->withHeader('Authorization', 'Bearer wrong');
    (new RequireStaticToken('STATIC_TOKEN_UNDER_TEST'))($request, $this->handler);
  }

  function test_403WhenTokenIsNotConfigured(): void {
    unset($_ENV['STATIC_TOKEN_UNDER_TEST']);
    $this->expectException(HttpForbiddenException::class);
    $request = RequestCreator::create('GET', '/portal/workspaces')->withHeader('Authorization', 'Bearer ');
    (new RequireStaticToken('STATIC_TOKEN_UNDER_TEST'))($request, $this->handler);
  }
}
