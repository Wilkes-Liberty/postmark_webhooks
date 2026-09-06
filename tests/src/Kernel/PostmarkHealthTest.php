<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies health evaluation, quiet-site handling and alert delivery.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkHealthTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'postmark_webhooks',
    'postmark_health_alert_test',
  ];

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
    new Settings(['postmark_webhooks.webhook_secret' => 'health-secret']);
  }

  /**
   * Quiet sites without an activity window are unknown, not broken.
   */
  public function testQuietSiteIsUnknown(): void {
    $report = $this->container->get('postmark_webhooks.health')->evaluate(1_700_000_000);
    $this->assertSame('unknown', $report['severity']);
    $this->assertSame('unproven', $report['endpoint_reachability']);
    $this->assertSame('not_configured', $this->checkStatus($report, 'intake_silence'));
    $this->assertSame('unknown', $this->checkSeverity($report, 'intake_silence'));
    $this->assertSame('not_queried', $this->checkStatus($report, 'provider_delivery'));
    $json = json_encode($report, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('health-secret', $json);
    $this->assertStringNotContainsString('@example.com', $json);
    $this->assertNull($this->container->get('postmark_webhooks.health_alerts')->notify(1_700_000_000));
  }

  /**
   * A configured activity window treats missing intake as stale.
   */
  public function testConfiguredSilenceIsWarning(): void {
    $this->config('postmark_webhooks.settings')->set('health_expected_activity_seconds', 3600)->save();
    $report = $this->container->get('postmark_webhooks.health')->evaluate(1_700_000_000);
    $this->assertSame('warning', $report['severity']);
    $this->assertSame('no_traffic', $this->checkStatus($report, 'intake_silence'));
    $alert = $this->container->get('postmark_webhooks.health_alerts')->notify(1_700_000_000);
    $this->assertSame('alert', $alert['event']);
    $this->assertSame('warning', $alert['severity']);
    $this->assertNull($this->container->get('postmark_webhooks.health_alerts')->notify(1_700_000_100));
  }

  /**
   * Last-accepted timestamps are compared against a frozen now.
   */
  public function testStaleIntakeWindow(): void {
    $this->config('postmark_webhooks.settings')->set('health_expected_activity_seconds', 3600)->save();
    $this->container->get('database')->merge('postmark_intake_metrics')
      ->key('outcome', 'accepted')
      ->fields(['total' => 1, 'last_seen' => 1_700_000_000 - 7200])
      ->execute();
    $stale = $this->container->get('postmark_webhooks.health')->evaluate(1_700_000_000);
    $this->assertSame('stale', $this->checkStatus($stale, 'intake_silence'));
    $this->container->get('database')->merge('postmark_intake_metrics')
      ->key('outcome', 'accepted')
      ->fields(['total' => 2, 'last_seen' => 1_700_000_000 - 60])
      ->execute();
    $fresh = $this->container->get('postmark_webhooks.health')->evaluate(1_700_000_000);
    $this->assertSame('fresh', $this->checkStatus($fresh, 'intake_silence'));
    $this->assertSame('ok', $this->checkSeverity($fresh, 'intake_silence'));
  }

  /**
   * Missing credentials are errors; rotation expiry can warn.
   */
  public function testCredentialsAndRotation(): void {
    new Settings([]);
    $missing = $this->container->get('postmark_webhooks.health')->evaluate(100);
    $this->assertSame('error', $missing['severity']);
    $this->assertSame('missing', $this->checkStatus($missing, 'secret'));
    new Settings([
      'postmark_webhooks.webhook_secret' => 'health-secret',
      'postmark_webhooks.previous_webhook_secret' => [
        'secret' => 'previous-secret',
        'expires' => 200,
      ],
    ]);
    $expiring = $this->container->get('postmark_webhooks.health')->evaluate(100);
    $this->assertSame('expiring', $this->checkStatus($expiring, 'rotation'));
    $this->assertSame('warning', $this->checkSeverity($expiring, 'rotation'));
    $this->assertSame(100, $expiring['rotation']['expires_in']);
  }

  /**
   * Retention backlog warnings use expired row counts, not suppression rows.
   */
  public function testRetentionBacklog(): void {
    $this->config('postmark_webhooks.settings')
      ->set('event_retention_days', 1)
      ->set('health_retention_backlog_warning', 1)
      ->save();
    $database = $this->container->get('database');
    $database->insert('postmark_events')->fields(['created', 'event_type', 'description'])->values([1, 'Delivery', ''])->execute();
    $database->insert('postmark_events')->fields(['created', 'event_type', 'description'])->values([1, 'Delivery', ''])->execute();
    $report = $this->container->get('postmark_webhooks.health')->evaluate(200000);
    $this->assertSame('backlogged', $this->checkStatus($report, 'retention_backlog'));
  }

  /**
   * Recovery alerts fire once after an unhealthy report returns to ok/unknown.
   */
  public function testRecoveryAndNotificationFailure(): void {
    $this->config('postmark_webhooks.settings')->set('health_expected_activity_seconds', 3600)->save();
    $this->container->get('postmark_webhooks.health_alerts')->notify(1_700_000_000);
    $this->config('postmark_webhooks.settings')->set('health_expected_activity_seconds', 0)->save();
    $recovery = $this->container->get('postmark_webhooks.health_alerts')->notify(1_700_000_200);
    $this->assertSame('recovery', $recovery['event']);
    $alerts = $this->container->get('state')->get('postmark_health_alert_test.alerts');
    $this->assertCount(2, $alerts);
    $this->container->get('state')->set('postmark_health_alert_test.throw', TRUE);
    $this->config('postmark_webhooks.settings')->set('health_expected_activity_seconds', 3600)->save();
    $failed = $this->container->get('postmark_webhooks.health_alerts')->notify(1_700_000_400);
    $this->assertSame('alert', $failed['event']);
    $this->assertSame('warning', $this->container->get('postmark_webhooks.health')->evaluate(1_700_000_400)['severity']);
  }

  /**
   * Optional provider hooks can distinguish paused delivery from local silence.
   */
  public function testProviderPaused(): void {
    $this->container->get('state')->set('postmark_health_alert_test.provider', 'paused');
    $report = $this->container->get('postmark_webhooks.health')->evaluate(1_700_000_000);
    $this->assertSame('paused', $this->checkStatus($report, 'provider_delivery'));
    $this->assertSame('warning', $this->checkSeverity($report, 'provider_delivery'));
    $this->assertSame('unproven', $report['endpoint_reachability']);
  }

  /**
   * Returns one check status.
   */
  private function checkStatus(array $report, string $id): string {
    foreach ($report['checks'] as $check) {
      if ($check['id'] === $id) {
        return $check['status'];
      }
    }
    $this->fail('Missing check ' . $id);
  }

  /**
   * Returns one check severity.
   */
  private function checkSeverity(array $report, string $id): string {
    foreach ($report['checks'] as $check) {
      if ($check['id'] === $id) {
        return $check['severity'];
      }
    }
    $this->fail('Missing check ' . $id);
  }

}
