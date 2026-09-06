<?php

namespace Drupal\postmark_webhooks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\postmark_webhooks\Event\EventIdentity;
use Drupal\postmark_webhooks\Source\SourcePolicy;
use Drupal\postmark_webhooks\Event\WebhookPayload;
use Drupal\postmark_webhooks\Event\EventTime;
use Drupal\postmark_webhooks\Suppression\SuppressionStore;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\postmark_webhooks\Authentication\WebhookCredentials;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives and logs Postmark bounce, spam, and delivery webhooks.
 *
 * Authentication is HTTP Basic Auth: Postmark is configured with a webhook URL
 * of the form `https://postmark:<secret>@host/api/webhooks/postmark` and sends
 * the credentials in the `Authorization` header — NOT in the URL path — so the
 * shared secret never lands in proxy / CDN / access logs (the weakness of the
 * previous `/api/webhooks/postmark/{secret}` scheme). Postmark does not offer
 * payload HMAC signing (no `X-Postmark-Signature`), so Basic Auth + HTTPS, plus
 * the network controls in front of this endpoint, is the supported mechanism.
 *
 * The configured `webhook_secret` is the Basic Auth *password*; the username is
 * not significant (Postmark requires one, conventionally `postmark`).
 */
class PostmarkWebhookController extends ControllerBase {

  /**
   * Constructs the webhook receiver.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected SuppressionStore $suppressionStore,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('database'), $container->get('datetime.time'), $container->get('postmark_webhooks.suppression_store'));
  }

  /**
   * Authenticates via HTTP Basic Auth, records the event, and responds.
   */
  public function receive(Request $request): Response {
    $credentials = WebhookCredentials::fromSettings();
    if (!$credentials->isConfigured()) {
      // Misconfiguration — refuse rather than accept unauthenticated posts.
      return new Response('Service Unavailable', 503);
    }

    $provided = $this->basicAuthPassword($request);
    if (!$credentials->accepts($provided, $this->time->getCurrentTime())) {
      return new Response('Unauthorized', 401, [
        'WWW-Authenticate' => 'Basic realm="postmark-webhook"',
      ]);
    }

    $body = stream_get_contents($request->getContent(TRUE), WebhookPayload::MAX_BYTES + 1);
    if ($body === FALSE || strlen($body) > WebhookPayload::MAX_BYTES) {
      return new Response('Payload Too Large', 413);
    }
    try {
      $data = WebhookPayload::decode($body);
      [$occurred, $time_basis] = EventTime::resolve($data, $this->time->getRequestTime());
    }
    catch (\JsonException | \InvalidArgumentException $exception) {
      return new Response('Bad Request', 400);
    }

    try {
      if (!SourcePolicy::permitsIntake($data)) {
        return new Response('Forbidden', 403);
      }
    }
    catch (\InvalidArgumentException $exception) {
      return new Response('Service Unavailable', 503);
    }

    // Normalize the recipient to lowercase so suppression lookups match
    // regardless of the case Postmark reports. On PostgreSQL an exact string
    // comparison is case-sensitive, so an unnormalized "John@Example.com" would
    // not match a later send to "john@example.com". The raw JSON body is not
    // stored: extracted columns are enough for suppression.
    $recipient = (string) ($data['Recipient'] ?? $data['Email'] ?? '');
    $recipient = mb_strtolower(trim($recipient));
    $message_id = (string) ($data['MessageID'] ?? '');

    $database = $this->database;
    $event_key = EventIdentity::key($data, $recipient);
    // Use a savepoint when called inside another transaction.
    // PostgreSQL must roll back a unique violation before any further query.
    $transaction = $database->startTransaction();
    try {
      $event = [
        'occurred' => $occurred,
        'time_basis' => $time_basis,
        'server_id' => (string) ($data['ServerID'] ?? ''),
        'message_stream' => $data['MessageStream'] ?? '',
        'event_key' => $event_key,
        'created' => $this->time->getRequestTime(),
        'event_type' => $data['RecordType'] ?? '',
        'message_id' => $message_id,
        'recipient' => $recipient,
        'bounce_type' => $data['Type'] ?? '',
        'description' => mb_substr($data['Description'] ?? $data['Name'] ?? '', 0, 512),
        'payload' => NULL,
      ];
      if ($event['event_type'] === 'SubscriptionChange') {
        $event['suppress_sending'] = (int) $data['SuppressSending'];
        $event['suppression_reason'] = $data['SuppressionReason'] ?? '';
        $event['origin'] = $data['Origin'];
      }
      $database->insert('postmark_events')->fields($event)->execute();
      $this->suppressionStore->record($event);
    }
    catch (IntegrityConstraintViolationException $exception) {
      $transaction->rollBack();
      // A different constraint failure is not a successful webhook delivery.
      $exists = $database->select('postmark_events', 'pe')
        ->fields('pe', ['eid'])
        ->condition('event_key', $event_key)
        ->execute()->fetchField();
      if (!$exists) {
        throw $exception;
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    unset($transaction);

    return new Response('OK', 200);
  }

  /**
   * Extracts the HTTP Basic Auth password from the request.
   *
   * Prefers the value PHP/Apache already parsed (`PHP_AUTH_PW`); falls back to
   * decoding the raw `Authorization` header when a FastCGI / proxy setup
   * doesn't split it.
   */
  private function basicAuthPassword(Request $request): ?string {
    $password = $request->getPassword();
    if ($password !== NULL && $password !== '') {
      return $password;
    }

    $header = (string) $request->headers->get('Authorization', '');
    if (stripos($header, 'Basic ') === 0) {
      $decoded = base64_decode(substr($header, 6), TRUE);
      if ($decoded !== FALSE && strpos($decoded, ':') !== FALSE) {
        [, $password] = explode(':', $decoded, 2);
        return $password;
      }
    }

    return NULL;
  }

}
