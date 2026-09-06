<?php

namespace Drupal\postmark_mailer_test;

use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Creates a capture-only transport with no network or delivery side effects.
 */
final class CaptureFactory extends AbstractTransportFactory {

  /**
   * {@inheritdoc}
   */
  public function create(Dsn $dsn): TransportInterface {
    return new CaptureTransport();
  }

  /**
   * {@inheritdoc}
   */
  protected function getSupportedSchemes(): array {
    return ['postmark-test'];
  }

}
