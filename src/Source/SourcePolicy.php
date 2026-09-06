<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Source;

use Drupal\Core\Site\Settings;

/**
 * Explicit source mapping and settings-bound intake restrictions.
 *
 * @internal
 */
final class SourcePolicy {

  /**
   * Validates configuration mappings, including duplicate source ambiguity.
   */
  public static function validate(mixed $mappings): array {
    if ($mappings === NULL) {
      return [];
    }
    if (!is_array($mappings) || !array_is_list($mappings)) {
      throw new \InvalidArgumentException('Invalid source policy list.');
    }
    $seen = [];
    foreach ($mappings as $mapping) {
      if (!is_array($mapping) || !is_string($mapping['server_id'] ?? NULL)
        || !is_string($mapping['message_stream'] ?? NULL)
        || !in_array($mapping['scope'] ?? NULL, ['global', 'source'], TRUE)) {
        throw new \InvalidArgumentException('Invalid source policy mapping.');
      }
      new SourceContext($mapping['server_id'], $mapping['message_stream']);
      $key = json_encode([$mapping['server_id'], $mapping['message_stream']], JSON_THROW_ON_ERROR);
      if (isset($seen[$key])) {
        throw new \InvalidArgumentException('Duplicate source policy mapping.');
      }
      $seen[$key] = TRUE;
    }
    return $mappings;
  }

  /**
   * Whether evidence applies; missing sending context remains conservative.
   */
  public static function applies(object $row, ?SourceContext $context, array $mappings): bool {
    if ($context === NULL) {
      return TRUE;
    }
    foreach ($mappings as $mapping) {
      if ($row->server_id === $mapping['server_id'] && $row->message_stream === $mapping['message_stream']) {
        return $mapping['scope'] === 'global'
          || ($context->serverId === $row->server_id && $context->messageStream === $row->message_stream);
      }
    }
    // Legacy and unknown sources retain the backward-compatible global rule.
    return TRUE;
  }

  /**
   * Checks authenticated intake against the optional settings source allowlist.
   *
   * An invalid configured allowlist fails closed with a configuration error.
   */
  public static function permitsIntake(array $data): bool {
    $allowed = Settings::get('postmark_webhooks.allowed_sources');
    if ($allowed === NULL) {
      return TRUE;
    }
    if (!is_array($allowed) || !array_is_list($allowed)) {
      throw new \InvalidArgumentException('Invalid source allowlist.');
    }
    $match = FALSE;
    foreach ($allowed as $source) {
      if (!is_array($source) || (!is_string($source['server_id'] ?? NULL) && !is_int($source['server_id'] ?? NULL))
        || !is_string($source['message_stream'] ?? NULL)) {
        throw new \InvalidArgumentException('Invalid allowed source.');
      }
      $source['server_id'] = (string) $source['server_id'];
      new SourceContext($source['server_id'], $source['message_stream']);
      $match = $match || ($source['server_id'] === (string) ($data['ServerID'] ?? '')
        && $source['message_stream'] === ($data['MessageStream'] ?? ''));
    }
    return $match;
  }

}
