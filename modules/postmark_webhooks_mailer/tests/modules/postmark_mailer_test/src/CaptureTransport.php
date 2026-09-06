<?php

namespace Drupal\postmark_mailer_test;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Captures actual transport calls after all Mailer Plus processing.
 */
final class CaptureTransport extends AbstractTransport {

  /**
   * {@inheritdoc}
   */
  protected function doSend(SentMessage $message): void {
    $state = \Drupal::state();
    $messages = $state->get('postmark_mailer_test.messages', []);
    $messages[] = $message->getOriginalMessage();
    $state->set('postmark_mailer_test.messages', $messages);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return 'postmark-test://default';
  }

}
