<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_reconcile;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\postmark_webhooks\Event\EventIdentity;
use Drupal\postmark_webhooks\Event\EventTime;
use Drupal\postmark_webhooks\Event\WebhookPayload;
use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\postmark_webhooks\Source\SourcePolicy;
use GuzzleHttp\ClientInterface;

/**
 * Reads only documented GET endpoints, with bounded bodies and no redirects.
 */
final class ProviderReader {

  public const MAX_BYTES = 4194304;

  /**
   * Constructs the provider reader.
   */
  public function __construct(private readonly ClientInterface $http, private readonly TimeInterface $time) {}

  /**
   * Fetches and validates one documented inclusive date-filtered dump.
   */
  public function dump(SourceContext $source, string $date): array {
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > gmdate('Y-m-d', $this->time->getCurrentTime())) {
      throw new \InvalidArgumentException('Use a valid UTC date no later than today.');
    }
    $tokens = Settings::get('postmark_webhooks.reconciliation_tokens', []);
    $token = is_array($tokens) ? ($tokens[$source->serverId] ?? NULL) : NULL;
    if (!is_string($token) || $token === '') {
      throw new ProviderReadException('No valid settings-only token is configured for this server.');
    }
    if (!SourcePolicy::permitsIntake(['ServerID' => $source->serverId, 'MessageStream' => $source->messageStream])) {
      throw new ProviderReadException('This source is not allowed by the intake policy.');
    }
    // Verify that the configured token belongs to the claimed server before
    // interpreting recipient evidence as belonging to that source.
    $server = $this->get('/server', $token);
    if ((!is_string($server['ID'] ?? NULL) && !is_int($server['ID'] ?? NULL))
      || (string) $server['ID'] !== $source->serverId) {
      throw new ProviderReadException('The API token does not match the requested server.');
    }
    $path = '/message-streams/' . rawurlencode($source->messageStream) . '/suppressions/dump';
    $data = $this->get($path, $token, ['fromdate' => $date, 'todate' => $date]);
    if (!isset($data['Suppressions']) || !is_array($data['Suppressions']) || !array_is_list($data['Suppressions']) || count($data['Suppressions']) > 10000) {
      throw new ProviderReadException('The provider dump is invalid or exceeds 10000 records.');
    }
    $events = [];
    foreach ($data['Suppressions'] as $row) {
      if (!is_array($row)) {
        throw new ProviderReadException('The provider returned an invalid suppression record.');
      }
      try {
        $payload = WebhookPayload::decode(json_encode([
          'RecordType' => 'SubscriptionChange',
          'Email' => $row['EmailAddress'] ?? NULL,
          'ServerID' => $source->serverId,
          'MessageStream' => $source->messageStream,
          'SuppressSending' => TRUE,
          'SuppressionReason' => $row['SuppressionReason'] ?? NULL,
          'Origin' => $row['Origin'] ?? NULL,
          'ChangedAt' => $row['CreatedAt'] ?? NULL,
        ], JSON_THROW_ON_ERROR));
        [$occurred, $basis] = EventTime::resolve($payload, $this->time->getCurrentTime());
      }
      catch (\JsonException | \InvalidArgumentException $exception) {
        throw new ProviderReadException('The provider returned an invalid suppression record.');
      }
      $recipient = mb_strtolower(trim($payload['Email']));
      $key = EventIdentity::key($payload, $recipient);
      $events[$key] = [
        'event_type' => 'SubscriptionChange',
        'suppress_sending' => 1,
        'suppression_reason' => $payload['SuppressionReason'],
        'recipient' => $recipient,
        'server_id' => $source->serverId,
        'message_stream' => $source->messageStream,
        'occurred' => $occurred,
        'time_basis' => $basis,
        'created' => $this->time->getCurrentTime(),
        'event_key' => $key,
      ];
    }
    ksort($events);
    return array_values($events);
  }

  /**
   * Issues a fixed-host GET and never returns remote error content.
   */
  private function get(string $path, #[\SensitiveParameter] string $token, array $query = []): array {
    try {
      $response = $this->http->request('GET', 'https://api.postmarkapp.com' . $path, [
        'headers' => ['Accept' => 'application/json', 'X-Postmark-Server-Token' => $token],
        'query' => $query,
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
        'verify' => TRUE,
        'connect_timeout' => 5,
        'timeout' => 15,
        'read_timeout' => 15,
        'stream' => TRUE,
      ]);
    }
    catch (\Throwable $exception) {
      // Client exceptions can embed request headers. Do not chain or log them.
      throw new ProviderReadException('The provider request failed; retry without advancing the checkpoint.');
    }
    $body = $response->getBody();
    try {
      if ($response->getStatusCode() === 429) {
        $retry = $response->getHeaderLine('Retry-After');
        throw new ProviderReadException('Provider rate limit; retry without advancing the checkpoint.', ctype_digit($retry) ? min(3600, (int) $retry) : 60);
      }
      if ($response->getStatusCode() !== 200) {
        throw new ProviderReadException('Provider GET failed with HTTP ' . $response->getStatusCode() . '.');
      }
      $json = '';
      while (!$body->eof() && strlen($json) <= self::MAX_BYTES) {
        $chunk = $body->read(min(65536, self::MAX_BYTES + 1 - strlen($json)));
        if ($chunk === '' && !$body->eof()) {
          throw new ProviderReadException('The provider response stopped before completion.');
        }
        $json .= $chunk;
      }
      if (strlen($json) > self::MAX_BYTES) {
        throw new ProviderReadException('The provider dump exceeds the 4 MiB safety bound; no records were applied.');
      }
      $data = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
      if (!is_array($data)) {
        throw new ProviderReadException('The provider response is not a JSON object.');
      }
      return $data;
    }
    catch (ProviderReadException $exception) {
      throw $exception;
    }
    catch (\Throwable $exception) {
      throw new ProviderReadException('The provider response could not be read safely.');
    }
    finally {
      $body->close();
    }
  }

}
