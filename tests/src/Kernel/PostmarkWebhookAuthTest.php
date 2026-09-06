<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\postmark_webhooks\Controller\PostmarkWebhookController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for the Postmark webhook HTTP Basic Auth controller.
 *
 * Verifies that PostmarkWebhookController::receive() authenticates via Basic
 * Auth (secret = password, sent in the Authorization header — never in the URL)
 * and only records an event for an authenticated, well-formed request.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkWebhookAuthTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['postmark_webhooks'];

  private const SECRET = 's3cr3t-webhook-pass';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', ['postmark_events']);
    $this->installConfig(['postmark_webhooks']);
  }

  /**
   * Sets (or clears) the configured webhook secret.
   */
  private function setSecret(string $secret): void {
    new Settings(['postmark_webhooks.webhook_secret' => $secret] + Settings::getAll());
  }

  /**
   * Builds a POST request, optionally with a Basic Auth password.
   */
  private function request(?string $password, string $body = '{"RecordType":"Bounce","Type":"HardBounce","Email":"x@example.com","MessageID":"msg-1"}'): Request {
    $request = Request::create('/api/webhooks/postmark', 'POST', [], [], [], [], $body);
    if ($password !== NULL) {
      $request->headers->set('Authorization', 'Basic ' . base64_encode('postmark:' . $password));
    }
    return $request;
  }

  /**
   * Invokes the controller against the current container.
   */
  private function receive(Request $request) {
    $controller = PostmarkWebhookController::create($this->container);
    return $controller->receive($request);
  }

  /**
   * Counts rows in postmark_events.
   */
  private function eventCount(): int {
    return (int) \Drupal::database()->select('postmark_events')->countQuery()->execute()->fetchField();
  }

  /**
   * A request with the correct secret is accepted and records the event.
   */
  public function testValidSecretRecordsEvent(): void {
    $this->setSecret(self::SECRET);
    $response = $this->receive($this->request(self::SECRET));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(1, $this->eventCount(), 'A valid webhook records exactly one event.');
  }

  /**
   * A request with no Authorization header is rejected with 401.
   */
  public function testMissingAuthRejected(): void {
    $this->setSecret(self::SECRET);
    $response = $this->receive($this->request(NULL));
    $this->assertSame(401, $response->getStatusCode());
    $this->assertTrue($response->headers->has('WWW-Authenticate'));
    $this->assertSame(0, $this->eventCount(), 'An unauthenticated request records nothing.');
  }

  /**
   * A request with the wrong secret is rejected with 401.
   */
  public function testWrongSecretRejected(): void {
    $this->setSecret(self::SECRET);
    $response = $this->receive($this->request('wrong-password'));
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame(0, $this->eventCount());
  }

  /**
   * When no secret is configured the endpoint refuses all posts (503).
   */
  public function testUnconfiguredSecretRefuses(): void {
    // No settings.php secret has been configured.
    $response = $this->receive($this->request(self::SECRET));
    $this->assertSame(503, $response->getStatusCode());
    $this->assertSame(0, $this->eventCount());
  }

  /**
   * An explicitly empty settings secret cannot fall back to exported config.
   */
  public function testEmptySecretIgnoresConfig(): void {
    $this->setSecret('');
    $name = 'postmark_webhooks.settings';
    $storage = $this->container->get('config.storage');
    $storage->write($name, ['webhook_secret' => self::SECRET] + $storage->read($name));
    $this->container->get('config.factory')->reset($name);
    $response = $this->receive($this->request(self::SECRET));
    $this->assertSame(503, $response->getStatusCode());
    $this->assertSame(0, $this->eventCount());
  }

  /**
   * Installation does not create a configuration secret.
   */
  public function testNoSecretInInstallConfig(): void {
    $this->assertArrayNotHasKey('webhook_secret', $this->config('postmark_webhooks.settings')->getRawData());
  }

  /**
   * An authenticated request with a non-JSON body is a 400 and records nothing.
   */
  public function testAuthenticatedButMalformedBodyRejected(): void {
    $this->setSecret(self::SECRET);
    $response = $this->receive($this->request(self::SECRET, 'not-json'));
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame(0, $this->eventCount());
  }

  /**
   * A repeated MessageID is acknowledged without a second row.
   */
  public function testDuplicateMessageIdIsIdempotent(): void {
    $this->setSecret(self::SECRET);
    $body = '{"RecordType":"Bounce","Type":"HardBounce","Email":"x@example.com","MessageID":"dup-1"}';
    $this->assertSame(200, $this->receive($this->request(self::SECRET, $body))->getStatusCode());
    $this->assertSame(200, $this->receive($this->request(self::SECRET, $body))->getStatusCode());
    $this->assertSame(1, $this->eventCount());
  }

  /**
   * The raw webhook body is not persisted.
   */
  public function testPayloadIsNotStored(): void {
    $this->setSecret(self::SECRET);
    $this->receive($this->request(self::SECRET));
    $payload = \Drupal::database()->select('postmark_events', 'pe')
      ->fields('pe', ['payload'])
      ->range(0, 1)
      ->execute()
      ->fetchField();
    $this->assertTrue($payload === NULL || $payload === '', 'The raw Postmark body must not be stored.');
  }

}
