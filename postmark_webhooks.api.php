<?php

/**
 * @file
 * Hooks for Postmark Webhooks integrations.
 */

/**
 * Reacts to a health alert or recovery. Do not send mail from this module.
 *
 * @param array $alert
 *   Privacy-safe payload with event, severity, fingerprint and checks.
 */
function hook_postmark_webhooks_health_alert(array $alert): void {
  // Forward to an existing notifier. Never log recipients or secrets.
}

/**
 * Optionally reports provider delivery status.
 *
 * @return array
 *   Either an empty array or ['status' => 'paused'|'available'|'error'].
 */
function hook_postmark_webhooks_provider_health(): array {
  return [];
}
