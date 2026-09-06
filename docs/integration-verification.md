# Integration verification

Verified 2026-09-06 on isolated Drupal 10.3/PHP 8.3 and Drupal 11/PHP 8.4
fixtures with PostgreSQL 16. No provider credentials or real mail were used.

CI runs six combinations: each Drupal branch without Mailer Plus, with Mailer
Plus 1.6.2, and with Mailer Plus 2.0.2. Each job checks Drupal coding standards,
the core kernel/HTTP suite, optional reconciliation and actual Drush discovery.
Mailer jobs additionally exercise native and compatibility transport cancellation.
The final installed-site check serves Drupal beneath `/subdirectory` and verifies
Basic Auth, idempotent intake, authenticated settings and prefixed links.

Synthetic bounce, complaint and subscription fixtures follow Postmark's official
examples; their provenance is recorded beside the JSON files. Assertions cover
authentication denial, retry identity, private-field exclusion and transport
cancellation. Upgrade tests cover retained legacy evidence and restartable schema
or privacy updates. Reconciliation tests use a fake HTTP transport, not a live API.

Live browser checks used a disposable site at a 375 × 812 viewport with the Stark
theme. Settings, policy preview, inspector, recovery confirmation, export and
history-erasure confirmation all fit without horizontal scrolling. Checks verified
accessible labels, recipient-to-mail-path focus order, keyboard submission,
evidence expansion, confirmation navigation/cancellation and repeated submissions.
The tests found and fixed oversized default input widths and private injected
properties lost during Drupal form-cache serialization. HTTP regressions preserve
the repeated-submit and CSRF contracts.

These checks do not claim a complete screen-reader audit or production-theme
certification. PostgreSQL is the measured database target; MySQL/SQLite query
plans and concurrency behavior remain unverified. Direct Symfony transports and
custom processors that mutate recipients after the adapter callback remain
outside the documented adapter boundary.
