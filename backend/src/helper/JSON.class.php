<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);
// TODO unit test
// TODO throw other Exceptions, so we don't get a 500 on malformed json

class JSON {
  static function decode(?string $json, bool $assoc = false) {
    if (!$json) {
      return $assoc ? [] : new stdClass();
    }

    $decoded = json_decode($json, $assoc, 512, JSON_UNESCAPED_UNICODE);

    switch (json_last_error()) {
      case JSON_ERROR_NONE:
        return $decoded;
      case JSON_ERROR_DEPTH:
        throw new Exception('JSON Error: Maximum stack depth exceeded');
      case JSON_ERROR_STATE_MISMATCH:
        throw new Exception('JSON Error: Underflow or the modes mismatch');
      case JSON_ERROR_CTRL_CHAR:
        throw new Exception('JSON Error: Unexpected control character found');
      case JSON_ERROR_SYNTAX:
        // DIAGNOSTIC (2026-09-03) -- remove once the PATCH /state defect is found.
        //
        // 62,384 of these fired in one run, 100% on `PATCH /test/{id}/state`, at a
        // steady ~1,600/min (~2.6% of PATCHes) even while the system was healthy --
        // zero shed 503s, zero DB timeouts and zero pod churn in that window. Ruled
        // out by measurement: the `laststate` decode (0 of 90,000 rows invalid), the
        // token middleware (getToken performs no decode), the `logins` JSON columns
        // (not on this path), transport truncation (zero nginx body errors), a bad
        // pod (uniform 320-362 across all 40), a bad client (2,712 distinct tests,
        // 8 source IPs) and ProxySQL shunning (began 13:44; errors began 13:40).
        //
        // Only the request body remains, and no log can show its bytes -- hence this.
        // Costs nothing on the success path: it runs only after a decode has failed.
        //
        // How to read the output:
        //   len=0                     -> body empty, or the stream was already consumed
        //   len < declared            -> TRUNCATED; `tail` shows where it stopped
        //   len == declared, valid    -> the fault is NOT this decode; look at the
        //                                other JSON::decode on the same request
        //   garbage / wrong shape     -> misframed request, e.g. connection desync
        error_log(sprintf(
          'JSON_SYNTAX_DIAG method=%s uri=%s len=%d declared=%s head=%s tail=%s',
          $_SERVER['REQUEST_METHOD'] ?? '-',
          $_SERVER['REQUEST_URI'] ?? '-',
          strlen($json),
          $_SERVER['CONTENT_LENGTH'] ?? '-',
          json_encode(substr($json, 0, 160)),
          json_encode(substr($json, -60))
        ));
        throw new Exception('JSON Error: Syntax error, malformed JSON');
      case JSON_ERROR_UTF8:
        throw new Exception('JSON Error: Malformed UTF-8 characters, possibly incorrectly encoded');
      case JSON_ERROR_RECURSION:
        throw new Exception('JSON Error: One or more recursive references in the value to be encoded');
      case JSON_ERROR_INF_OR_NAN:
        throw new Exception('JSON Error: One or more NAN or INF values in the value to be encoded');
      case JSON_ERROR_UNSUPPORTED_TYPE:
        throw new Exception('JSON Error: A value of a type that cannot be encoded was given');
      case JSON_ERROR_INVALID_PROPERTY_NAME:
        throw new Exception('JSON Error: A property name that cannot be encoded was given');
      case JSON_ERROR_UTF16:
        throw new Exception('JSON Error: Malformed UTF-16 characters, possibly incorrectly encoded');
      default:
        throw new Exception('JSON Error: Unknown error');
    }
  }
}
