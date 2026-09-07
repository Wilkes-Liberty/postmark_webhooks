<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\postmark_webhooks\Controller\PostmarkWebhookController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Source-profile credentials authenticate then bind intake to that profile.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkSourceProfileAuthTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['postmark_webhooks'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', [
      'postmark_events',
      'postmark_suppression',
      'postmark_intake_metrics',
    ]);
    $this->installConfig(['postmark_webhooks']);
  }

  /**
   * Posts one bounce for a server and stream.
   */
  private function bounce(string $password, string $server, string $stream, string $id): int {
    $body = json_encode([
      'RecordType' => 'Bounce',
      'Type' => 'HardBounce',
      'Email' => $id . '@example.com',
      'ID' => $id,
      'ServerID' => (int) $server,
      'MessageStream' => $stream,
      'MessageID' => 'msg-' . $id,
    ], JSON_THROW_ON_ERROR);
    $request = Request::create('/api/webhooks/postmark', 'POST', [], [], [], [], $body);
    $request->headers->set('Authorization', 'Basic ' . base64_encode('postmark:' . $password));
    return PostmarkWebhookController::create($this->container)->receive($request)->getStatusCode();
  }

  /**
   * Event row count.
   */
  private function events(): int {
    return (int) $this->container->get('database')->select('postmark_events')->countQuery()->execute()->fetchField();
  }

  /**
   * Two named profiles with independent secrets and source bindings.
   */
  private function twoProfiles(): array {
    return [
      'marketing' => [
        'secret' => 'marketing-secret-test-only',
        'previous' => [
          'secret' => 'marketing-previous-test-only',
          'expires' => 200,
        ],
        'sources' => [
          ['server_id' => '23', 'message_stream' => 'outbound'],
        ],
      ],
      'transactional' => [
        'secret' => 'transactional-secret-test-only',
        'sources' => [
          ['server_id' => '99', 'message_stream' => 'outbound'],
        ],
      ],
    ];
  }

  /**
   * A profile secret stores only that profile's sources.
   */
  public function testProfileSecretAcceptsBoundSource(): void {
    new Settings([
      'postmark_webhooks.source_profiles' => $this->twoProfiles(),
    ] + Settings::getAll());
    $this->assertSame(200, $this->bounce('marketing-secret-test-only', '23', 'outbound', '1'));
    $this->assertSame(1, $this->events());
  }

  /**
   * A profile secret cannot store another profile's source.
   */
  public function testCrossProfilePayloadForbidden(): void {
    new Settings([
      'postmark_webhooks.source_profiles' => $this->twoProfiles(),
    ] + Settings::getAll());
    $this->assertSame(403, $this->bounce('marketing-secret-test-only', '99', 'outbound', '2'));
    $this->assertSame(0, $this->events());
  }

  /**
   * Source-less payloads are rejected after a profile authenticates.
   */
  public function testSourcelessPayloadForbidden(): void {
    new Settings([
      'postmark_webhooks.source_profiles' => $this->twoProfiles(),
    ] + Settings::getAll());
    $body = json_encode([
      'RecordType' => 'Bounce',
      'Type' => 'HardBounce',
      'Email' => 'none@example.com',
      'ID' => '3',
      'MessageID' => 'msg-3',
    ], JSON_THROW_ON_ERROR);
    $request = Request::create('/api/webhooks/postmark', 'POST', [], [], [], [], $body);
    $request->headers->set('Authorization', 'Basic ' . base64_encode('postmark:marketing-secret-test-only'));
    $status = PostmarkWebhookController::create($this->container)->receive($request)->getStatusCode();
    $this->assertSame(403, $status);
    $this->assertSame(0, $this->events());
  }

  /**
   * Independent previous secrets expire per profile.
   */
  public function testProfilePreviousRotation(): void {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(100);
    $now = 199;
    $time->method('getCurrentTime')->willReturnCallback(static function () use (&$now): int {
      return $now;
    });
    $this->container->set('datetime.time', $time);
    new Settings([
      'postmark_webhooks.source_profiles' => $this->twoProfiles(),
    ] + Settings::getAll());
    $this->assertSame(200, $this->bounce('marketing-previous-test-only', '23', 'outbound', '4'));
    $now = 200;
    $this->assertSame(401, $this->bounce('marketing-previous-test-only', '23', 'outbound', '5'));
    $this->assertSame(200, $this->bounce('marketing-secret-test-only', '23', 'outbound', '6'));
    $this->assertSame(2, $this->events());
  }

  /**
   * A revoked profile's credential is rejected immediately.
   */
  public function testRevokedProfileRejected(): void {
    $profiles = $this->twoProfiles();
    $profiles['marketing']['revoked'] = TRUE;
    new Settings([
      'postmark_webhooks.source_profiles' => $profiles,
    ] + Settings::getAll());
    $this->assertSame(401, $this->bounce('marketing-secret-test-only', '23', 'outbound', '7'));
    $this->assertSame(200, $this->bounce('transactional-secret-test-only', '99', 'outbound', '8'));
    $this->assertSame(1, $this->events());
  }

  /**
   * Shared-secret intake still works when profiles are not configured.
   */
  public function testLegacySharedSecretUnchanged(): void {
    new Settings([
      'postmark_webhooks.webhook_secret' => 'shared-secret-test-only',
    ] + Settings::getAll());
    $this->assertSame(200, $this->bounce('shared-secret-test-only', '23', 'outbound', '9'));
    $this->assertSame(1, $this->events());
  }

  /**
   * Usable profiles disable the shared secret so it cannot cross bindings.
   */
  public function testSharedSecretIgnoredWhileProfilesUsable(): void {
    new Settings([
      'postmark_webhooks.webhook_secret' => 'shared-secret-test-only',
      'postmark_webhooks.source_profiles' => $this->twoProfiles(),
    ] + Settings::getAll());
    $this->assertSame(401, $this->bounce('shared-secret-test-only', '23', 'outbound', '10'));
    $this->assertSame(0, $this->events());
  }

  /**
   * After every profile is revoked the shared secret remains the fallback.
   */
  public function testSharedSecretAfterAllProfilesRevoked(): void {
    $profiles = $this->twoProfiles();
    $profiles['marketing']['revoked'] = TRUE;
    $profiles['transactional']['revoked'] = TRUE;
    new Settings([
      'postmark_webhooks.webhook_secret' => 'shared-secret-test-only',
      'postmark_webhooks.source_profiles' => $profiles,
    ] + Settings::getAll());
    $this->assertSame(200, $this->bounce('shared-secret-test-only', '23', 'outbound', '11'));
    $this->assertSame(1, $this->events());
  }

  /**
   * An empty previous secret is ignored and does not 503 the profile.
   */
  public function testEmptyPreviousSecretIsOmitted(): void {
    $profiles = $this->twoProfiles();
    $profiles['marketing']['previous'] = [
      'secret' => '',
      'expires' => 200,
    ];
    new Settings([
      'postmark_webhooks.source_profiles' => $profiles,
    ] + Settings::getAll());
    $this->assertSame(200, $this->bounce('marketing-secret-test-only', '23', 'outbound', '14'));
    $this->assertSame(1, $this->events());
  }

  /**
   * Duplicate secrets or bindings fail closed with 503.
   */
  public function testMalformedProfilesRefuse(): void {
    $duplicate_secret = $this->twoProfiles();
    $duplicate_secret['transactional']['secret'] = 'marketing-secret-test-only';
    new Settings([
      'postmark_webhooks.webhook_secret' => 'shared-secret-test-only',
      'postmark_webhooks.source_profiles' => $duplicate_secret,
    ] + Settings::getAll());
    $this->assertSame(503, $this->bounce('marketing-secret-test-only', '23', 'outbound', '12'));
    $duplicate_binding = $this->twoProfiles();
    $duplicate_binding['transactional']['sources'] = [
      ['server_id' => '23', 'message_stream' => 'outbound'],
    ];
    new Settings([
      'postmark_webhooks.source_profiles' => $duplicate_binding,
    ] + Settings::getAll());
    $this->assertSame(503, $this->bounce('transactional-secret-test-only', '23', 'outbound', '13'));
    $this->assertSame(0, $this->events());
  }

  /**
   * Malformed profiles do not mark a valid previous secret invalid.
   */
  public function testMalformedProfilesKeepSharedPreviousStatus(): void {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(100);
    $time->method('getCurrentTime')->willReturn(100);
    $this->container->set('datetime.time', $time);
    new Settings([
      'postmark_webhooks.webhook_secret' => 'shared-secret-test-only',
      'postmark_webhooks.source_profiles' => [
        'marketing' => [
          'secret' => 'marketing-secret-test-only',
        ],
      ],
    ] + Settings::getAll());
    $diagnostics = $this->container->get('postmark_webhooks.policy_preview')->diagnostics();
    $this->assertFalse($diagnostics['secret_configured']);
    $this->assertTrue($diagnostics['source_profiles']['malformed']);
    $this->assertSame('absent', $diagnostics['previous_secret_status']);
    $report = $this->container->get('postmark_webhooks.health')->evaluate(100);
    $this->assertSame('missing', $this->healthStatus($report, 'secret'));
    $this->assertSame('absent', $this->healthStatus($report, 'rotation'));
    new Settings([
      'postmark_webhooks.webhook_secret' => 'shared-secret-test-only',
      'postmark_webhooks.previous_webhook_secret' => [
        'secret' => 'shared-previous-test-only',
        'expires' => 200,
      ],
      'postmark_webhooks.source_profiles' => [
        'marketing' => [
          'secret' => 'marketing-secret-test-only',
        ],
      ],
    ] + Settings::getAll());
    $diagnostics = $this->container->get('postmark_webhooks.policy_preview')->diagnostics();
    $this->assertTrue($diagnostics['source_profiles']['malformed']);
    $this->assertSame('active', $diagnostics['previous_secret_status']);
    $this->assertStringNotContainsString('shared-previous-test-only', json_encode($diagnostics));
  }

  /**
   * Diagnostics list profile ids and rotation without secret values.
   */
  public function testDiagnosticsOmitSecrets(): void {
    new Settings([
      'postmark_webhooks.source_profiles' => $this->twoProfiles(),
    ] + Settings::getAll());
    $json = json_encode($this->container->get('postmark_webhooks.policy_preview')->diagnostics());
    $this->assertStringNotContainsString('marketing-secret-test-only', $json);
    $this->assertStringNotContainsString('marketing-previous-test-only', $json);
    $this->assertStringNotContainsString('transactional-secret-test-only', $json);
    $profiles = json_decode($json, TRUE)['source_profiles'];
    $this->assertTrue($profiles['enabled']);
    $this->assertFalse($profiles['malformed']);
    $this->assertSame(2, $profiles['count']);
    $this->assertSame('marketing', $profiles['profiles'][0]['id']);
    $config = $this->config('postmark_webhooks.settings')->getRawData();
    $this->assertArrayNotHasKey('source_profiles', $config);
    $this->assertArrayNotHasKey('webhook_secret', $config);
  }

  /**
   * Returns one health check status.
   *
   * @param array $report
   *   A health evaluation report.
   * @param string $id
   *   Check id.
   *
   * @return string
   *   The check status.
   */
  private function healthStatus(array $report, string $id): string {
    foreach ($report['checks'] as $check) {
      if ($check['id'] === $id) {
        return $check['status'];
      }
    }
    $this->fail('Missing check ' . $id);
  }

}
