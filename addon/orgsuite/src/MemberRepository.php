<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Member records: who belongs to this organization, in what role, and whether their
 * credential has been verified.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\Database\DBA;
use Friendica\Util\DateTimeFormat;
use Friendica\Addon\OrgSuite\RoleRepository;

final class MemberRepository
{
	public static function forUser(int $uid): ?array
	{
		if ($uid <= 0 || !OrgSchema::ready()) {
			return null;
		}

		$member = DBA::selectFirst(OrgSchema::TABLE_MEMBER, [], ['uid' => $uid]);

		return DBA::isResult($member) ? $member : null;
	}

	public static function forCurrentUser(): ?array
	{
		return self::forUser((int) \Friendica\DI::userSession()->getLocalUserId());
	}

	public static function isOfficer(int $uid): bool
	{
		$member = self::forUser($uid);

		if ($member === null || $member['status'] !== 'active') {
			return false;
		}

		if (!OrgSchema::ready()) {
			return in_array((string) $member['role'], OrgConfig::OFFICER_ROLES, true);
		}

		return RoleRepository::isOfficerMember($member);
	}

	public static function isVerified(int $uid): bool
	{
		$member = self::forUser($uid);

		return $member !== null && $member['status'] === 'active';
	}

	/**
	 * Creates the membership record for a brand-new account, or updates the existing one.
	 *
	 * @param array<string, mixed> $fields
	 */
	public static function upsert(int $uid, array $fields): bool
	{
		if ($uid <= 0 || !OrgSchema::ready()) {
			return false;
		}

		$fields['updated'] = DateTimeFormat::utcNow();

		if (DBA::exists(OrgSchema::TABLE_MEMBER, ['uid' => $uid])) {
			return DBA::update(OrgSchema::TABLE_MEMBER, $fields, ['uid' => $uid]);
		}

		$fields['uid']     = $uid;
		$fields['created'] = $fields['created'] ?? DateTimeFormat::utcNow();

		return DBA::insert(OrgSchema::TABLE_MEMBER, $fields);
	}

	/**
	 * Marks a member as verified and active, recording which officer did it.
	 */
	public static function activate(int $uid, int $officerUid, array $extra = []): bool
	{
		return self::upsert($uid, array_merge($extra, [
			'status'      => 'active',
			'verified_by' => $officerUid,
			'verified_at' => DateTimeFormat::utcNow(),
		]));
	}

	/**
	 * Assigns a position and keeps the two role columns in step.
	 *
	 * @param string|int $role a role id, or one of the role slugs
	 */
	public static function setRole(int $uid, string|int $role): bool
	{
		$fields = RoleRepository::memberFieldsFor($role);

		if ($fields['role_id'] === 0) {
			// No positions exist yet, so there is nothing sensible to assign.
			return false;
		}

		return self::upsert($uid, $fields);
	}

	public static function setStatus(int $uid, string $status): bool
	{
		if (!array_key_exists($status, OrgConfig::STATUS_LABELS)) {
			return false;
		}

		return self::upsert($uid, ['status' => $status]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function listAll(?string $status = null): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		$condition = $status !== null ? ['status' => $status] : [];

		return DBA::selectToArray(OrgSchema::TABLE_MEMBER, [], $condition, ['order' => ['status', 'household', 'uid']]);
	}

	/**
	 * Members who agreed to appear in the directory.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function directory(): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		return DBA::selectToArray(
			OrgSchema::TABLE_MEMBER,
			[],
			['status' => 'active', 'directory' => true],
			['order' => ['household', 'unit']]
		);
	}

	/**
	 * Membership counts by status, used on the dashboard and console overview.
	 *
	 * @return array<string, int>
	 */
	public static function countsByStatus(): array
	{
		$counts = array_fill_keys(array_keys(OrgConfig::STATUS_LABELS), 0);

		if (!OrgSchema::ready()) {
			return $counts;
		}

		$stmt = DBA::p('SELECT `status`, COUNT(*) AS `total` FROM `' . OrgSchema::TABLE_MEMBER . '` GROUP BY `status`');

		while ($row = DBA::fetch($stmt)) {
			$counts[$row['status']] = (int) $row['total'];
		}
		DBA::close($stmt);

		return $counts;
	}

	/**
	 * Finds the membership record matching a normalized credential, if any.
	 */
	public static function findByCredential(string $credential): ?array
	{
		if (!OrgSchema::ready()) {
			return null;
		}

		$member = DBA::selectFirst(
			OrgSchema::TABLE_MEMBER,
			[],
			['`unit` = ? OR `membership_id` = ?', $credential, $credential]
		);

		return DBA::isResult($member) ? $member : null;
	}
}
