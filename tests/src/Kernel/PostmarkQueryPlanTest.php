<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Asserts cleanup and suppression lookups use indexes on each database driver.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkQueryPlanTest extends KernelTestBase {

  /**
   * History rows used for planner evidence, mixed expired and current.
   */
  private const HISTORY_ROWS = 5000;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['postmark_webhooks'];

  /**
   * Cleanup selection and recipient lookup use the declared indexes.
   */
  public function testCleanupAndLookupUseIndexes(): void {
    $this->installSchema('postmark_webhooks', [
      'postmark_events',
      'postmark_suppression',
      'postmark_intake_metrics',
    ]);
    $database = $this->container->get('database');
    $insert = $database->insert('postmark_events')->fields(['created', 'recipient']);
    for ($i = 0; $i < self::HISTORY_ROWS; $i++) {
      // Expired rows are a minority so created < cutoff can use the index
      // instead of scanning the whole table.
      $insert->values([$i < 400 ? $i : 1000 + $i, 'history' . ($i % 50) . '@example.com']);
    }
    $insert->execute();
    $states = $database->insert('postmark_suppression')->fields([
      'state_key',
      'recipient',
      'server_id',
      'message_stream',
      'reason',
      'occurred',
      'time_basis',
      'evidence',
    ]);
    for ($i = 0; $i < 2000; $i++) {
      $states->values([
        hash('sha256', (string) $i),
        'lookup' . $i . '@example.com',
        '',
        '',
        'hard:HardBounce',
        1,
        'provider',
        hash('sha256', 'evidence' . $i),
      ]);
    }
    $states->execute();
    $this->analyze('postmark_events');
    $this->analyze('postmark_suppression');

    $cleanup = $database->select('postmark_events', 'pe')
      ->fields('pe', ['eid'])
      ->condition('created', 100, '<')
      ->orderBy('created')
      ->range(0, 250);
    $lookup = $database->select('postmark_suppression', 'ps')
      ->fields('ps')
      ->condition('recipient', 'lookup25@example.com');

    $this->assertIndexAccess($this->explain($cleanup), 'created', 'cleanup');
    $this->assertIndexAccess($this->explain($lookup), 'recipient', 'lookup');
  }

  /**
   * Refreshes planner statistics for a module table.
   */
  private function analyze(string $table): void {
    $database = $this->container->get('database');
    $prefixed = $database->prefixTables('{' . $table . '}');
    match ($database->driver()) {
      'mysql' => $database->query('ANALYZE TABLE ' . $prefixed),
      'sqlite' => $database->query('ANALYZE'),
      default => $database->query('ANALYZE ' . $prefixed),
    };
  }

  /**
   * Returns a driver-specific EXPLAIN dump for a select query.
   */
  private function explain(SelectInterface $query): array {
    $database = $this->container->get('database');
    $driver = $database->driver();
    $command = match ($driver) {
      'pgsql' => 'EXPLAIN (FORMAT TEXT) ',
      'sqlite' => 'EXPLAIN QUERY PLAN ',
      default => 'EXPLAIN ',
    };
    $rows = $database->query($command . $query->__toString(), $query->getArguments())->fetchAll();
    $lines = [];
    foreach ($rows as $row) {
      $values = [];
      foreach ((array) $row as $value) {
        $values[] = is_scalar($value) ? (string) $value : json_encode($value);
      }
      $lines[] = implode(' | ', $values);
    }
    return [
      'driver' => $driver,
      'rows' => $rows,
      'text' => strtolower(implode("\n", $lines)),
    ];
  }

  /**
   * Asserts the plan reads through an index rather than a full scan.
   */
  private function assertIndexAccess(array $plan, string $column, string $label): void {
    $text = $plan['text'];
    $this->assertNotSame('', $text, $label . ' plan was empty');
    if ($plan['driver'] === 'mysql') {
      $keys = [];
      foreach ($plan['rows'] as $row) {
        $fields = array_change_key_case((array) $row, CASE_LOWER);
        $keys[] = strtolower((string) ($fields['key'] ?? ''));
      }
      $matched = array_filter($keys, static fn (string $key): bool => $key !== '' && str_contains($key, $column));
      $this->assertNotSame([], $matched, $label . " MySQL/MariaDB plan did not use the $column index:\n" . $text);
      return;
    }
    $uses_index = str_contains($text, 'index') || str_contains($text, $column);
    $full_scan = match ($plan['driver']) {
      'pgsql' => str_contains($text, 'seq scan') && !str_contains($text, 'index'),
      default => str_contains($text, 'scan') && !str_contains($text, 'index'),
    };
    $this->assertFalse($full_scan, $label . ' used a full scan on ' . $plan['driver'] . ":\n" . $text);
    $this->assertTrue($uses_index, $label . ' plan did not mention an index or ' . $column . ' on ' . $plan['driver'] . ":\n" . $text);
  }

}
