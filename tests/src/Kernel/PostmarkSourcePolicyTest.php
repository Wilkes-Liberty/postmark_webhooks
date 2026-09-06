<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\postmark_webhooks\Controller\PostmarkWebhookController;
use Drupal\postmark_webhooks\Source\SourceContext;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies explicit source policy and credential-bound intake restrictions.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkSourcePolicyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'postmark_webhooks', 'postmark_webhooks_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', ['postmark_events', 'postmark_suppression']);
    $this->installConfig(['postmark_webhooks']);
  }

  /**
   * Explicit source mappings narrow only known sources with trusted context.
   */
  public function testScopedAndGlobalPolicy(): void {
    $store = $this->container->get('postmark_webhooks.suppression_store');
    $event = [
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'scope@example.com',
      'server_id' => '1',
      'message_stream' => 'broadcast',
      'created' => 1,
      'event_key' => str_repeat('a', 64),
    ];
    $store->record($event);
    $policy = $this->container->get('postmark_webhooks.suppression_policy');
    $outbound = new SourceContext('1', 'outbound');
    $broadcast = new SourceContext('1', 'broadcast');
    $this->assertTrue($policy->decide('SCOPE@example.com', $outbound)->suppressed);
    $mapping = ['server_id' => '1', 'message_stream' => 'broadcast', 'scope' => 'source'];
    $this->config('postmark_webhooks.settings')->set('source_policies', [$mapping])->save();
    $this->assertFalse($policy->decide('scope@example.com', $outbound)->suppressed);
    $this->assertTrue($policy->decide('scope@example.com', $broadcast)->suppressed);
    $this->assertTrue($policy->decide('scope@example.com')->suppressed);
    $this->assertFalse($policy->decide('scope@example.com', new SourceContext('2', 'broadcast'))->suppressed);
    // Legacy evidence with no reliable source remains global.
    $store->record(['server_id' => '', 'message_stream' => ''] + $event);
    $this->assertTrue($policy->decide('scope@example.com', $outbound)->suppressed);
    $this->config('postmark_webhooks.settings')->set('source_policies', [$mapping, $mapping])->save();
    $this->assertSame('invalid_source_policy', $policy->decide('any@example.com', $outbound)->reason);
    $this->config('postmark_webhooks.settings')->set('source_policies', [
      ['server_id' => '1', 'message_stream' => chr(255), 'scope' => 'source'],
    ])->save();
    $this->assertSame('invalid_source_policy', $policy->decide('any@example.com', $outbound)->reason);
  }

  /**
   * Core transport uses explicit source context and otherwise fails closed.
   */
  public function testCoreMailContext(): void {
    $this->config('system.site')->set('mail', 'sender@example.com')->save();
    $this->config('system.mail')->set('interface', ['default' => 'test_mail_collector'])->save();
    $this->container->get('state')->set('system.test_mail_collector', []);
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'scope@example.com',
      'server_id' => '1',
      'message_stream' => 'broadcast',
      'created' => 1,
      'event_key' => str_repeat('a', 64),
    ]);
    $this->config('postmark_webhooks.settings')->set('source_policies', [
      ['server_id' => '1', 'message_stream' => 'broadcast', 'scope' => 'source'],
    ])->save();
    $manager = $this->container->get('plugin.manager.mail');
    $allowed = $manager->mail('postmark_webhooks_test', 'test', 'scope@example.com', 'en', [
      'postmark_webhooks_source' => new SourceContext('1', 'outbound'),
    ]);
    $this->assertTrue($allowed['result']);
    $unknown = $manager->mail('postmark_webhooks_test', 'test', 'scope@example.com', 'en');
    $this->assertFalse($unknown['send']);
    $invalid = $manager->mail('postmark_webhooks_test', 'test', 'scope@example.com', 'en', [
      'postmark_webhooks_source' => ['server_id' => '1'],
    ]);
    $this->assertFalse($invalid['send']);
    $this->assertCount(1, $this->container->get('state')->get('system.test_mail_collector', []));
  }

  /**
   * Payload source labels cannot escape the credential's configured allowlist.
   */
  public function testIntakeAllowlist(): void {
    $settings = Settings::getAll();
    new Settings([
      'postmark_webhooks.webhook_secret' => 'source-test-only',
      'postmark_webhooks.allowed_sources' => [['server_id' => 1, 'message_stream' => 'broadcast']],
    ] + $settings);
    $send = function (array $source, string $password = 'source-test-only'): int {
      $request = Request::create('/api/webhooks/postmark', 'POST', [], [], [], [], json_encode($source + [
        'RecordType' => 'Bounce',
        'Type' => 'HardBounce',
        'Email' => 'scope@example.com',
      ]));
      $request->headers->set('Authorization', 'Basic ' . base64_encode('postmark:' . $password));
      return PostmarkWebhookController::create($this->container)->receive($request)->getStatusCode();
    };
    $this->assertSame(403, $send([]));
    $this->assertSame(403, $send(['ServerID' => 2, 'MessageStream' => 'broadcast']));
    $this->assertSame(403, $send(['ServerID' => 1, 'MessageStream' => 'outbound']));
    $this->assertSame(200, $send(['ServerID' => 1, 'MessageStream' => 'broadcast']));
    new Settings([
      'postmark_webhooks.webhook_secret' => 'source-test-only',
      'postmark_webhooks.allowed_sources' => 'invalid',
    ] + $settings);
    $this->assertSame(503, $send([]));
    $this->assertSame(401, $send([], 'incorrect'));
    $this->assertSame(1, (int) $this->container->get('database')->select('postmark_events')->countQuery()->execute()->fetchField());
  }

  /**
   * Configuration imports reject ambiguous duplicate source rules.
   */
  public function testInvalidMappingImport(): void {
    $source = new MemoryStorage();
    $target = new MemoryStorage();
    $data = $this->config('postmark_webhooks.settings')->getRawData();
    $mapping = ['server_id' => '1', 'message_stream' => 'broadcast', 'scope' => 'source'];
    $source->write('postmark_webhooks.settings', ['source_policies' => [$mapping, $mapping]] + $data);
    $target->write('postmark_webhooks.settings', $data);
    $comparer = new StorageComparer($source, $target);
    $comparer->createChangelist();
    $importer = $this->getMockBuilder(ConfigImporter::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getStorageComparer', 'logError'])
      ->getMock();
    $importer->method('getStorageComparer')->willReturn($comparer);
    $importer->expects($this->once())->method('logError');
    $this->container->get('postmark_webhooks.config_import_validator')->validate(new ConfigImporterEvent($importer));
  }

}
