<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Authentication;

use Drupal\Core\Site\Settings;
use Drupal\postmark_webhooks\Source\SourceContext;

/**
 * Validates settings-only Basic Auth credentials during secret rotation.
 *
 * @internal
 */
final class WebhookCredentials {

  /**
   * Sentinel for a request authenticated with the shared site secret.
   */
  private const LEGACY = '';

  /**
   * Profile id that accepted the last request, or NULL for the shared secret.
   */
  private ?string $acceptedProfile = NULL;

  /**
   * Whether accepts() authenticated a named profile.
   */
  private bool $acceptedViaProfile = FALSE;

  /**
   * Constructs an immutable snapshot of the configured credentials.
   *
   * @param mixed $active
   *   Shared active secret from settings.
   * @param mixed $previous
   *   Shared previous secret setting.
   * @param array $profiles
   *   Parsed named profiles. Empty when unused or malformed.
   * @param bool $profilesMalformed
   *   TRUE when source_profiles is present but invalid.
   * @param bool $usesProfiles
   *   TRUE when source_profiles is set in settings.
   */
  private function __construct(
    private readonly mixed $active,
    private readonly mixed $previous,
    private readonly array $profiles,
    private readonly bool $profilesMalformed,
    private readonly bool $usesProfiles,
  ) {}

  /**
   * Reads credentials exclusively from settings, never exported configuration.
   */
  public static function fromSettings(): self {
    $raw = Settings::get('postmark_webhooks.source_profiles');
    $malformed = FALSE;
    $profiles = [];
    $uses = $raw !== NULL;
    if ($uses) {
      try {
        $profiles = self::parseProfiles($raw);
      }
      catch (\InvalidArgumentException $exception) {
        $malformed = TRUE;
      }
    }
    return new self(
      Settings::get('postmark_webhooks.webhook_secret'),
      Settings::get('postmark_webhooks.previous_webhook_secret'),
      $profiles,
      $malformed,
      $uses,
    );
  }

  /**
   * Whether optional named source profiles are configured.
   */
  public function usesProfiles(): bool {
    return $this->usesProfiles && !$this->profilesMalformed;
  }

  /**
   * Named profile that authenticated the request, or NULL if shared.
   */
  public function acceptedProfile(): ?string {
    return $this->acceptedViaProfile ? $this->acceptedProfile : NULL;
  }

  /**
   * Whether the required active credential is configured correctly.
   */
  public function isConfigured(): bool {
    if ($this->profilesMalformed) {
      return FALSE;
    }
    if ($this->usableProfileCount() > 0) {
      return TRUE;
    }
    return is_string($this->active) && $this->active !== '';
  }

  /**
   * Returns the soonest still-active previous-secret expiry, or NULL.
   */
  public function previousExpiresAt(int $now): ?int {
    $times = [];
    if ($this->usableProfileCount() === 0) {
      $global = $this->globalPreviousExpiresAt();
      if ($global !== NULL && $now < $global) {
        $times[] = $global;
      }
    }
    foreach ($this->acceptedProfiles() as $profile) {
      if ($profile['previous'] !== NULL && $now < $profile['previous']['expires']) {
        $times[] = $profile['previous']['expires'];
      }
    }
    return $times ? min($times) : NULL;
  }

  /**
   * Returns readiness without exposing either credential or its expiry.
   *
   * Reports only previous secrets the endpoint currently accepts. A malformed
   * source_profiles map is source_profiles.malformed, not this status.
   */
  public function rotationStatus(int $now): string {
    $statuses = [];
    if ($this->usableProfileCount() === 0) {
      $statuses[] = $this->globalRotationStatus($now);
    }
    foreach ($this->acceptedProfiles() as $profile) {
      $statuses[] = $this->profileRotationStatus($profile, $now);
    }
    if ($statuses === []) {
      return 'absent';
    }
    if (in_array('invalid', $statuses, TRUE)) {
      return 'invalid';
    }
    if (in_array('active', $statuses, TRUE)) {
      return 'active';
    }
    if (in_array('expired', $statuses, TRUE)) {
      return 'expired';
    }
    return 'absent';
  }

