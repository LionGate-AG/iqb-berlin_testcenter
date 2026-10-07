<?php
declare(strict_types=1);

use Slim\Http\Response;
use Slim\Psr7\Stream;

class FileResponse {
  /**
   * Streams the file at $absolutePath as the body of $response. The caller must ensure
   * the path is legitimate and contained (e.g. via Workspace::getFilePath).
   * Passing $downloadName adds a Content-Disposition attachment header.
   *
   * Cache-Control: private keeps shared/edge caches (e.g. a CDN) from storing these
   * authenticated responses and serving them without re-checking authorization; the
   * browser may still cache them per user.
   */
  public static function stream(
    Response $response,
    string   $absolutePath,
    ?string  $downloadName = null,
    ?string  $contentType = null
  ): Response {
    $size = filesize($absolutePath);

    return self::streamResource(
      $response,
      fopen($absolutePath, 'rb'),
      $downloadName,
      $contentType ?? FileExt::getMimeType($absolutePath),
      ($size === false) ? null : $size
    );
  }

  /**
   * Streams an already opened resource -- e.g. an object-store download, which has no local path -- with
   * the same headers as stream(). Content-Length is only sent when $size is known.
   *
   * @param resource $handle
   */
  public static function streamResource(
    Response $response,
    $handle,
    ?string  $downloadName = null,
    ?string  $contentType = null,
    ?int     $size = null
  ): Response {
    $response = $response
      ->withHeader('Content-Type', $contentType ?? 'application/octet-stream')
      ->withHeader('Cache-Control', 'private')
      ->withBody(new Stream($handle));

    if ($size !== null) {
      $response = $response->withHeader('Content-Length', (string) $size);
    }

    if ($downloadName !== null) {
      $response = $response->withHeader('Content-Disposition', 'attachment; filename="' . $downloadName . '"');
    }

    return $response;
  }

}
