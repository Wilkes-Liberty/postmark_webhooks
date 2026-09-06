# Retention and lookup benchmark — #3621134

Measured 2026-09-06 against isolated PostgreSQL fixtures in DDEV. Seeded 100,000
expired event rows and 10,000 distinct normalized suppression recipients using
batches of 1,000 inserts, then ran ANALYZE. No production data or mail was used.

| Fixture | Cleanup selection | Recipient lookup | Delete 250 through service | Allocated memory delta |
| --- | --- | --- | --- | --- |
| Drupal 10.3.14 / PHP 8.3 | 0.062 ms | 0.017 ms | 11.75 ms | 936 bytes |
| Drupal 11.4.6 / PHP 8.4 | 0.064 ms | 0.012 ms | 14.67 ms | 198,592 bytes |

The memory figures are before/after PHP allocation deltas around one service
call, including lazy service loading, not peak-memory bounds. The implementation
materializes at most 250 primary keys. Each measurement is a single local sample;
these values are not a production latency or throughput guarantee. Lock waits,
I/O, table bloat and a different data distribution can change them.

The measured PostgreSQL plans were:

```text
Limit (actual rows=250)
  Index Scan using postmark_events__created__idx
    Index Cond: created < 100

Index Scan using postmark_suppression__recipient__idx (actual rows=1)
  Index Cond: recipient = 'recipient9999@example.com'
```

Equivalent diagnostic queries, using the installation's table prefix:

```sql
EXPLAIN (ANALYZE, FORMAT JSON)
SELECT eid FROM postmark_events WHERE created < 100 ORDER BY created LIMIT 250;

EXPLAIN (ANALYZE, FORMAT JSON)
SELECT * FROM postmark_suppression WHERE recipient = 'recipient9999@example.com';
```

Cleanup orders by the existing indexed receipt time. Equal-time rows need no
secondary sort or cursor: successful deletion removes them from the next batch.
The DELETE rechecks the cutoff and is restricted to the selected primary keys.
Two cleanup workers may overlap safely; new inserts are eligible in later batches.
Suppression reads normalized durable state rather than applying LOWER() to history.
Legacy mixed-case evidence is normalized by the durable-state upgrade.

Automated coverage includes batch boundaries, late older arrivals, current rows,
retained suppression, and two independent cleanup processes racing with a webhook
receiver. Both Drupal/PHP fixtures pass. Queries use Drupal's portable database
API; this benchmark does not claim measured plans on MySQL or SQLite.
