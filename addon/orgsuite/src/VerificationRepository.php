<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Applications and their credential review state.
 *
 * A row is written the moment somebody submits the sign-up form and is carried through to
 * approval, so the credential an applicant typed is preserved verbatim for the officer who
 * cross-references it. That is the record that answers "why was this account activated?".
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\Database\DBA;
use Friendica\Util\DateTimeFormat;

final class VerificationRepository
{
	public const STATUS_PENDING  = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';

	/**
	 * Records a submitted credential. Returns the new row id, or 0 on failure.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function create(array $data): int
	{
		if (!OrgSchema::ready()) {
			return 0;
		}

		$inserted = DBA::insert(OrgSchema::TABLE_VERIFICATION, [
			'uid'             => (int) ($data['uid'] ?? 0),
			'username'        => (string) ($data['username'] ?? ''),
			'nickname'        => (string) ($data['nickname'] ?? ''),
			'email'           => (string) ($data['email'] ?? ''),
			'credential_raw'  => (string) ($data['credential_raw'] ?? ''),
			'credential'      => (string) ($data['credential'] ?? ''),
			'credential_type' => (string) ($data['credential_type'] ?? 'unit'),
			'roster_id'       => (int) ($data['roster_id'] ?? 0),
			'status'          => self::STATUS_PENDING,
			'created'         => DateTimeFormat::utcNow(),
		]);

		return $inserted ? DBA::lastInsertId() : 0;
	}

	public static function byId(int $id): ?array
	{
		if ($id <= 0 || !OrgSchema::ready()) {
			return null;
		}

		$row = DBA::selectFirst(OrgSchema::TABLE_VERIFICATION, [], ['id' => $id]);

		return DBA::isResult($row) ? $row : null;
	}

	/**
	 * The most recent application linked to a local account.
	 */
	public static function forUid(int $uid): ?array
	{
		if ($uid <= 0 || !OrgSchema::ready()) {
			return null;
		}

		$row = DBA::selectFirst(OrgSchema::TABLE_VERIFICATION, [], ['uid' => $uid], ['order' => ['id' => 'DESC']]);

		return DBA::isResult($row) ? $row : null;
	}

	/**
	 * The most recent unlinked application matching an email address, used to bind the
	 * credential to the account the moment Friendica creates it.
	 */
	public static function latestUnlinkedForEmail(string $email): ?array
	{
		if ($email === '' || !OrgSchema::ready()) {
			return null;
		}

		$row = DBA::selectFirst(OrgSchema::TABLE_VERIFICATION, [], [
			'email' => $email,
			'uid'   => 0,
		], ['order' => ['id' => 'DESC']]);

		return DBA::isResult($row) ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	/**
	 * Finds an application by the identifier someone would type into the sign-in box.
	 *
	 * Used to explain a failed sign-in that is not actually a wrong password: an account that
	 * is still awaiting verification cannot authenticate at all, because Friendica only
	 * authenticates verified accounts. Without this an applicant is told to check their
	 * credentials, which sends them round the loop of re-registering.
	 */
	public static function pendingForIdentifier(string $identifier): ?array
	{
		if (!OrgSchema::ready() || $identifier === '') {
			return null;
		}

		$identifier = strtolower(trim($identifier));

		$row = DBA::selectFirst(
			OrgSchema::TABLE_VERIFICATION,
			[],
			[
				'`status` = ? AND (`email` = ? OR `nickname` = ?)',
				'pending',
				$identifier,
				$identifier,
			],
			['order' => ['created' => 'DESC']]
		);

		return DBA::isResult($row) ? $row : null;
	}

	public static function byStatus(string $status, int $limit = 200): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		return DBA::selectToArray(
			OrgSchema::TABLE_VERIFICATION,
			[],
			['status' => $status],
			['order' => ['created' => 'DESC'], 'limit' => $limit]
		);
	}

	public static function pendingCount(): int
	{
		return OrgSchema::ready() ? DBA::count(OrgSchema::TABLE_VERIFICATION, ['status' => self::STATUS_PENDING]) : 0;
	}

	/**
	 * @return array<string, int>
	 */
	public static function counts(): array
	{
		$counts = [
			self::STATUS_PENDING  => 0,
			self::STATUS_APPROVED => 0,
			self::STATUS_REJECTED => 0,
		];

		if (!OrgSchema::ready()) {
			return $counts;
		}

		$stmt = DBA::p('SELECT `status`, COUNT(*) AS `total` FROM `' . OrgSchema::TABLE_VERIFICATION . '` GROUP BY `status`');

		while ($row = DBA::fetch($stmt)) {
			$counts[$row['status']] = (int) $row['total'];
		}
		DBA::close($stmt);

		return $counts;
	}

	public static function attachUid(int $id, int $uid): bool
	{
		if (!OrgSchema::ready()) {
			return false;
		}

		return DBA::update(OrgSchema::TABLE_VERIFICATION, ['uid' => $uid], ['id' => $id]);
	}

	public static function setRosterMatch(int $id, int $rosterId): bool
	{
		if (!OrgSchema::ready()) {
			return false;
		}

		return DBA::update(OrgSchema::TABLE_VERIFICATION, ['roster_id' => $rosterId], ['id' => $id]);
	}

	public static function setStatus(int $id, string $status, int $officerUid, string $note = ''): bool
	{
		if (!array_key_exists($status, [
			self::STATUS_PENDING  => true,
			self::STATUS_APPROVED => true,
			self::STATUS_REJECTED => true,
		])) {
			return false;
		}

		if (!OrgSchema::ready()) {
			return false;
		}

		return DBA::update(OrgSchema::TABLE_VERIFICATION, [
			'status'      => $status,
			'reviewed_by' => $officerUid,
			'reviewed_at' => DateTimeFormat::utcNow(),
			'admin_note'  => $note !== '' ? $note : null,
		], ['id' => $id]);
	}
}
