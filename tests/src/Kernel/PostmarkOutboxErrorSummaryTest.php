<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\postmark_webhooks\Controller\PostmarkWebhookController;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A subscriber error leaves no request data in the outbox or the log.
 *
 * Subscriber errors usually come from an HTTP client, and those messages quote
 * the request: the host, the query string, header values and mailboxes.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkOutboxErrorSummaryTest extends KernelTestBase {

  /**
   * Fragments that must never reach storage, Drush output or a log line.
   */
  private const FORBIDDEN = [
    'crm.invalid',
    'https://',
    '/hook',
    'token=',
    'SECRET-7Q',
    'private.person',
    'example.com',
    '%40',
    '@',
    'Bearer',
    'Basic',
    'eyJhbGciOi',
    'dXNlcjpwYXNz',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'postmark_webhooks',
    'postmark_integration_test',
  ];

  /**
   * Log records captured during the test.
   *
   * @var list<string>
   */
  public array $logged = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', [
      'postmark_events',
      'postmark_suppression',
      'postmark_intake_metrics',
      'postmark_integration_outbox',
    ]);
    $this->installConfig(['postmark_webhooks']);
    new Settings(['postmark_webhooks.webhook_secret' => 's3cr3t-webhook-pass'] + Settings::getAll());
    $this->config('postmark_webhooks.settings')->set('integration_events_enabled', TRUE)->save();

    $test = $this;
    $this->container->get('logger.factory')->addLogger(new class($test) implements LoggerInterface {
      use RfcLoggerTrait;

      public function __construct(private readonly PostmarkOutboxErrorSummaryTest $test) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        if (($context['channel'] ?? '') !== 'postmark_webhooks') {
          return;
        }
        $placeholders = array_filter($context, static fn ($key): bool => is_string($key) && str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY);
        $this->test->logged[] = strtr((string) $message, array_map('strval', $placeholders));
      }

    });
  }

  /**
   * An HTTP client error whose message quotes the whole request.
   */
  public static function httpUnauthorized(): \Throwable {
    return new RequestException(
      'Client error: `POST https://crm.invalid/hook?token=SECRET-7Q&email=private.person%40example.com` resulted in a `401 Unauthorized` response',
      new PsrRequest('POST', 'https://crm.invalid/hook?token=SECRET-7Q&email=private.person%40example.com'),
      new PsrResponse(401),
    );
  }

  /**
   * A plain exception carrying a mailbox and both kinds of header value.
   */
  public static function headersAndMailbox(): \Throwable {
    return new \RuntimeException('Rejected private.person@example.com with Authorization: Bearer eyJhbGciOi.payload.sig and Authorization: Basic dXNlcjpwYXNz at https://crm.invalid/hook?token=SECRET-7Q');
  }

  /**
   * A subscriber that wraps the HTTP error in its own exception.
   */
  public static function wrappedServerError(): \Throwable {
    $inner = new RequestException(
      'Server error: `POST https://crm.invalid/hook?token=SECRET-7Q` resulted in a `503 Service Unavailable` response',
      new PsrRequest('POST', 'https://crm.invalid/hook?token=SECRET-7Q'),
      new PsrResponse(503),
    );
    return new \LogicException('CRM push failed for private.person%40example.com: ' . $inner->getMessage(), 0, $inner);
  }

  /**
   * A connection failure: no response, so no status.
   */
  public static function connectionRefused(): \Throwable {
    return new ConnectException(
      'cURL error 7: Failed to connect to crm.invalid port 443 for https://crm.invalid/hook?token=SECRET-7Q',
      new PsrRequest('POST', 'https://crm.invalid/hook?token=SECRET-7Q'),
    );
  }

  /**
   * An anonymous class, whose name PHP builds from a file path.
   */
  public static function anonymousClass(): \Throwable {
    return new class('token=SECRET-7Q') extends \RuntimeException {};
  }

  /**
   * Each hostile error and the only text it may leave behind.
   *
   * @return array<string, array{0: string, 1: string}>
   *   Factory method name and the expected stored summary.
   */
  public static function hostileErrors(): array {
    return [
      'url, token, encoded mailbox, status' => [
        'httpUnauthorized',
        'class=RequestException; status=401; reason=http_client_error',
      ],
      'plain mailbox, bearer and basic values' => [
        'headersAndMailbox',
        'class=RuntimeException; status=none; reason=subscriber_error',
      ],
      'status found on a wrapped exception' => [
        'wrappedServerError',
        'class=LogicException; status=503; reason=http_server_error',
      ],
      'connection failure' => ['connectionRefused', 'class=ConnectException; status=none; reason=network'],
      'anonymous class name' => ['anonymousClass', 'class=anonymous; status=none; reason=subscriber_error'],
    ];
  }

  /**
   * Nothing from the request survives; the class and status do.
   *
   * @dataProvider hostileErrors
   */
  #[DataProvider('hostileErrors')]
  public function testSubscriberErrorLeavesNoRequestData(string $factory, string $expected): void {
    $this->container->get('state')->set('postmark_integration_test.throw_factory', self::class . '::' . $factory);
    $this->bounce('1');
    $result = $this->container->get('postmark_webhooks.integration_outbox')->dispatch();
    // One bounce enqueues the event and the suppression change.
    $this->assertGreaterThanOrEqual(1, $result['failed']);
    $this->assertSame($result['attempted'], $result['failed']);

    $all = $this->container->get('database')->select('postmark_integration_outbox', 'o')
      ->fields('o', ['last_error'])->execute()->fetchCol();
    $this->assertCount($result['failed'], $all);
    $stored = implode("\n", $all);
    foreach (self::FORBIDDEN as $fragment) {
      $this->assertStringNotContainsStringIgnoringCase($fragment, $stored, "Stored error leaks: $fragment");
    }
    foreach ($all as $error) {
      $this->assertSame($expected, $error);
    }
    foreach ($this->container->get('postmark_webhooks.integration_outbox')->inspect() as $row) {
      $this->assertSame($expected, $row['last_error']);
    }

    $this->assertNotSame([], $this->logged, 'The delivery failure was logged.');
    $log = implode("\n", $this->logged);
    $this->assertStringContainsString($expected, $log, 'The log line carries the same summary.');
    foreach (self::FORBIDDEN as $fragment) {
      $this->assertStringNotContainsStringIgnoringCase($fragment, $log, "Log line leaks: $fragment");
    }
  }

  /**
   * A stored-payload failure is named as such and is terminal.
   */
  public function testInvalidStoredPayloadHasItsOwnReason(): void {
    $this->bounce('2');
    $database = $this->container->get('database');
    $database->update('postmark_integration_outbox')->fields(['payload' => '{"token":"SECRET-7Q"'])->execute();
    $this->container->get('postmark_webhooks.integration_outbox')->dispatch();

    $row = $this->container->get('postmark_webhooks.integration_outbox')->inspect()[0];
    $this->assertSame('failed', $row['status']);
    $this->assertSame('class=InvalidArgumentException; status=none; reason=invalid_event', $row['last_error']);
  }

  /**
   * Rows written before the update are summarised when they are read.
   *
   * Between deploying the code and running the update, Drush must not print
   * the old text.
   */
  public function testInspectNeverReturnsLegacyText(): void {
    $this->insertRow('POST https://crm.invalid/hook?token=SECRET-7Q&email=private.person%40example.com resulted in a `401 Unauthorized` response');

    $error = $this->container->get('postmark_webhooks.integration_outbox')->inspect()[0]['last_error'];
    $this->assertSame('class=unknown; status=401; reason=legacy_redacted', $error);
  }

  /**
   * Update 10008 rewrites stored errors in bounded batches.
   */
  public function testUpdateRewritesStoredErrors(): void {
    $hostile = 'POST https://crm.invalid/hook?token=SECRET-7Q&email=private.person%40example.com resulted in a `401 Unauthorized` response';
    $this->insertRow($hostile);
    $this->insertRow('Rejected [redacted] with Authorization: Bearer eyJhbGciOi.payload.sig');
    $this->insertRow('');
    $this->insertRow('class=RequestException; status=502; reason=http_server_error');
    // A value shaped like the new format with a reason this module never
    // writes is not trusted.
    $this->insertRow('class=SECRET7Q; status=none; reason=made_up');
    for ($i = 0; $i < 300; $i++) {
      $this->insertRow($hostile);
    }

    $this->container->get('module_handler')->loadInclude('postmark_webhooks', 'install');
    $sandbox = [];
    $passes = 0;
    do {
      postmark_webhooks_update_10008($sandbox);
      $passes++;
      $this->assertLessThan(10, $passes, 'The update terminates.');
    } while (($sandbox['#finished'] ?? 1) < 1);
    $this->assertGreaterThan(1, $passes, 'More than 250 rows take more than one batch.');

    $errors = $this->container->get('database')->select('postmark_integration_outbox', 'o')
      ->fields('o', ['last_error'])->orderBy('oid')->execute()->fetchCol();
    $this->assertCount(305, $errors);
    $this->assertSame('class=unknown; status=401; reason=legacy_redacted', $errors[0]);
    $this->assertSame('class=unknown; status=none; reason=legacy_redacted', $errors[1]);
    $this->assertSame('', $errors[2]);
    $this->assertSame('class=RequestException; status=502; reason=http_server_error', $errors[3]);
    $this->assertSame('class=unknown; status=none; reason=legacy_redacted', $errors[4]);
    $this->assertSame(['class=unknown; status=401; reason=legacy_redacted'], array_values(array_unique(array_slice($errors, 5))));
    foreach (self::FORBIDDEN as $fragment) {
      $this->assertStringNotContainsStringIgnoringCase($fragment, implode("\n", $errors));
    }

    // Running it again changes nothing and finishes at once.
    $sandbox = [];
    postmark_webhooks_update_10008($sandbox);
  }

  /**
   * The update does nothing on a site without the outbox table.
   */
  public function testUpdateWithoutTheTable(): void {
    $this->container->get('database')->schema()->dropTable('postmark_integration_outbox');
    $this->container->get('module_handler')->loadInclude('postmark_webhooks', 'install');
    $sandbox = [];
    postmark_webhooks_update_10008($sandbox);
    $this->assertSame(1, $sandbox['#finished']);
  }

  /**
   * Inserts one outbox row with the given stored error.
   */
  private function insertRow(string $error): void {
    static $n = 0;
    $n++;
    $this->container->get('database')->insert('postmark_integration_outbox')->fields([
      'fingerprint' => hash('sha256', 'row-' . $n),
      'type' => 'suppression.changed',
      'version' => 1,
      'payload' => '{}',
      'status' => 'failed',
      'attempts' => 3,
      'available_at' => 10,
      'created' => 5,
      'delivered_at' => 0,
      'last_error' => $error,
    ])->execute();
  }

  /**
   * Posts one bounce webhook.
   */
  private function bounce(string $id): void {
    $body = json_encode([
      'RecordType' => 'Bounce',
      'Type' => 'HardBounce',
      'Email' => 'recipient0@example.com',
      'ID' => $id,
      'ServerID' => 1,
      'MessageStream' => 'outbound',
      'MessageID' => 'msg-' . $id,
      'BouncedAt' => '2020-01-01T12:00:00Z',
    ], JSON_THROW_ON_ERROR);
    $request = Request::create('/api/webhooks/postmark', 'POST', [], [], [], [], $body);
    $request->headers->set('Authorization', 'Basic ' . base64_encode('postmark:s3cr3t-webhook-pass'));
    $response = PostmarkWebhookController::create($this->container)->receive($request);
    $this->assertSame(200, $response->getStatusCode());
  }

}
