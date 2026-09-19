<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Integration;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * Describes a delivery failure without carrying any part of the request.
 *
 * Subscriber errors usually come from an HTTP client, and those messages quote
 * the request: host, path, query string, header values, mailboxes. Removing
 * the dangerous parts from free text is a guess about every shape a message
 * can take, and the previous guess missed a URL-encoded mailbox. So the
 * exception message is never read. The summary is built from three values the
 * request cannot influence: the exception class, an HTTP status, and a reason
 * from a fixed list.
 *
 * @internal
 */
final class DeliveryErrorSummary {

  /**
   * Every reason this class can write.
   */
  public const REASONS = [
    'invalid_event',
    'network',
    'http_client_error',
    'http_server_error',
    'http_other',
    'http_client',
    'subscriber_error',
    'legacy_redacted',
  ];

  /**
   * How far down the getPrevious() chain a status is looked for.
   */
  private const MAX_DEPTH = 5;

  /**
   * The exact shape of a summary. Nothing else is trusted as one.
   */
  private const FORMAT = '/^class=([A-Za-z0-9_]{1,64}); status=(none|[1-5][0-9]{2}); reason=([a-z_]{1,32})\z/';

  /**
   * Builds the summary for a caught delivery failure.
   */
  public static function fromThrowable(\Throwable $exception): string {
    $status = NULL;
    $network = FALSE;
    $http_client = FALSE;
    $current = $exception;
    for ($depth = 0; $current !== NULL && $depth < self::MAX_DEPTH; $depth++) {
      $status ??= self::status($current);
      $network = $network || $current instanceof NetworkExceptionInterface;
      $http_client = $http_client || $current instanceof ClientExceptionInterface;
      $current = $current->getPrevious();
    }

    $reason = match (TRUE) {
      $status !== NULL && $status >= 400 && $status < 500 => 'http_client_error',
      $status !== NULL && $status >= 500 => 'http_server_error',
      $status !== NULL => 'http_other',
      $network => 'network',
      $http_client => 'http_client',
      // The outbox treats this type as a payload it cannot decode.
      $exception instanceof \InvalidArgumentException => 'invalid_event',
      default => 'subscriber_error',
    };
    return self::format(self::className($exception), $status, $reason);
  }

  /**
   * Rewrites text stored by an older release.
   *
   * A value already in the summary format, with a reason this class writes, is
   * kept. Anything else is free text from an exception message and is
   * replaced. Only an HTTP status in the exact wording Guzzle uses is carried
   * over: a number from 100 to 599 cannot hold request data.
   */
  public static function fromStoredText(string $text): string {
    if ($text === '') {
      return '';
    }
    if (preg_match(self::FORMAT, $text, $parts) === 1 && in_array($parts[3], self::REASONS, TRUE)) {
      return $text;
    }
    $status = preg_match('/resulted in a `([1-5][0-9]{2}) /', $text, $found) === 1 ? (int) $found[1] : NULL;
    return self::format('unknown', $status, 'legacy_redacted');
  }

  /**
   * An HTTP status the exception exposes, or NULL.
   *
   * Duck-typed so no HTTP client class is named: Guzzle's RequestException
   * has getResponse(), Symfony's HTTP exceptions have getStatusCode().
   */
  private static function status(\Throwable $exception): ?int {
    try {
      $code = NULL;
      if (method_exists($exception, 'getResponse')) {
        $response = $exception->getResponse();
        if (is_object($response) && method_exists($response, 'getStatusCode')) {
          $code = $response->getStatusCode();
        }
      }
      // Not elseif: a client exception with no response may still know it.
      if (!is_int($code) && method_exists($exception, 'getStatusCode')) {
        $code = $exception->getStatusCode();
      }
    }
    catch (\Throwable) {
      return NULL;
    }
    return is_int($code) && $code >= 100 && $code <= 599 ? $code : NULL;
  }

  /**
   * The short class name, reduced to characters that cannot carry data.
   */
  private static function className(\Throwable $exception): string {
    $class = get_class($exception);
    // PHP builds an anonymous class name from the file path that declares it.
    if (str_contains($class, '@anonymous')) {
      return 'anonymous';
    }
    $position = strrpos($class, '\\');
    $short = $position === FALSE ? $class : substr($class, $position + 1);
    $short = substr((string) preg_replace('/[^A-Za-z0-9_]/', '', $short), 0, 64);
    return $short === '' ? 'unknown' : $short;
  }

  /**
   * Assembles the stored text.
   */
  private static function format(string $class, ?int $status, string $reason): string {
    return sprintf('class=%s; status=%s; reason=%s', $class, $status === NULL ? 'none' : (string) $status, $reason);
  }

}
