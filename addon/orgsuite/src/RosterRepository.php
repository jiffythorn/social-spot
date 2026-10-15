<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The pre-loaded roster.
 *
 * Downloading a CSV from the association's records system and pasting it in here once is far
 * less work than reviewing every sign-up by hand, and it is also what makes a match
 * trustworthy: only a credential that was on the list can ever be auto-approved.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\Database\DBA;
use Friendica\Util\DateTimeFormat;

final class RosterRepository
{
	public const RESULT_ADDED     = 'added';
	public const RESULT_DUPLICATE = 'duplicate';
	public const RESULT_INVALID   = 'invalid';

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		return DBA::selectToArray(OrgSchema::TABLE_ROSTER, [], [], ['order' => ['credential_type', 'label', 'credential']]);
	}

	public static function count(): int
	{
		return OrgSchema::ready() ? DBA::count(OrgSchema::TABLE_ROSTER) : 0;
	}

	public static function unclaimedCount(): int
	{
		return OrgSchema::ready() ? DBA::count(OrgSchema::TABLE_ROSTER, ['claimed_uid' => 0]) : 0;
	}

	public static function findByCredential(string $normalized): ?array
	{
		if ($normalized === '' || !OrgSchema::ready()) {
			return null;
		}

		$entry = DBA::selectFirst(OrgSchema::TABLE_ROSTER, [], ['credential' => $normalized]);

		return DBA::isResult($entry) ? $entry : null;
	}

	/**
	 * Adds one roster entry.
	 *
	 * @return string one of the RESULT_* constants
	 */
	public static function add(string $rawCredential, string $type = 'unit', string $label = '', string $household = '', string $defaultRole = 'member'): string
	{
		if (!OrgSchema::ready()) {
			return self::RESULT_INVALID;
		}

		$normalized = Text::normalizeCredential($rawCredential);

		if ($normalized === '') {
			return self::RESULT_INVALID;
		}

		if (!in_array($type, self::types(), true)) {
			$type = 'unit';
		}

		if (!in_array($defaultRole, OrgConfig::ROLES, true)) {
			$defaultRole = 'member';
		}

		if (DBA::exists(OrgSchema::TABLE_ROSTER, ['credential' => $normalized])) {
			return self::RESULT_DUPLICATE;
		}

		$inserted = DBA::insert(OrgSchema::TABLE_ROSTER, [
			'credential'      => $normalized,
			'credential_type' => $type,
			'label'           => $label !== '' ? $label : $rawCredential,
			'household'       => $household,
			'default_role'    => $defaultRole,
			'created'         => DateTimeFormat::utcNow(),
		]);

		return $inserted ? self::RESULT_ADDED : self::RESULT_INVALID;
	}

	public static function delete(int $id): bool
	{
		return OrgSchema::ready() && DBA::delete(OrgSchema::TABLE_ROSTER, ['id' => $id]);
	}

	public static function deleteAll(): bool
	{
		return OrgSchema::ready() && DBA::delete(OrgSchema::TABLE_ROSTER, []);
	}

	/**
	 * Marks a roster entry as claimed by a member, so one unit cannot be registered twice.
	 */
	public static function claim(int $id, int $uid): bool
	{
		if (!OrgSchema::ready()) {
			return false;
		}

		return DBA::update(OrgSchema::TABLE_ROSTER, [
			'claimed_uid' => $uid,
			'claimed_at'  => DateTimeFormat::utcNow(),
		], ['id' => $id, 'claimed_uid' => 0]);
	}

	/**
	 * Imports a pasted CSV blob.
	 *
	 * Accepted columns, in order: credential[, label[, household[, role]]].
	 * A leading header row is detected and skipped. Lines starting with '#' are comments.
	 *
	 * @return array{added: int, duplicate: int, invalid: int, errors: string[], total: int}
	 */
	public static function importCsv(string $csv, string $type = 'unit'): array
	{
		$summary = ['added' => 0, 'duplicate' => 0, 'invalid' => 0, 'errors' => [], 'total' => 0];

		if (!OrgSchema::ready()) {
			$summary['errors'][] = 'The organization tables do not exist yet. Run "bin/console dbstructure update" first.';

			return $summary;
		}

		$lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];

		foreach ($lines as $index => $line) {
			$line = trim($line);

			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			$cells = array_map('trim', str_getcsv($line));

			// Skip a header row such as "unit,address,household".
			if ($index === 0 && self::looksLikeHeader($cells)) {
				continue;
			}

			$credential = $cells[0] ?? '';

			if ($credential === '') {
				$summary['invalid']++;
				$summary['errors'][] = 'Line ' . ($index + 1) . ': no credential in the first column.';
				continue;
			}

			$summary['total']++;

			switch (self::add($credential, $type, $cells[1] ?? '', $cells[2] ?? '', $cells[3] ?? 'member')) {
				case self::RESULT_ADDED:
					$summary['added']++;
					break;
				case self::RESULT_DUPLICATE:
					$summary['duplicate']++;
					break;
				default:
					$summary['invalid']++;
					$summary['errors'][] = 'Line ' . ($index + 1) . ': "' . $credential . '" is not a usable credential.';
					break;
			}
		}

		return $summary;
	}

	/**
	 * @param string[] $cells
	 */
	private static function looksLikeHeader(array $cells): bool
	{
		$first = strtolower($cells[0] ?? '');

		return in_array($first, ['credential', 'unit', 'unit number', 'address', 'property address', 'membership id', 'member id', 'id'], true);
	}

	/**
	 * Credential kinds an officer can import.
	 *
	 * @return string[]
	 */
	public static function types(): array
	{
		return ['unit', 'membership_id', 'address', 'email'];
	}

	/**
	 * Selectable credential kinds, as an option list.
	 *
	 * @return array<string, string>
	 */
	public static function typeLabels(): array
	{
		$labels = [];

		foreach (self::types() as $type) {
			$labels[$type] = self::typeLabel($type);
		}

		return $labels;
	}

	public static function typeLabel(string $type): string
	{
		return match ($type) {
			'membership_id' => 'Membership ID',
			'address'       => 'Address',
			'email'         => 'Email address',
			default         => 'Unit or lot number',
		};
	}
}
