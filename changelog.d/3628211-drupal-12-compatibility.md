---
section: Changed
---
#3628211: Declare Drupal 12 compatibility (`^10.6 || ^11.3 || ^12`) on the
base module and the reconciliation submodule. The advertised 10.3 floor is
raised to the same 10.6 and 11.3 floors as field_guard, file_gate and
menu_autopilot because Drupal 12 removes the `REQUIREMENT_*` constants
(replaced in 11.2 by `RequirementSeverity`). Status-report severities use
the enum from 11.2 and the constants on 10.6. The optional
`postmark_webhooks_mcp` and `postmark_webhooks_mailer` submodules stay on
`^10.6 || ^11.3` because Tool API and Mailer Plus have not declared Drupal
12.
https://www.drupal.org/project/postmark_webhooks/issues/3628211
