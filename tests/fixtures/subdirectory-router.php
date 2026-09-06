<?php

/**
 * @file
 * Front-controller routing for the disposable subdirectory HTTP fixture only.
 */

$root = getenv('POSTMARK_TEST_WEBROOT');
$prefix = '/subdirectory';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (PHP_SAPI !== 'cli-server' || !$root || !str_starts_with($path, $prefix . '/')) {
  http_response_code(404);
  exit;
}
$relative = substr($path, strlen($prefix));
$file = realpath($root . $relative);
if ($file && str_starts_with($file, realpath($root) . '/') && is_file($file)) {
  return FALSE;
}
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['SCRIPT_NAME'] = $prefix . '/index.php';
$_SERVER['PHP_SELF'] = $prefix . '/index.php';
chdir($root);
require $_SERVER['SCRIPT_FILENAME'];
