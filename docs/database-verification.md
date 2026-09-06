# Database driver verification

Issue #3621226. PostgreSQL 16 remains the default CI matrix from #3621225.
MySQL, MariaDB and SQLite run a risk-based pair: Drupal 10.6 / PHP 8.3 and
Drupal 11.4 / PHP 8.5. Exact resolved database versions are written to each
job's Actions summary.

| Job | Engine | Drupal | PHP |
| --- | --- | --- | --- |
| mysql-8.4-d10.6-php8.3 | MySQL 8.4 | 10.6.* | 8.3 |
| mysql-8.4-d11.4-php8.5 | MySQL 8.4 | 11.4.* | 8.5 |
| mariadb-10.11-d10.6-php8.3 | MariaDB 10.11 | 10.6.* | 8.3 |
| mariadb-10.11-d11.4-php8.5 | MariaDB 10.11 | 11.4.* | 8.5 |
| sqlite-d10.6-php8.3 | SQLite | 10.6.* | 8.3 |
| sqlite-d11.4-php8.5 | SQLite | 11.4.* | 8.5 |

Each of those jobs runs the kernel/HTTP suite (fresh install, alpha upgrades
with 251 retained rows and resumed batches, concurrent identical and distinct
intake, unique event keys, case normalization, unrelated constraint failures,
privacy erasure and bounded retention), reconciliation, Drush discovery and the
`/subdirectory` HTTP fixture. Mailer Plus stays on the PostgreSQL matrix.

## Query plans

`PostmarkQueryPlanTest` seeds 5,000 history rows (400 expired, the rest
current) and 200 suppression rows, refreshes planner statistics, and requires
the cleanup selection (`created < cutoff ORDER BY created LIMIT 250`) and
recipient lookup to use an index rather than a full scan. That is CI evidence,
not a 100,000-row throughput measurement. The earlier PostgreSQL 100,000-row
sample remains in [retention-benchmark.md](retention-benchmark.md).

## Transaction behavior

Webhook intake uses a Drupal transaction around insert plus suppression
recording. A unique `event_key` collision rolls back and then looks up the
existing row; a different constraint still fails the request. Concurrent
workers load the active Drupal database driver from the test connection
options (PostgreSQL, MySQL/MariaDB, or SQLite) instead of assuming pgsql.
SQLite serializes writers; the suite still requires one logical event per
identity and no partial event/state commit.

## Remaining limits

This is not a full Drupal × PHP × mailer × driver Cartesian product. Query
plans are asserted on a representative mixed-age 5,000-row set, not on
production-scale bloat. Direct Symfony transports remain outside the documented
adapter boundary.
