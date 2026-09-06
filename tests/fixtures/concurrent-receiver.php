<?php

/**
 * @file
 * Isolated receiver worker for the PostgreSQL concurrency regression.
 */

use Drupal\Component\Datetime\Time;
use Drupal\postmark_webhooks\Diagnostics\IntakeMetrics;
use Drupal\postmark_webhooks\Retention\EventRetention;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\postmark_webhooks\Suppression\SuppressionStore;
use Drupal\postmark_webhooks\Controller\PostmarkWebhookController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

$input = json_decode(fgets(STDIN), TRUE, 512, JSON_THROW_ON_ERROR);
$loader = require $input['root'] . '/autoload.php';
$loader->addPsr4('Drupal\\postmark_webhooks\\', dirname(__DIR__, 2) . '/src');
$loader->addPsr4('Drupal\\pgsql\\', $input['root'] . '/core/modules/pgsql/src');
Database::addConnectionInfo('default', 'default', $input['database']);
$database = Database::getConnection();
new Settings(['postmark_webhooks.webhook_secret' => 'concurrency-test']);
fwrite(STDOUT, "ready\n");
// Every worker waits until the parent has observed all ready signals.
fgets(STDIN);
if (isset($input['purge_before'])) {
  $retention = new EventRetention($database);
  fwrite(STDOUT, (string) $retention->purgeBefore($input['purge_before']));
  exit(0);
}
$request = Request::create('/api/webhooks/postmark', 'POST', [], [], [], [], json_encode([
  'RecordType' => 'Bounce',
  'Email' => 'concurrent@example.com',
  'ID' => $input['event_id'] ?? 123,
  'Type' => 'HardBounce',
]));
$request->headers->set('Authorization', 'Basic ' . base64_encode('postmark:concurrency-test'));
$controller = new PostmarkWebhookController($database, new Time(new RequestStack()), new SuppressionStore($database), new IntakeMetrics($database));
fwrite(STDOUT, (string) $controller->receive($request)->getStatusCode());
