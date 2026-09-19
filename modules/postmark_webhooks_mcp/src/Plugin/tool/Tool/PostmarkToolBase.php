<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_mcp\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mcp_sentinel\McpPolicyProfileInterface;
use Drupal\mcp_sentinel\Plugin\tool\Tool\McpEntityToolTrait;
use Drupal\mcp_sentinel\Plugin\tool\Tool\McpGovernedToolBase;
use Drupal\tool\ExecutableResult;

/**
 * Shares access, rate limiting and refusal handling for the read-only tools.
 *
 * This module's own refusals are one fixed message. Caller input, exception
 * text, mailboxes and secrets never reach a result or a log line.
 */
abstract class PostmarkToolBase extends McpGovernedToolBase {

  use McpEntityToolTrait;

  /**
   * Permission every tool in this module requires.
   */
  public const PERMISSION = 'use postmark webhooks mcp tools';

  /**
   * Largest JSON result a tool returns, in bytes.
   *
   * The resolved profile's response-size cap applies when it is lower.
   */
  protected const MAX_RESULT_BYTES = 131072;

  /**
   * Runs the read against the module's own service.
   *
   * @param array $values
   *   Validated input values.
   *
   * @return array
   *   Recipient-free, secret-free result.
   *
   * @throws \InvalidArgumentException
   *   When an input is not acceptable. The message is never relayed.
   */
  abstract protected function read(array $values): array;

  /**
   * Additional permissions a tool requires beyond the shared one.
   *
   * @return string[]
   *   Permission names.
   */
  protected function extraPermissions(): array {
    return [];
  }

  /**
   * Whether the services this tool reads are installed.
   */
  protected function available(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkGovernedDiscoveryAccess(AccountInterface $account): AccessResultInterface {
    return $this->checkGovernedAccess([], $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkGovernedAccess(array $values, AccountInterface $account): AccessResultInterface {
    if (!$this->available()) {
      return AccessResult::forbidden('The required module is not installed.')->setCacheMaxAge(0);
    }
    $access = AccessResult::allowedIfHasPermissions(
      $account,
      array_merge([self::PERMISSION], $this->extraPermissions()),
    );
    return $access->setCacheMaxAge(0);
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      // ToolBase::execute() does not call access(). Recheck for PHP callers.
      if (!$this->checkAccess($values, $this->currentUser)) {
        return $this->refused();
      }
      $profile = $this->governancePolicyResolver?->resolve($this->currentUser);
      if ($profile === NULL) {
        return $this->refused();
      }
      if ($limited = $this->checkRateLimit($profile, $this->getPluginId())) {
        return $limited;
      }
      $result = $this->read($values);
      if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > $this->resultLimit($profile)) {
        return $this->refused();
      }
      return ExecutableResult::success($this->t('Postmark read completed.'), $result);
    }
    catch (\Throwable $exception) {
      // Record the failure class only. Messages can carry caller input.
      $this->logger->warning('Postmark tool @tool failed with @type at @source:@line.', [
        '@tool' => $this->getPluginId(),
        '@type' => get_class($exception),
        '@source' => basename($exception->getFile()),
        '@line' => $exception->getLine(),
      ]);
      return $this->refused();
    }
  }

  /**
   * The smaller of this module's ceiling and the profile's response-size cap.
   */
  protected function resultLimit(McpPolicyProfileInterface $profile): int {
    $cap = \Drupal::hasService('mcp_sentinel.exfiltration_guard')
      ? (int) \Drupal::service('mcp_sentinel.exfiltration_guard')->effectiveResponseSizeCap($profile)
      : 0;
    return $cap > 0 ? min(static::MAX_RESULT_BYTES, $cap) : static::MAX_RESULT_BYTES;
  }

  /**
   * The refusal this module's own code returns.
   */
  protected function refused(): ExecutableResult {
    return ExecutableResult::failure($this->t('Postmark read refused. Check permissions, inputs and limits.'));
  }

}
