<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * MessageID timeline groups events without treating them as message identity.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkEventTimelineTest extends KernelTestBase {

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
   * Denied actors cannot read retained events.
   */
  public function testPermissionDenied(): void {
    $this->expectException(AccessDeniedHttpException::class);
    $this->container->get('postmark_webhooks.event_timeline')
      ->lookup('msg-1', [], $this->actor([]), 0);
  }

  /**
   * Delayed provider times sort ahead of later receipt order.
   */
  public function testDelayedEventsSortByOccurrence(): void {
    $this->seed('msg-1', 'Delivery', 150, 150, 'a');
    $this->seed('msg-1', 'Bounce', 100, 300, 'b', 'HardBounce');
    $this->seed('msg-2', 'Delivery', 90, 90, 'c');
    $result = $this->container->get('postmark_webhooks.event_timeline')
      ->lookup('msg-1', [], $this->actor(['view postmark suppression']), 0);
    $this->assertSame(2, $result['total']);
    $this->assertSame('Bounce', $result['events'][0]->event_type);
    $this->assertSame(100, (int) $result['events'][0]->occurred);
    $this->assertSame('provider', $result['events'][0]->time_basis);
    $this->assertSame('Delivery', $result['events'][1]->event_type);
    $this->assertSame('receipt', $result['events'][1]->time_basis);
  }

  /**
   * Source and type filters stay bounded to the authenticated MessageID.
   */
  public function testFiltersAndPagination(): void {
    for ($i = 0; $i < 26; $i++) {
      $this->seed('msg-page', 'Delivery', $i + 1, $i + 1, 'p' . $i);
    }
    $this->seed('msg-page', 'Bounce', 50, 50, 'bounce', 'HardBounce', '99', 'outbound');
    $timeline = $this->container->get('postmark_webhooks.event_timeline');
    $actor = $this->actor(['view postmark suppression']);
    $first = $timeline->lookup('msg-page', [], $actor, 0);
    $this->assertSame(27, $first['total']);
    $this->assertSame(2, $first['pages']);
    $this->assertCount(25, $first['events']);
    $second = $timeline->lookup('msg-page', [], $actor, 1);
    $this->assertCount(2, $second['events']);
    $bounces = $timeline->lookup('msg-page', ['event_type' => 'Bounce'], $actor, 0);
    $this->assertSame(1, $bounces['total']);
    $this->assertSame('HardBounce', $bounces['events'][0]->bounce_type);
    $source = $timeline->lookup('msg-page', [
      'server_id' => '99',
      'message_stream' => 'outbound',
    ], $actor, 0);
    $this->assertSame(1, $source['total']);
  }

  /**
   * Empty lookups stay empty after retention and never claim a full archive.
   */
  public function testRetentionLeavesEmptyLookup(): void {
    $this->seed('msg-gone', 'Delivery', 1, 1, 'gone');
    $this->container->get('database')->delete('postmark_events')->execute();
    $result = $this->container->get('postmark_webhooks.event_timeline')
      ->lookup('msg-gone', [], $this->actor(['view postmark suppression']), 0);
    $this->assertSame(0, $result['total']);
    $this->assertSame([], $result['events']);
  }

  /**
   * Malformed filters fail before any query.
   */
  public function testMalformedFilters(): void {
    $timeline = $this->container->get('postmark_webhooks.event_timeline');
    $actor = $this->actor(['view postmark suppression']);
    $this->expectException(\InvalidArgumentException::class);
    $timeline->lookup('msg-1', ['server_id' => '1'], $actor, 0);
  }

  /**
   * Invalid source values return an actionable operator error.
   */
  public function testInvalidSourceMessage(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('numeric server ID');
    $this->container->get('postmark_webhooks.event_timeline')->lookup('msg-1', [
      'server_id' => 'abc',
      'message_stream' => 'outbound',
    ], $this->actor(['view postmark suppression']), 0);
  }

  /**
   * Inserts one retained event for timeline tests.
   */
  private function seed(
    string $message_id,
    string $type,
    int $occurred,
    int $created,
    string $suffix,
    string $bounce = '',
    string $server = '1',
    string $stream = 'outbound',
  ): void {
    $this->container->get('database')->insert('postmark_events')->fields([
      'message_id' => $message_id,
      'event_type' => $type,
      'bounce_type' => $bounce,
      'recipient' => 'private@example.com',
      'server_id' => $server,
      'message_stream' => $stream,
      'occurred' => $occurred,
      'created' => $created,
      'time_basis' => $occurred === $created ? 'receipt' : 'provider',
      'event_key' => hash('sha256', $suffix),
      'description' => 'should-not-surface',
    ])->execute();
  }

  /**
   * Builds an actor with explicit permissions.
   */
  private function actor(array $permissions): AccountInterface {
    $actor = $this->createMock(AccountInterface::class);
    $actor->method('id')->willReturn(42);
    $actor->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => in_array($permission, $permissions, TRUE),
    );
    return $actor;
  }

}