  /**
   * Privacy-safe profile list for diagnostics. Contains no secrets.
   */
  public function profileDiagnostics(int $now): array {
    $revoked = 0;
    $rows = [];
    foreach ($this->profiles as $profile) {
      if ($profile['revoked']) {
        $revoked++;
      }
      $rows[] = [
        'id' => $profile['id'],
        'revoked' => $profile['revoked'],
        'rotation' => $this->profileRotationStatus($profile, $now),
      ];
    }
    return [
      'enabled' => $this->usesProfiles(),
      'malformed' => $this->profilesMalformed,
      'count' => count($this->profiles),
      'revoked' => $revoked,
      'profiles' => $rows,
    ];
  }

  /**
   * Compares credentials and accepts a previous one only before expiry.
   */
  public function accepts(?string $provided, int $now): bool {
    $this->acceptedProfile = NULL;
    $this->acceptedViaProfile = FALSE;
    if ($this->profilesMalformed || !$this->isConfigured() || $provided === NULL) {
      return FALSE;
    }
    $hits = [];
    foreach ($this->profiles as $profile) {
      $active_match = $profile['secret'] !== '' && hash_equals($profile['secret'], $provided);
      $previous_match = $profile['previous'] !== NULL
        && hash_equals($profile['previous']['secret'], $provided)
        && $now < $profile['previous']['expires'];
      if ($profile['revoked'] || $profile['secret'] === '') {
        continue;
      }
      if ($active_match || $previous_match) {
        $hits[] = $profile['id'];
      }
    }
    $rotation = $this->globalRotationStatus($now);
    $active = is_string($this->active) ? $this->active : '';
    $previous = $this->globalPreviousSecret();
    $active_match = $active !== '' && hash_equals($active, $provided);
    $previous_match = $previous !== '' && hash_equals($previous, $provided);
    // A usable profile disables the shared secret so one credential cannot
    // post another profile's sources. The shared secret remains the
    // migration path when no profile is usable.
    if ($this->usableProfileCount() === 0 && $active !== ''
      && ($active_match || ($rotation === 'active' && $previous_match))) {
      $hits[] = self::LEGACY;
    }
    if (count($hits) !== 1) {
      return FALSE;
    }
    if ($hits[0] === self::LEGACY) {
      return TRUE;
    }
    $this->acceptedViaProfile = TRUE;
    $this->acceptedProfile = $hits[0];
    return TRUE;
  }

  /**
   * Whether the authenticated profile may store this payload.
   *
   * Source-less provider bodies are rejected. Call after accepts().
   */
  public function profileAllows(array $data): bool {
    if (!$this->acceptedViaProfile || $this->acceptedProfile === NULL) {
      return FALSE;
    }
    $server = $data['ServerID'] ?? '';
    $stream = $data['MessageStream'] ?? '';
    if ((!is_string($server) && !is_int($server)) || !is_string($stream)
      || $server === '' || $stream === '') {
      return FALSE;
    }
    $server = (string) $server;
    foreach ($this->profiles as $profile) {
      if ($profile['id'] !== $this->acceptedProfile) {
        continue;
      }
      foreach ($profile['sources'] as $source) {
        if ($source['server_id'] === $server && $source['message_stream'] === $stream) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Parses named source profiles or throws.
   *
   * @return array
   *   Normalized profiles with id, secret, previous, sources and revoked.
   */
  private static function parseProfiles(mixed $raw): array {
    if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
      throw new \InvalidArgumentException('Invalid source profiles.');
    }
    $profiles = [];
    $secrets = [];
    $bindings = [];
    foreach ($raw as $id => $profile) {
      if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $id)
        || !is_array($profile)) {
        throw new \InvalidArgumentException('Invalid source profile id.');
      }
      if (!is_string($profile['secret'] ?? NULL)
        || str_contains($profile['secret'], "\0")) {
        throw new \InvalidArgumentException('Invalid source profile secret.');
      }
      $revoked = $profile['revoked'] ?? FALSE;
      if (!is_bool($revoked)) {
        throw new \InvalidArgumentException('Invalid source profile revocation.');
      }
      $previous = self::parsePrevious($profile['previous'] ?? NULL);
      $sources = self::parseSources($profile['sources'] ?? NULL);
      foreach ([$profile['secret'], $previous['secret'] ?? ''] as $secret) {
        if ($secret === '') {
          continue;
        }
        if (isset($secrets[$secret])) {
          throw new \InvalidArgumentException('Duplicate source profile secret.');
        }
        $secrets[$secret] = TRUE;
      }
      foreach ($sources as $source) {
        $key = $source['server_id'] . "\0" . $source['message_stream'];
        if (isset($bindings[$key])) {
          throw new \InvalidArgumentException('Duplicate source profile binding.');
        }
        $bindings[$key] = TRUE;
      }
      $profiles[] = [
        'id' => $id,
        'secret' => $profile['secret'],
        'previous' => $previous,
        'sources' => $sources,
        'revoked' => $revoked,
      ];
    }
    return $profiles;
  }

