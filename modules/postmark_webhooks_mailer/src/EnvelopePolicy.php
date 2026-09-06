<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_mailer;

use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\postmark_webhooks\Suppression\SuppressionPolicyInterface;
use Drupal\symfony_mailer\EmailInterface;
use Drupal\symfony_mailer\Exception\SkipMailException;

/**
 * Checks the final Mailer Plus envelope before it reaches transport.
 *
 * @internal
 */
final class EnvelopePolicy {

  /**
   * Constructs the envelope policy adapter.
   */
  public function __construct(
    private readonly SuppressionPolicyInterface $policy,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Cancels the whole message when any final envelope recipient is suppressed.
   */
  public function check(EmailInterface $email): void {
    if (!$this->configFactory->get('postmark_webhooks.settings')->get('enabled')) {
      return;
    }
    $source = $email->getParam('postmark_webhooks_source');
    if ($source !== NULL && !$source instanceof SourceContext) {
      throw new SkipMailException('Invalid Postmark sending source context.');
    }
    $recipients = [];
    // These addresses become the transport envelope after post-render. Do not
    // call getSymfonyEmail(): that advances Mailer Plus to its post-send phase.
    foreach (array_merge($email->getTo(), $email->getCc(), $email->getBcc()) as $address) {
      $recipients[mb_strtolower($address->getEmail())] = TRUE;
    }
    foreach (['To', 'Cc', 'Bcc'] as $name) {
      foreach ($email->getHeaders()->all($name) as $header) {
        foreach ($header->getAddresses() as $address) {
          $recipients[mb_strtolower($address->getAddress())] = TRUE;
        }
      }
    }
    foreach (array_keys($recipients) as $recipient) {
      if ($this->policy->decide($recipient, $source)->suppressed) {
        // Mailer Plus handles its supported cancellation exception. Do not log
        // addresses or duplicate a core-hook suppression message here.
        throw new SkipMailException('Mail suppressed by Postmark policy.');
      }
    }
  }

}
