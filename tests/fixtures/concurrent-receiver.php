<?php

/**
 * @file
 * Isolated receiver worker for the PostgreSQL concurrency regression.
 */

use Drupal\Component\Datetime\Time;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
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
// Both workers wait until the parent has observed both ready signals.
fgets(STDIN);
$request = Request::create('/api/webhooks/postmark', 'POST', [], [], [], [], json_encode([
  'RecordType' => 'Bounce',
  'Email' => 'concurrent@example.com',
  'ID' => 123,
]));
$request->headers->set('Authorization', 'Basic ' . base64_encode('postmark:concurrency-test'));
$controller = new PostmarkWebhookController($database, new Time(new RequestStack()));
fwrite(STDOUT, (string) $controller->receive($request)->getStatusCode());
