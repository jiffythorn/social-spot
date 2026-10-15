<?php

/**
 * Dispatches a single synthetic request through Friendica's front controller and prints the
 * result, with any exception shown in full.
 *
 * The web server masks internal errors behind "Friendica can't display this page at the moment"
 * for signed-out visitors, which makes debugging a broken instance awkward. This helper runs
 * the same code path with display_errors on, so the real exception and stack trace are visible.
 *
 * Usage:
 *   php tools/dev/debug-request.php /            # status line + first bytes of the body
 *   php tools/dev/debug-request.php /orgsuite    # any path
 *   php tools/dev/debug-request.php / --body     # print the whole body
 *
 * This is a development tool. It must never be deployed to a public host: it bypasses the
 * web server's error masking on purpose.
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = realpath(__DIR__ . '/../../friendica');

// Friendica resolves relative config paths (for example smarty3.config_dir) against the
// process working directory, and a real web server runs with its working directory set to
// the document root. Mirror that here, otherwise template compilation fails misleadingly.
chdir($root);

$path = $argv[1] ?? '/';
$showBody = in_array('--body', $argv, true);

$query = '';
if (str_contains($path, '?')) {
	[$path, $query] = explode('?', $path, 2);
}

$method  = getenv('DEBUG_METHOD') ?: 'GET';
$session = getenv('DEBUG_SESSION') ?: '';

$_SERVER = array_merge($_SERVER, [
	'REQUEST_URI'      => $path . ($query !== '' ? '?' . $query : ''),
	'REQUEST_METHOD'   => $method,
	'QUERY_STRING'     => $query,
	'HTTP_HOST'        => 'localhost:8080',
	'SERVER_NAME'      => 'localhost',
	'SERVER_PORT'      => '8080',
	'REMOTE_ADDR'      => '127.0.0.1',
	'SCRIPT_NAME'      => '/index.php',
	'SCRIPT_FILENAME'  => $root . '/index.php',
	'HTTP_USER_AGENT'  => 'debug-request',
	'HTTPS'            => '',
]);

if ($session !== '') {
	$_COOKIE['PHPSESSID'] = $session;
}

$_GET    = $query !== '' ? [] : [];
$_REQUEST = [];

if ($query !== '') {
	parse_str($query, $_GET);
	$_REQUEST = $_GET;
}

require $root . '/index.php';
