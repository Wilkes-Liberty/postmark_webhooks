<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Unit;

use Drupal\postmark_webhooks\Integration\DeliveryErrorSummary;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The summary holds a class, a status and a fixed reason, and nothing else.
 *
 * @group postmark_webhooks
 * @coversDefaultClass \Drupal\postmark_webhooks\Integration\DeliveryErrorSummary
 */
class DeliveryErrorSummaryTest extends UnitTestCase {

  /**
   * The only shape a summary may have.
   */
  private const SHAPE = '/^class=[A-Za-z0-9_]{1,64}; status=(none|[1-5][0-9]{2}); reason=[a-z_]{1,32}\z/';

  /**
   * An exception exposing the given value as its response status.
   */
  private function withStatus(mixed $status): \Throwable {
    return new class($status) extends \RuntimeException {

      public function __construct(private readonly mixed $status) {
        parent::__construct('https://crm.invalid/hook?token=SECRET-7Q');
      }

      /**
       * Mimics an HTTP client exception without naming one.
       */
      public function getResponse(): object {
        return new class($this->status) {

          public function __construct(private readonly mixed $status) {}

          /**
           * The status, of whatever type the test handed over.
           */
          public function getStatusCode(): mixed {
            return $this->status;
          }

        };
      }

    };
  }

  /**
   * Only an integer from 100 to 599 is accepted as a status.
   *
   * @covers ::fromThrowable
   */
  public function testOnlyRealStatusCodesAreKept(): void {
    $this->assertSame('class=anonymous; status=404; reason=http_client_error', DeliveryErrorSummary::fromThrowable($this->withStatus(404)));
    $this->assertSame('class=anonymous; status=302; reason=http_other', DeliveryErrorSummary::fromThrowable($this->withStatus(302)));
    foreach ([99, 600, 0, -1, '401', 'token=SECRET-7Q', NULL, 4.01, ['401']] as $bad) {
      $this->assertSame('class=anonymous; status=none; reason=subscriber_error', DeliveryErrorSummary::fromThrowable($this->withStatus($bad)));
    }
  }

  /**
   * A response accessor that throws does not break failure accounting.
   *
   * @covers ::fromThrowable
   */
  public function testThrowingAccessorIsContained(): void {
    $exception = new class('boom') extends \RuntimeException {

      /**
       * Fails the way a half-built client exception might.
       */
      public function getResponse(): never {
        throw new \LogicException('https://crm.invalid/hook?token=SECRET-7Q');
      }

    };
    $this->assertSame('class=anonymous; status=none; reason=subscriber_error', DeliveryErrorSummary::fromThrowable($exception));
  }

  /**
   * A Symfony HTTP exception exposes its status directly.
   *
   * @covers ::fromThrowable
   */
  public function testSymfonyHttpExceptionStatus(): void {
    $this->assertSame('class=HttpException; status=502; reason=http_server_error', DeliveryErrorSummary::fromThrowable(new HttpException(502, 'token=SECRET-7Q')));
  }

  /**
   * An empty response does not hide a status the exception itself knows.
   *
   * @covers ::fromThrowable
   */
  public function testStatusIsReadWhenTheResponseIsEmpty(): void {
    $exception = new class('token=SECRET-7Q') extends \RuntimeException {

      /**
       * A client exception that never received a response.
       */
      public function getResponse(): ?object {
        return NULL;
      }

      /**
       * The status it recorded some other way.
       */
      public function getStatusCode(): int {
        return 429;
      }

    };
    $this->assertSame('class=anonymous; status=429; reason=http_client_error', DeliveryErrorSummary::fromThrowable($exception));
  }

  /**
   * A status buried deeper than the search depth is not used.
   *
   * @covers ::fromThrowable
   */
  public function testTheChainSearchIsBounded(): void {
    $exception = new HttpException(503);
    for ($i = 0; $i < 5; $i++) {
      $exception = new \RuntimeException('wrap', 0, $exception);
    }
    $this->assertSame('class=RuntimeException; status=none; reason=subscriber_error', DeliveryErrorSummary::fromThrowable($exception));

    $near = new \RuntimeException('wrap', 0, new HttpException(503));
    $this->assertSame('class=RuntimeException; status=503; reason=http_server_error', DeliveryErrorSummary::fromThrowable($near));
  }

  /**
   * Whatever is thrown, the result has the fixed shape and a known reason.
   *
   * @covers ::fromThrowable
   */
  public function testEveryResultHasTheFixedShape(): void {
    $thrown = [
      new \Exception(str_repeat('https://crm.invalid/?token=SECRET-7Q ', 40)),
      new \InvalidArgumentException('private.person@example.com'),
      new \Error("line\nbreak; status=200; reason=network"),
      $this->withStatus(401),
    ];
    foreach ($thrown as $exception) {
      $summary = DeliveryErrorSummary::fromThrowable($exception);
      $this->assertMatchesRegularExpression(self::SHAPE, $summary);
      $this->assertLessThanOrEqual(255, strlen($summary), 'Fits the last_error column.');
      $this->assertContains(substr($summary, strrpos($summary, '=') + 1), DeliveryErrorSummary::REASONS);
    }
  }

  /**
   * Stored text is kept only when it is a summary this class could write.
   *
   * @covers ::fromStoredText
   */
  public function testStoredTextIsRewrittenUnlessAlreadySummary(): void {
    $this->assertSame('', DeliveryErrorSummary::fromStoredText(''));

    $kept = 'class=RequestException; status=401; reason=http_client_error';
    $this->assertSame($kept, DeliveryErrorSummary::fromStoredText($kept));
    $this->assertSame($kept, DeliveryErrorSummary::fromStoredText(DeliveryErrorSummary::fromStoredText($kept)));

    $legacy = 'class=unknown; status=none; reason=legacy_redacted';
    $rewritten = [
      'POST https://crm.invalid/hook?token=SECRET-7Q&email=private.person%40example.com failed',
      'Rejected [redacted] with Authorization: Bearer eyJhbGciOi.payload.sig',
      'class=X; status=none; reason=made_up',
      "class=X; status=none; reason=network\ntoken=SECRET-7Q",
      'class=X; status=none; reason=network token=SECRET-7Q',
      ' class=X; status=none; reason=network',
      // A trailing newline is not part of the format.
      "class=X; status=none; reason=network\n",
      // A three-digit number outside Guzzle's exact wording is not a status.
      'token 401 for https://crm.invalid',
      'resulted in a `999 Nope` response',
    ];
    foreach ($rewritten as $text) {
      $this->assertSame($legacy, DeliveryErrorSummary::fromStoredText($text), $text);
    }
    $this->assertSame('class=unknown; status=500; reason=legacy_redacted', DeliveryErrorSummary::fromStoredText('`POST https://crm.invalid/hook` resulted in a `500 Internal Server Error` response'));
    $this->assertSame($legacy, DeliveryErrorSummary::fromStoredText(DeliveryErrorSummary::fromStoredText($rewritten[0])), 'Idempotent.');
  }

}