  /**
   * Parses one profile previous secret, or NULL when omitted.
   *
   * @return array|null
   *   Secret and integer expiry, or NULL when omitted.
   */
  private static function parsePrevious(mixed $previous): ?array {
    if ($previous === NULL) {
      return NULL;
    }
    if (!is_array($previous)) {
      throw new \InvalidArgumentException('Invalid source profile previous secret.');
    }
    $secret = $previous['secret'] ?? NULL;
    if ($secret === NULL || $secret === '') {
      return NULL;
    }
    if (!is_string($secret) || str_contains($secret, "\0")
      || !is_int($previous['expires'] ?? NULL)
      || $previous['expires'] <= 0) {
      throw new \InvalidArgumentException('Invalid source profile previous secret.');
    }
    return [
      'secret' => $previous['secret'],
      'expires' => $previous['expires'],
    ];
  }

  /**
   * Parses required source bindings for one profile.
   *
   * @return array
   *   List of server_id and message_stream pairs.
   */
  private static function parseSources(mixed $sources): array {
    if (!is_array($sources) || $sources === [] || !array_is_list($sources)) {
      throw new \InvalidArgumentException('Invalid source profile bindings.');
    }
    $parsed = [];
    foreach ($sources as $source) {
      if (!is_array($source)
        || (!is_string($source['server_id'] ?? NULL) && !is_int($source['server_id'] ?? NULL))
        || !is_string($source['message_stream'] ?? NULL)) {
        throw new \InvalidArgumentException('Invalid source profile binding.');
      }
      $server_id = (string) $source['server_id'];
      new SourceContext($server_id, $source['message_stream']);
      $parsed[] = [
        'server_id' => $server_id,
        'message_stream' => $source['message_stream'],
      ];
    }
    return $parsed;
  }

  /**
   * Usable (non-revoked, non-empty secret) profile count.
   */
  private function usableProfileCount(): int {
    return count($this->acceptedProfiles());
  }

  /**
   * Profiles whose credentials the controller will currently accept.
   */
  private function acceptedProfiles(): array {
    $accepted = [];
    foreach ($this->profiles as $profile) {
      if (!$profile['revoked'] && $profile['secret'] !== '') {
        $accepted[] = $profile;
      }
    }
    return $accepted;
  }

  /**
   * Shared-secret previous expiry only.
   */
  private function globalPreviousExpiresAt(): ?int {
    if (!is_array($this->previous)
      || !is_string($this->previous['secret'] ?? NULL)
      || $this->previous['secret'] === ''
      || !is_int($this->previous['expires'] ?? NULL)
      || $this->previous['expires'] <= 0) {
      return NULL;
    }
    return $this->previous['expires'];
  }

  /**
   * Shared-secret previous value, or empty when unusable.
   */
  private function globalPreviousSecret(): string {
    return $this->globalPreviousExpiresAt() === NULL ? '' : $this->previous['secret'];
  }

  /**
   * Shared-secret rotation status.
   */
  private function globalRotationStatus(int $now): string {
    if ($this->previous === NULL) {
      return 'absent';
    }
    $expires = $this->globalPreviousExpiresAt();
    if ($expires === NULL) {
      return 'invalid';
    }
    return $now < $expires ? 'active' : 'expired';
  }

  /**
   * One profile's previous-secret status. Revoked profiles stay inspectable.
   */
  private function profileRotationStatus(array $profile, int $now): string {
    if ($profile['previous'] === NULL) {
      return 'absent';
    }
    return $now < $profile['previous']['expires'] ? 'active' : 'expired';
  }

}
