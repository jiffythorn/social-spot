<?php

/**
 * Router script for PHP's built-in web server.
 *
 * Friendica normally relies on an .htaccess rewrite to funnel every request through index.php.
 * The built-in server has no such rewrite, so this script does the same job: existing files
 * (CSS, JS, images, theme assets) are served directly by the server, and everything else is
 * handed to Friendica's front controller with REQUEST_URI untouched, which is what its
 * Arguments component needs in order to resolve the route.
 *
 * Usage (from tools/dev/serve.sh):
 *   php -S 127.0.0.1:8080 -t friendica tools/dev/router.php
 */

declare(strict_types=1);

$root = __DIR__ . '/../../friendica';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Block traversal and never expose the config directory, even in development.
$normalised = str_replace('\\', '/', $path);

if (str_contains($normalised, '..') || str_starts_with($normalised, '/config/')) {
	http_response_code(404);

	return true;
}

$candidate = $root . $normalised;

if ($normalised !== '/' && is_file($candidate)) {
	// Returning false lets the built-in server send the file itself, with the right MIME type.
	return false;
}

/*
 * Friendica does not read the path from REQUEST_URI. \Friendica\App\Arguments::determine()
 * takes the route from a `pagename` query parameter, which .htaccess supplies with
 *
 *     RewriteRule ^(.*)$ index.php?pagename=$1 [QSA,L]
 *
 * so the rewrite has to be emulated here or every request resolves to the default module and
 * the whole site renders the home page.
 */
// The built-in server does not populate QUERY_STRING when a request has no query, while a
// real server always does. Friendica reads it directly in places, so normalise it.
if (!array_key_exists('QUERY_STRING', $_SERVER)) {
	$_SERVER['QUERY_STRING'] = '';
}

/*
 * Friendica selects an alternative configuration directory through $_SERVER, not getenv(): its
 * Config factory reads $server['FRIENDICA_CONFIG_DIR'] and falls back to the default config/
 * when the key is absent.
 *
 * That matters because this host runs with variables_order=GPCS, the widely used default. With
 * no `E` in that list, environment variables are kept out of $_SERVER, so a FRIENDICA_CONFIG_DIR
 * exported by the shell reaches bin/console.php (the CLI builds $_SERVER from the environment
 * itself) but never reaches the web server. The result is a server that answers on one port
 * while quietly using the DEFAULT instance's database and base URL — which looks like it works
 * and is the worst kind of wrong: it sent a registration into the wrong database entirely.
 *
 * Bridging the variable here keeps both entry points in agreement, without touching php.ini and
 * without depending on how any particular host is configured.
 */
$configDir = getenv('FRIENDICA_CONFIG_DIR');
if (is_string($configDir) && $configDir !== '') {
	$_SERVER['FRIENDICA_CONFIG_DIR'] = $configDir;
}

$pagename = ltrim($normalised, '/');

$_GET['pagename']     = $pagename;
$_REQUEST['pagename'] = $pagename;

require $root . '/index.php';

return true;
