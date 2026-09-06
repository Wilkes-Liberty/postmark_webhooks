<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Diagnostics;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\postmark_webhooks\Authentication\WebhookCredentials;
use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\postmark_webhooks\Suppression\SuppressionPolicyInterface;

/**
 * Read-only effective policy and coverage diagnostics, without sending mail.
 */
final class PolicyPreview {

  /**
   * Constructs the preview service.
   */
  public function __construct(
    private readonly SuppressionPolicyInterface $policy,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EmailValidatorInterface $emailValidator,
    private readonly TimeInterface $time,
    private readonly IntakeMetrics $metrics,
  ) {}

  /**
   * Reports configuration readiness without returning secrets or mailboxes.
   */
  public function diagnostics(): array {
    $credentials = WebhookCredentials::fromSettings();
    return [
      'enabled' => (bool) $this->configFactory->get('postmark_webhooks.settings')->get('enabled'),
      'secret_configured' => $credentials->isConfigured(),
      'previous_secret_status' => $credentials->rotationStatus($this->time->getCurrentTime()),
      'coverage' => [
        'core' => TRUE,
        'mailer_plus' => $this->moduleHandler->moduleExists('postmark_webhooks_mailer'),
        'direct_symfony' => FALSE,
      ],
      'intake' => $this->metrics->snapshot(),
    ];
  }

  /**
   * Predicts only this module's block, not provider acceptance or delivery.
   */
  public function preview(string $recipient, string $path = 'core', ?SourceContext $source = NULL): array {
    $recipient = mb_strtolower(trim($recipient));
    if (!$this->emailValidator->isValid($recipient)) {
      throw new \InvalidArgumentException('Enter one valid email address.');
    }
    $diagnostics = $this->diagnostics();
    if (!array_key_exists($path, $diagnostics['coverage'])) {
      throw new \InvalidArgumentException('Unknown mail path.');
    }
    $decision = $this->policy->decide($recipient, $source)->jsonSerialize();
    $covered = $diagnostics['coverage'][$path];
    return [
      'enabled' => $diagnostics['enabled'],
      'mail_path' => $path,
      'covered' => $covered,
      'effective_block' => $diagnostics['enabled'] && $covered && $decision['suppressed'],
      'delivery_guaranteed' => FALSE,
      'policy' => $decision,
    ];
  }

}
