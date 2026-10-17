<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Outgoing mail for the organization suite.
 *
 * Friendica core sends every account email (approval notices, password resets,
 * admin notices) through PHP's `mail()`, which hands the message to
 * `/usr/sbin/sendmail`. The server image ships no mail transfer agent at all,
 * so that call fails and the message never leaves.
 *
 * Installing a full mail server per club makes deliverability worse, not better:
 * a fresh residential or office IP has no reputation, no SPF/DKIM, and port 25
 * is blocked in many places, so providers junk or reject direct mail.
 *
 * The supported path is therefore a relay: the club reuses a mailbox it already
 * owns (Google Workspace, Outlook, the HOA's own mail) and this class delivers
 * through it over authenticated SMTP. The settings live in the officer console
 * (Organization settings -> Email), because the address is only known once a
 * club adopts the instance - never at install time.
 *
 * Delivery uses a plain-socket SMTP client with no extra dependencies, so the
 * image needs no MTA and the addon stays self-contained. When nothing is
 * configured this class reports itself as such and callers fall back to core's
 * behaviour (which fails loudly in the logs) rather than pretending mail works.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\DI;

final class OrgMail
{
	public const SECURITY_NONE = 'none';
	public const SECURITY_STARTTLS = 'tls';
	public const SECURITY_SSL = 'ssl';

	/** @return array{from: string, host: string, port: int, user: string, security: string, configured: bool, has_password: bool} */
	public static function get(): array
	{
		$from = trim((string) OrgConfig::get('mail_from', ''));
		$host = trim((string) OrgConfig::get('mail_host', ''));
		$port = (int) OrgConfig::get('mail_port', 587);
		$user = trim((string) OrgConfig::get('mail_user', ''));
		$security = (string) OrgConfig::get('mail_security', self::SECURITY_STARTTLS);

		if (!in_array($security, [self::SECURITY_NONE, self::SECURITY_STARTTLS, self::SECURITY_SSL], true)) {
			$security = self::SECURITY_STARTTLS;
		}

		if ($port <= 0 || $port > 65535) {
			$port = 587;
		}

		// Fall back to the node's sender address so an officer only has to fill
		// in the relay itself when the site already names its From address.
		if ($from === '') {
			$from = trim((string) DI::config()->get('config', 'sender_email', ''));
		}

		$password = (string) OrgConfig::get('mail_pass', '');

		return [
			'from'         => $from,
			'host'         => $host,
			'port'         => $port,
			'user'         => $user,
			'security'     => $security,
			'configured'   => $from !== '' && $host !== '',
			'has_password' => $password !== '',
		];
	}

	public static function isConfigured(): bool
	{
		return self::get()['configured'];
	}

	/**
	 * Saves the relay settings. An empty password keeps the stored one, so the
	 * form never has to echo the secret back to the browser.
	 */
	public static function save(string $from, string $host, int $port, string $user, string $password, string $security): void
	{
		OrgConfig::set('mail_from', $from);

		OrgConfig::set('mail_host', $host);
		OrgConfig::set('mail_port', ($port > 0 && $port <= 65535) ? $port : 587);
		OrgConfig::set('mail_user', $user);

		if ($password !== '') {
			OrgConfig::set('mail_pass', $password);
		}

		if (!in_array($security, [self::SECURITY_NONE, self::SECURITY_STARTTLS, self::SECURITY_SSL], true)) {
			$security = self::SECURITY_STARTTLS;
		}

		OrgConfig::set('mail_security', $security);
	}

	public static function clearPassword(): void
	{
		OrgConfig::delete('mail_pass');
	}

	/**
	 * Sends one message through the configured relay.
	 *
	 * @param string $to      recipient address (core always passes a single address here)
	 * @param string $subject already-encoded subject line
	 * @param string $body    full MIME body core built
	 * @param string $headers full header block core built (includes From, may include Cc)
	 * @param string|null $error filled in when sending fails, for the console notice
	 */
	public static function send(string $to, string $subject, string $body, string $headers, ?string &$error = null): bool
	{
		$settings = self::get();

		if (!$settings['configured']) {
			$error = 'Email is not configured yet. Fill in the relay under Organization settings -> Email.';

			return false;
		}

		$password = (string) OrgConfig::get('mail_pass', '');
		$from = self::addressFromHeaders($headers) ?? $settings['from'];

		if (!self::validAddress($to) || !self::validAddress($from)) {
			$error = 'The sender or recipient address looks invalid. Check the From address in Email settings.';

			return false;
		}

		// Core's header block already carries From/MIME/Message-ID; the envelope
		// only needs From + To + Subject + the rest. Subject arrives separately
		// from the headers, so add it when the block does not already name one.
		$message = rtrim($headers, "\r\n") . "\r\n";

		if (stripos($headers, 'Subject:') === false) {
			$message .= 'Subject: ' . $subject . "\r\n";
		}

		$message .= 'To: ' . $to . "\r\n";
		$message .= "\r\n" . ltrim($body, "\r\n");

		$transportError = null;

		if (!self::transmit($settings['host'], $settings['port'], $settings['security'], $settings['user'], $password, $from, $to, $message, $transportError)) {
			$error = $transportError ?? 'The mail server refused the message. Check the host, port, user and password.';

			DI::logger()->notice('OrgSuite: SMTP send failed', ['error' => $error]);

			return false;
		}

		return true;
	}

	/**
	 * Sends a labelled test message. Used by the console's "send a test" button
	 * so an officer knows the relay works before members depend on it.
	 */
	public static function sendTest(string $to, ?string &$error = null): bool
	{
		$settings = self::get();
		$site = OrgConfig::getOrgName() !== '' ? OrgConfig::getOrgName() : 'Social Spot';

		$subject = 'Test email from ' . $site;
		$body = "This is a test from your site.\r\n\r\nIf you can read this, password resets and approval notices will reach members too.\r\n";
		$headers = 'From: ' . $settings['from'] . "\r\n"
			. 'MIME-Version: 1.0' . "\r\n"
			. 'Content-Type: text/plain; charset=UTF-8' . "\r\n";

		return self::send($to, $subject, $body, $headers, $error);
	}

	/** Prefers the From core already chose; falls back to the configured one. */
	private static function addressFromHeaders(string $headers): ?string
	{
		if (preg_match('/^From:\s*(?:.*<([^>]+)>|([^\s<>]+@[^\s<>]+))/mi', $headers, $match) === 1) {
			$candidate = trim($match[1] !== '' ? $match[1] : $match[2]);

			return self::validAddress($candidate) ? $candidate : null;
		}

		return null;
	}

	private static function validAddress(string $address): bool
	{
		return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
	}

	/**
	 * Minimal SMTP client: EHLO, optional STARTTLS/SSL, AUTH LOGIN when a user is
	 * set, then MAIL/RCPT/DATA/QUIT. Timeouts are short on purpose - a hung relay
	 * must not hang the page that triggered the email.
	 */
	private static function transmit(string $host, int $port, string $security, string $user, string $password, string $from, string $to, string $message, ?string &$error): bool
	{
		$target = ($security === self::SECURITY_SSL ? 'ssl://' : '') . $host . ':' . $port;

		$socket = @stream_socket_client($target, $errno, $errstr, 10);

		if ($socket === false) {
			$error = 'Could not reach ' . $host . ':' . $port . ' (' . trim($errstr !== '' ? $errstr : 'connection failed') . ').';

			return false;
		}

		stream_set_timeout($socket, 10);

		$read = static function () use ($socket): array {
			$response = '';

			while (($line = fgets($socket, 512)) !== false) {
				$response .= $line;

				// Multi-line replies use "250-..." until the final "250 ...".
				if (strlen($line) >= 4 && $line[3] === ' ') {
					break;
				}
			}

			return [(int) substr($response, 0, 3), $response];
		};

		$command = static function (string $line, int ...$expect) use ($socket, $read, &$error): bool {
			fwrite($socket, $line . "\r\n");
			[$code] = $read();

			if (!in_array($code, $expect, true)) {
				$error = 'The mail server rejected "' . $line . '" (code ' . $code . ').';

				return false;
			}

			return true;
		};

		[$greeting] = $read();

		if ($greeting < 200 || $greeting >= 400) {
			$error = 'The mail server did not greet us (code ' . $greeting . ').';
			fclose($socket);

			return false;
		}

		$local = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');

		if (!$command('EHLO ' . $local, 250)) {
			fclose($socket);

			return false;
		}

		if ($security === self::SECURITY_STARTTLS) {
			if (!$command('STARTTLS', 220)) {
				$error = ($error ?? 'STARTTLS failed.') . ' If your provider uses plain port 25 or SSL on 465, change "Connection" below.';
				fclose($socket);

				return false;
			}

			if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
				$error = 'Could not start an encrypted connection to ' . $host . '.';
				fclose($socket);

				return false;
			}

			if (!$command('EHLO ' . $local, 250)) {
				fclose($socket);

				return false;
			}
		}

		if ($user !== '') {
			if (!$command('AUTH LOGIN', 334)) {
				fclose($socket);

				return false;
			}

			if (!$command(base64_encode($user), 334)) {
				$error = 'The mail server rejected the username. Check it in Email settings.';
				fclose($socket);

				return false;
			}

			if (!$command(base64_encode($password), 235)) {
				$error = 'The mail server rejected the password. Check it in Email settings (for Gmail/Outlook this is an app password, not the login password).';
				fclose($socket);

				return false;
			}
		}

		$ok = $command('MAIL FROM:<' . $from . '>', 250)
			&& $command('RCPT TO:<' . $to . '>', 250, 251)
			&& $command('DATA', 354);

		if ($ok) {
			// Dot-stuffing: a line starting with "." ends the message, so escape it.
			$stuffed = preg_replace('/^\./m', '..', $message) ?? $message;
			fwrite($socket, $stuffed . "\r\n.\r\n");
			[$code] = $read();

			$ok = $code >= 200 && $code < 400;

			if (!$ok) {
				$error = 'The mail server refused the message (code ' . $code . '). It may dislike the From address.';
			}
		}

		// QUIT is best-effort: the message already went or failed by now.
		@fwrite($socket, "QUIT\r\n");
		@fclose($socket);

		return $ok;
	}
}
