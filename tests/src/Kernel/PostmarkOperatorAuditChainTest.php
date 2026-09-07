<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\postmark_webhooks\Operator\OperatorAudit;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Verifies optional audit_chain dual-write of operator audits.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkOperatorAuditChainTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'postmark_webhooks'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', [
      'postmark_events',
      'postmark_suppression',
      'postmark_operator_audit',
    ]);
  }

  /**
   * Creates an actor with a fixed uid.
   */
  private function actor(): AccountInterface {
    $actor = $this->createMock(AccountInterface::class);
    $actor->method('id')->willReturn(42);
    return $actor;
  }

  /**
   * Dual-write copies hashed subject, uid and target, never the mailbox.
   */
  public function testDualWriteOmitsMailbox(): void {
    $recorded = new \ArrayObject();
    $chain = new class($recorded) {

      public function __construct(private readonly \ArrayObject $recorded) {}

      /**
       * Records a chain append.
       *
       * @param string $channel
       *   Channel name.
       * @param string $operation
       *   Operation name.
       * @param array $metadata
       *   Metadata payload.
       */
      public function log(string $channel, string $operation, array $metadata = []): void {
        $this->recorded[] = [
          'channel' => $channel,
          'operation' => $operation,
          'metadata' => $metadata,
        ];
      }

    };
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('error');
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);
    $audit = new OperatorAudit($this->container->get('database'), $chain, $factory);
    $audit->record('recover_hard', 'private@example.com', $this->actor(), 123, 'src-1');
    $row = $this->container->get('database')->select('postmark_operator_audit', 'a')->fields('a')->execute()->fetchAssoc();
    $this->assertSame('recover_hard', $row['action']);
    $this->assertCount(1, $recorded);
    $entry = $recorded[0];
    $this->assertSame(OperatorAudit::CHAIN_CHANNEL, $entry['channel']);
    $this->assertSame('recover_hard', $entry['operation']);
    $this->assertSame($row['subject'], $entry['metadata']['subject']);
    $this->assertSame('42', $entry['metadata']['uid']);
    $this->assertSame('src-1', $entry['metadata']['target']);
    $this->assertArrayNotHasKey('recipient', $entry['metadata']);
    $this->assertStringNotContainsString('private@example.com', json_encode($entry));
  }

  /**
   * A missing chain service leaves only the local row.
   */
  public function testAbsentChainIsLocalOnly(): void {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->expects($this->never())->method('get');
    $audit = new OperatorAudit($this->container->get('database'), NULL, $factory);
    $audit->record('export_requested', 'private@example.com', $this->actor(), 10);
    $this->assertSame(1, (int) $this->container->get('database')->select('postmark_operator_audit')->countQuery()->execute()->fetchField());
  }

  /**
   * Chain write failure is logged and does not undo the local row.
   */
  public function testChainFailureKeepsLocalRow(): void {
    $chain = new class() {

      /**
       * Always fails.
       *
       * @param string $channel
       *   Channel name.
       * @param string $operation
       *   Operation name.
       * @param array $metadata
       *   Metadata payload.
       */
      public function log(string $channel, string $operation, array $metadata = []): void {
        throw new \RuntimeException('chain down');
      }

    };
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->with('postmark_webhooks')->willReturn($logger);
    $audit = new OperatorAudit($this->container->get('database'), $chain, $factory);
    $audit->record('erase_history_batch', 'private@example.com', $this->actor(), 10);
    $this->assertSame(1, (int) $this->container->get('database')->select('postmark_operator_audit')->countQuery()->execute()->fetchField());
  }

}
