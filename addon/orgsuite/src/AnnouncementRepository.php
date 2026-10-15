<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Announcement banners.
 *
 * The top-of-page notice is the single most-used feature in every HOA tool researched for
 * this project, because "the water is off Tuesday" must not depend on a resident scrolling
 * a timeline. Banners therefore support a start and end time so an officer can queue a
 * notice and let it retire itself, and a visibility level so board-only notices stay on the
 * board's pages.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\Database\DBA;
use Friendica\Util\DateTimeFormat;

final class AnnouncementRepository
{
	public const LEVELS = ['info' => 'Information', 'important' => 'Important', 'urgent' => 'Urgent'];

	/**
	 * Banners that should currently be shown to the given audience.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function visible(bool $isLoggedIn, bool $isOfficer, bool $isAdmin, ?array $member = null): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		$now = DateTimeFormat::utcNow();

		// A raw SQL condition has to be the first, numerically indexed element with its values
		// following it (see Database\DBA::selectToArray). Written as string keys these were
		// read as column names, the query failed, and every announcement silently vanished.
		$rows = DBA::selectToArray(
			OrgSchema::TABLE_ANNOUNCEMENT,
			[],
			[
				'`active` AND (`starts_at` IS NULL OR `starts_at` <= ?) AND (`ends_at` IS NULL OR `ends_at` >= ?)',
				$now,
				$now,
			],
			['order' => ['level' => 'DESC', 'created' => 'DESC'], 'limit' => 5]
		);

		return array_values(array_filter($rows, static function (array $row) use ($isLoggedIn, $isOfficer, $isAdmin, $member): bool {
			return VaultRepository::canView((string) $row['visibility'], $isLoggedIn, $isOfficer, $isAdmin, $member);
		}));
	}

	/**
	 * Everything, newest first, for the console.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(int $limit = 100): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		return DBA::selectToArray(OrgSchema::TABLE_ANNOUNCEMENT, [], [], ['order' => ['active' => 'DESC', 'created' => 'DESC'], 'limit' => $limit]);
	}

	public static function create(string $title, string $body, string $level, string $visibility, ?string $endsAt, string $link = '', int $createdBy = 0): int
	{
		$title = trim($title);

		if ($title === '' || !OrgSchema::ready()) {
			return 0;
		}

		$inserted = DBA::insert(OrgSchema::TABLE_ANNOUNCEMENT, [
			'title'      => $title,
			'body'       => $body !== '' ? $body : null,
			'level'      => self::sanitizeLevel($level),
			'link'       => $link,
			'visibility' => VaultRepository::sanitizeVisibility($visibility),
			'active'     => true,
			'starts_at'  => DateTimeFormat::utcNow(),
			'ends_at'    => $endsAt,
			'created'    => DateTimeFormat::utcNow(),
			'created_by' => $createdBy,
		]);

		return $inserted ? DBA::lastInsertId() : 0;
	}

	public static function setActive(int $id, bool $active): bool
	{
		return OrgSchema::ready() && DBA::update(OrgSchema::TABLE_ANNOUNCEMENT, ['active' => $active], ['id' => $id]);
	}

	public static function delete(int $id): bool
	{
		return OrgSchema::ready() && DBA::delete(OrgSchema::TABLE_ANNOUNCEMENT, ['id' => $id]);
	}

	public static function sanitizeLevel(string $level): string
	{
		return array_key_exists($level, self::LEVELS) ? $level : 'info';
	}

	public static function activeCount(): int
	{
		return OrgSchema::ready() ? DBA::count(OrgSchema::TABLE_ANNOUNCEMENT, ['active' => true]) : 0;
	}
}
