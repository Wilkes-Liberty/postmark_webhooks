<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Unit;

use Composer\Semver\Semver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The declared Drupal range must keep the supported floors and include 12.
 *
 * The advertised floor moves from ^10.3 || ^11 to ^10.6 || ^11.3 so the
 * Drupal 12 claim is not attached to an EOL minor. Adding || ^12 must not
 * reopen 10.5 or 11.2. Optional submodules whose dependencies have not
 * declared 12 must not claim it on their own.
 *
 * @group postmark_webhooks
 */
final class CoreVersionRequirementTest extends TestCase {

  /**
   * Composer and the main info file must declare the same range.
   */
  public function testMainModuleDeclaresTheSupportedRange(): void {
    $expected = '^10.6 || ^11.3 || ^12';
    $this->assertSame($expected, $this->infoRequirement('postmark_webhooks.info.yml'));
    $this->assertSame($expected, $this->composerCoreConstraint());
  }

  /**
   * Reconciliation has no extra core constraint, so it follows the base module.
   */
  public function testReconcileSubmoduleDeclaresDrupal12(): void {
    $this->assertSame(
      '^10.6 || ^11.3 || ^12',
      $this->infoRequirement('modules/postmark_webhooks_reconcile/postmark_webhooks_reconcile.info.yml'),
    );
  }

  /**
   * Tool API declares ^10.5 || ^11, so the tools submodule cannot claim 12.
   */
  public function testMcpSubmoduleDoesNotClaimDrupal12(): void {
    $this->assertSame(
      '^10.6 || ^11.3',
      $this->infoRequirement('modules/postmark_webhooks_mcp/postmark_webhooks_mcp.info.yml'),
    );
  }

  /**
   * Mailer Plus still declares ^10.3 || ^11, so the adapter cannot claim 12.
   */
  public function testMailerSubmoduleDoesNotClaimDrupal12(): void {
    $this->assertSame(
      '^10.6 || ^11.3',
      $this->infoRequirement('modules/postmark_webhooks_mailer/postmark_webhooks_mailer.info.yml'),
    );
  }

  /**
   * Test modules must enable on the same core as the module they exercise.
   */
  public function testShippedTestModulesMatchTheirParents(): void {
    $with12 = '^10.6 || ^11.3 || ^12';
    $without12 = '^10.6 || ^11.3';
    $this->assertSame($with12, $this->infoRequirement('tests/modules/postmark_webhooks_test/postmark_webhooks_test.info.yml'));
    $this->assertSame($with12, $this->infoRequirement('tests/modules/postmark_health_alert_test/postmark_health_alert_test.info.yml'));
    $this->assertSame($with12, $this->infoRequirement('tests/modules/postmark_integration_test/postmark_integration_test.info.yml'));
    $this->assertSame($with12, $this->infoRequirement('tests/modules/postmark_webhooks_consumer_test/postmark_webhooks_consumer_test.info.yml'));
    $this->assertSame($with12, $this->infoRequirement('modules/postmark_webhooks_reconcile/tests/modules/postmark_drift_test/postmark_drift_test.info.yml'));
    $this->assertSame($without12, $this->infoRequirement('modules/postmark_webhooks_mailer/tests/modules/postmark_mailer_test/postmark_mailer_test.info.yml'));
  }

  /**
   * Supported floors and Drupal 12 satisfy the constraint; prior minors do not.
   */
  public function testConstraintKeepsFloorsAndIncludesDrupal12(): void {
    $constraint = $this->composerCoreConstraint();
    $this->assertTrue(Semver::satisfies('10.6.0', $constraint));
    $this->assertFalse(Semver::satisfies('10.5.99', $constraint));
    $this->assertTrue(Semver::satisfies('11.3.0', $constraint));
    $this->assertFalse(Semver::satisfies('11.2.99', $constraint));
    $this->assertTrue(Semver::satisfies('12.0.0', $constraint));
    $this->assertFalse(Semver::satisfies('13.0.0', $constraint));
  }

  /**
   * Reads core_version_requirement from an info.yml under the module root.
   */
  private function infoRequirement(string $relative): string {
    $path = $this->moduleRoot() . '/' . $relative;
    $this->assertFileExists($path);
    $info = Yaml::parseFile($path);
    $this->assertIsArray($info);
    $this->assertArrayHasKey('core_version_requirement', $info);
    $this->assertIsString($info['core_version_requirement']);
    return $info['core_version_requirement'];
  }

  /**
   * Reads the drupal/core Composer constraint.
   */
  private function composerCoreConstraint(): string {
    $path = $this->composerJsonPath();
    $this->assertFileExists($path);
    $composer = json_decode((string) file_get_contents($path), TRUE);
    $this->assertIsArray($composer);
    $this->assertIsString($composer['require']['drupal/core'] ?? NULL);
    return $composer['require']['drupal/core'];
  }

  /**
   * The module composer.json, read from the project mount on drupal.org.
   *
   * On drupal.org GitLab CI the checkout is also the Composer project
   * root. expand_composer_json.php overwrites its composer.json and drops
   * require.drupal/core, and gitlab_templates moves the module's own file
   * onto web/modules/custom/postmark_webhooks. Reading the checkout file
   * there yields a null constraint.
   */
  private function composerJsonPath(): string {
    $root = $this->moduleRoot();
    foreach (glob($root . '/web/modules/*/postmark_webhooks') ?: [] as $mount) {
      if (is_file($mount . '/postmark_webhooks.info.yml') && is_readable($mount . '/composer.json')) {
        return $mount . '/composer.json';
      }
    }
    return $root . '/composer.json';
  }

  /**
   * The module root, three directories above tests/src/Unit.
   */
  private function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

}
