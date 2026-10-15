<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Small text and money helpers shared by the organization suite.
 *
 * The credential normaliser is deliberately the single place where "Unit 4B", "apt. 4-b"
 * and "4b" are collapsed to the same value, so a roster import and a sign-up field can
 * never disagree about what counts as a match.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

final class Text
{
	/**
	 * Collapses a unit number, address or membership ID to a comparison key.
	 *
	 * Examples:
	 *   "Unit 4B"        => "4b"
	 *   "Apt. 12-C"      => "12c"
	 *   "Member #109"    => "member109"
	 *   "123 Sunset Rd." => "123sunsethillrd" (word order preserved, punctuation dropped)
	 */
	public static function normalizeCredential(string $value): string
	{
		$value = mb_strtolower(trim($value));

		if ($value === '') {
			return '';
		}

		// Punctuation and separators become single spaces so "4-B" and "4 B" agree.
		$value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';
		$value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

		// Drop a leading designator word only when something follows it ("unit 4b" => "4b").
		$value = preg_replace('/^(unit|apt|apartment|suite|ste|lot|no|number)\s+(?=.)/u', '', $value) ?? $value;

		return str_replace(' ', '', $value);
	}

	/**
	 * Character-safe truncation for directory cards and table cells.
	 */
	public static function excerpt(string $value, int $length = 120): string
	{
		$value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

		if (mb_strlen($value) <= $length) {
			return $value;
		}

		return mb_substr($value, 0, $length - 1) . "\u{2026}";
	}

	/**
	 * Formats a cent amount as a plain decimal string with a currency symbol.
	 */
	public static function formatMoney(int $cents, string $currency = '$'): string
	{
		$sign   = $cents < 0 ? '-' : '';
		$cents  = abs($cents);
		$units  = intdiv($cents, 100);
		$remain = $cents % 100;

		return $sign . $currency . number_format($units) . '.' . str_pad((string) $remain, 2, '0', STR_PAD_LEFT);
	}

	/**
	 * Parses operator-entered money into cents, or returns null when it is not a number.
	 *
	 * Accepts "125", "125.50", "$1,250.00" and "125,50". Rejects anything else rather than
	 * silently treating it as zero, which is the behaviour a treasurer needs.
	 */
	public static function parseMoneyToCents(string $input): ?int
	{
		$input = trim($input);

		if ($input === '') {
			return null;
		}

		$negative = str_starts_with($input, '-');

		// Keep digits, separators and a minus sign only.
		$cleaned = preg_replace('/[^0-9.,-]/', '', $input) ?? '';

		if ($cleaned === '' || preg_match('/\d/', $cleaned) !== 1) {
			return null;
		}

		$cleaned = str_replace(',', '.', $cleaned);

		// Reject ambiguous input such as "1.2.3" instead of guessing.
		if (substr_count($cleaned, '.') > 1) {
			return null;
		}

		$parts = explode('.', ltrim($cleaned, '-'));

		$units = (int) $parts[0];
		$cents = 0;

		if (isset($parts[1])) {
			$fraction = strlen($parts[1]) === 1 ? $parts[1] . '0' : substr($parts[1], 0, 2);
			$cents    = (int) $fraction;
		}

		$total = $units * 100 + $cents;

		return $negative ? -$total : $total;
	}

	/**
	 * Escapes a value for interpolation into a generated HTML string.
	 */
	public static function h(?string $value): string
	{
		return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * Reads the first value of a POSTed field as a trimmed string.
	 */
	public static function postValue(array $source, string $key, string $default = ''): string
	{
		$value = $source[$key] ?? $default;

		return is_string($value) ? trim($value) : $default;
	}
}
