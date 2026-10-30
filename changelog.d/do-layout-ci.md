---
section: Tests
---
`CoreVersionRequirementTest` reads the module `composer.json` from the drupal.org project mount, where the 1.3.0 pipeline read the expanded project file and got a null constraint. The `postmark_webhooks_mcp` kernel tests skip when Tool API and MCP Sentinel are not installed, as on drupal.org CI; the GitHub workflow installs both and still runs them.
