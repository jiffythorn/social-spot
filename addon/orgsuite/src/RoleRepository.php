<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Positions people hold, and the permissions each one carries.
 *
 * Positions are rows, not a hard-coded list, because the whole point is that a Maintenance
 * lead, a Security officer or an Architectural Review chair exist on one instance and are
 * meaningless on the next. A set of built-in positions is seeded so a new organization is
 * usable immediately, and every one of them is editable.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Util\DateTimeFormat;

final class RoleRepository
{
	public const SCOPE_ORGANIZATION = 'organization';
	public const SCOPE_PLATFORM     = 'platform';

	/**
	 * Built-in positions.
	 *
	 * `rank` is seniority, used to order the console and to answer "who outranks whom" without
	 * anybody having to encode that in strings. `key` is stable and never shown; `label` is
	 * what an officer sees and may edit.
	 *
	 * The platform entries are deliberately reachable only by a site administrator: they
	 * describe who runs the machine, which is not the association's to decide.
	 */
	public const BUILTIN = [
		[
			'key'         => 'member',
			'label'       => 'Member',
			'rank'        => 10,
			'scope'       => self::SCOPE_ORGANIZATION,
			'is_default'  => true,
			'description' => 'An ordinary member of the organization.',
			'permissions' => [
				Permissions::VIEW_DIRECTORY,
				Permissions::VIEW_OWN_DUES,
			],
		],
		[
			'key'         => 'resident',
			'label'       => 'Resident',
			'rank'        => 20,
			'scope'       => self::SCOPE_ORGANIZATION,
			'description' => 'Lives in the community without owning the property.',
			'permissions' => [
				Permissions::VIEW_DIRECTORY,
				Permissions::VIEW_OWN_DUES,
			],
		],
		[
			'key'         => 'owner',
			'label'       => 'Owner',
			'rank'        => 30,
			'scope'       => self::SCOPE_ORGANIZATION,
			'description' => 'Owns a unit or lot.',
			'permissions' => [
				Permissions::VIEW_DIRECTORY,
				Permissions::VIEW_OWN_DUES,
			],
		],
		[
			'key'         => 'committee',
			'label'       => 'Committee member',
			'rank'        => 40,
			'scope'       => self::SCOPE_ORGANIZATION,
			'description' => 'Sits on a committee. Use the Committees tab to say which one.',
			'permissions' => [
				Permissions::VIEW_DIRECTORY,
				Permissions::VIEW_OWN_DUES,
			],
		],
		[
			'key'         => 'board',
			'label'       => 'Board member',
			'rank'        => 60,
			'scope'       => self::SCOPE_ORGANIZATION,
			'description' => 'Sits on the board and can see board-only records.',
			'permissions' => [
				Permissions::VIEW_DIRECTORY,
				Permissions::VIEW_OWN_DUES,
				Permissions::VIEW_BOARD_DOCUMENTS,
				Permissions::MANAGE_DOCUMENTS,
				Permissions::MANAGE_ANNOUNCEMENTS,
			],
		],
		[
			'key'         => 'secretary',
			'label'       => 'Secretary',
			'rank'        => 65,
			'scope'       => self::SCOPE_ORGANIZATION,
			'description' => 'Keeps the records and the membership list.',
			'permissions' => [
				Permissions::VIEW_DIRECTORY,
				Permissions::VIEW_OWN_DUES,
				Permissions::VIEW_BOARD_DOCUMENTS,
				Permissions::MANAGE_DOCUMENTS,
				Permissions::MANAGE_ANNOUNCEMENTS,
				Permissions::MANAGE_MEMBERS,
			],
		],
		[
			'key'         => 'treasurer',
			'label'       => 'Treasurer',
			'rank'        => 70,
			'scope'       => self::SCOPE_ORGANIZATION,
			'description' => 'Keeps the money and the books.',
			'permissions' => [
				Permissions::VIEW_DIRECTORY,
				Permissions::VIEW_OWN_DUES,
				Permissions::VIEW_BOARD_DOCUMENTS,
				Permissions::MANAGE_DUES,
				Permissions::VIEW_AUDIT,
			],
		],
		[
			'key'         => 'vice_president',
			'label'       => 'Vice president',
			'rank'        => 80,
			'scope'       => self::SCOPE_ORGANIZATION,
			'description' => 'Deputises for the president.',
			'permissions' => [
				Permissions::VIEW_DIRECTORY,
				Permissions::VIEW_OWN_DUES,
				Permissions::VIEW_BOARD_DOCUMENTS,
				Permissions::VERIFY_APPLICATIONS,
				Permissions::MANAGE_MEMBERS,
				Permissions::MANAGE_DUES,
				Permissions::MANAGE_DOCUMENTS,
				Permissions::MANAGE_ANNOUNCEMENTS,
				Permissions::MANAGE_COMMITTEES,
				Permissions::VIEW_AUDIT,
			],
		],
		[
			'key'         => 'president',
			'label'       => 'President',
			'rank'        => 90,
			'scope'       => self::SCOPE_ORGANIZATION,
			'description' => 'Leads the organization; can change everything about it.',
			'permissions' => [
				Permissions::VIEW_DIRECTORY,
				Permissions::VIEW_OWN_DUES,
				Permissions::VIEW_BOARD_DOCUMENTS,
				Permissions::VERIFY_APPLICATIONS,
				Permissions::MANAGE_MEMBERS,
				Permissions::MANAGE_ROSTER,
				Permissions::MANAGE_DUES,
				Permissions::MANAGE_DOCUMENTS,
				Permissions::MANAGE_ANNOUNCEMENTS,
				Permissions::MANAGE_COMMITTEES,
				Permissions::MANAGE_ROLES,
				Permissions::MANAGE_SETTINGS,
				Permissions::VIEW_AUDIT,
			],
		],
		[
			'key'         => 'technician',
			'label'       => 'Technician',
			'rank'        => 95,
			'scope'       => self::SCOPE_PLATFORM,
			'is_system'   => true,
			'description' => 'Runs and maintains the server this organization is hosted on.',
			'permissions' => [
				Permissions::PLATFORM_CONFIGURE,
				Permissions::PLATFORM_AUDIT,
			],
		],
		[
			'key'         => 'developer',
			'label'       => 'Developer',
			'rank'        => 97,
			'scope'       => self::SCOPE_PLATFORM,
			'is_system'   => true,
			'description' => 'Builds and supports the software. Sees diagnostics, not member records.',
			'permissions' => [
				Permissions::PLATFORM_CONFIGURE,
				Permissions::PLATFORM_AUDIT,
			],
		],
		[
			'key'         => 'site_admin',
			'label'       => 'Site administrator',
			'rank'        => 100,
			'scope'       => self::SCOPE_PLATFORM,
			'is_system'   => true,
			'description' => 'Administers the installation itself. This is Friendica\'s own administrator flag.',
			'permissions' => [
				Permissions::PLATFORM_CONFIGURE,
				Permissions::PLATFORM_MEMBERS,
				Permissions::PLATFORM_AUDIT,
			],
		],
	];

	/** Whether the built-in positions have been seeded in this process. */
	private static bool $seeded = false;

	// ----------------------------------------------------------------- reading

	/**
	 * Positions, most senior first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(?string $scope = null, bool $includeInactive = true): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		$condition = [];

		if ($scope !== null) {
			$condition['scope'] = $scope;
		}

		if (!$includeInactive) {
			$condition['active'] = true;
		}

		$rows = DBA::selectToArray(
			OrgSchema::TABLE_ROLE,
			[],
			$condition ?: [],
			['order' => ['rank' => 'DESC', 'label' => 'ASC']]
		);

		return array_map([self::class, 'hydrate'], $rows);
	}

	public static function organizationRoles(bool $includeInactive = true): array
	{
		return self::all(self::SCOPE_ORGANIZATION, $includeInactive);
	}

	public static function platformRoles(bool $includeInactive = true): array
	{
		return self::all(self::SCOPE_PLATFORM, $includeInactive);
	}

	public static function byId(int $id): ?array
	{
		if ($id <= 0 || !OrgSchema::ready()) {
			return null;
		}

		$row = DBA::selectFirst(OrgSchema::TABLE_ROLE, [], ['id' => $id]);

		return DBA::isResult($row) ? self::hydrate($row) : null;
	}

	public static function byKey(string $key): ?array
	{
		if ($key === '' || !OrgSchema::ready()) {
			return null;
		}

		$row = DBA::selectFirst(OrgSchema::TABLE_ROLE, [], ['slug' => $key]);

		return DBA::isResult($row) ? self::hydrate($row) : null;
	}

	public static function defaultRole(): ?array
	{
		if (!OrgSchema::ready()) {
			return null;
		}

		$row = DBA::selectFirst(
			OrgSchema::TABLE_ROLE,
			[],
			['is_default' => true, 'active' => true, 'scope' => self::SCOPE_ORGANIZATION],
			['order' => ['rank' => 'ASC']]
		);

		if (DBA::isResult($row)) {
			return self::hydrate($row);
		}

		return self::byKey('member');
	}

	/** How many members hold each position, keyed by role id. */
	public static function holderCounts(): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		$counts = [];

		// A raw comparison has to be the numerically indexed first element; written as a
		// column => value pair this would be read as a column name.
		$rows = DBA::selectToArray(OrgSchema::TABLE_MEMBER, ['role_id'], ['`role_id` > 0']);

		foreach ($rows as $row) {
			$id = (int) $row['role_id'];
			$counts[$id] = ($counts[$id] ?? 0) + 1;
		}

		return $counts;
	}

	public static function countHolders(int $roleId): int
	{
		if ($roleId <= 0 || !OrgSchema::ready()) {
			return 0;
		}

		return (int) DBA::count(OrgSchema::TABLE_MEMBER, ['role_id' => $roleId]);
	}

	// ------------------------------------------------------- permission lookup

	/**
	 * The permissions a member actually holds.
	 *
	 * Two migrations are handled here, because getting them wrong would either lock everybody
	 * out or hand out powers nobody granted:
	 *
	 *  - a member whose `role_id` is 0 predates positions being rows, so their stored `role`
	 *    key is looked up instead;
	 *  - a position that has been retired (`active` false) grants nothing, so deactivating a
	 *    position is a real way to withdraw its powers without deleting history.
	 *
	 * @param array<string, mixed> $member
	 *
	 * @return string[]
	 */
	public static function permissionsForMember(array $member): array
	{
		$role = self::resolveForMember($member);

		if ($role === null || !$role['active']) {
			return [];
		}

		return $role['permissions'];
	}

	/**
	 * @param array<string, mixed> $member
	 *
	 * @return array<string, mixed>|null
	 */
	public static function resolveForMember(array $member): ?array
	{
		$roleId = (int) ($member['role_id'] ?? 0);

		if ($roleId > 0) {
			$role = self::byId($roleId);

			if ($role !== null) {
				return $role;
			}
		}

		$key = (string) ($member['role'] ?? '');

		return $key !== '' ? self::byKey($key) : null;
	}

	/**
	 * Whether a member's position gives them any of the powers that make someone an officer,
	 * which is what menus and warnings key off.
	 */
	public static function isOfficerMember(array $member): bool
	{
		$granted = self::permissionsForMember($member);

		foreach (Permissions::PRIVILEGED as $capability) {
			if (in_array($capability, $granted, true)) {
				return true;
			}
		}

		return false;
	}

	/** Convenience for the current reader. */
	public static function currentRole(): ?array
	{
		$member = MemberRepository::forCurrentUser();

		return $member !== null ? self::resolveForMember($member) : null;
	}

	// ----------------------------------------------------------------- writing

	/**
	 * Creates the built-in positions if they are missing.
	 *
	 * Idempotent, and it never overwrites a position an organization has edited: matching is
	 * by stable key, and an existing row is left exactly as it is. That is what makes it safe
	 * to call on every schema update rather than only at install time.
	 *
	 * @return int how many positions were created
	 */
	public static function seed(bool $force = false): int
	{
		if (!OrgSchema::ready()) {
			return 0;
		}

		if (self::$seeded && !$force) {
			return 0;
		}

		$created = 0;
		$now     = DateTimeFormat::utcNow();

		foreach (self::BUILTIN as $definition) {
			if (self::byKey($definition['key']) !== null) {
				continue;
			}

			$inserted = DBA::insert(OrgSchema::TABLE_ROLE, [
				'slug'        => $definition['key'],
				'label'       => $definition['label'],
				'description' => $definition['description'],
				'rank'        => $definition['rank'],
				'scope'       => $definition['scope'],
				'permissions' => json_encode(Permissions::sanitize($definition['permissions'])),
				'is_system'   => !empty($definition['is_system']),
				'is_default'  => !empty($definition['is_default']),
				'active'      => true,
				'created'     => $now,
				'updated'     => $now,
			]);

			if ($inserted) {
				$created++;
			}
		}

		self::$seeded = true;

		return $created;
	}

	/**
	 * @param array<string, mixed> $fields
	 *
	 * @return int the new role id, or 0
	 */
	public static function create(array $fields): int
	{
		if (!OrgSchema::ready()) {
			return 0;
		}

		$label = trim((string) ($fields['label'] ?? ''));

		if ($label === '') {
			return 0;
		}

		$key = self::uniqueKey((string) ($fields['key'] ?? $label));

		$inserted = DBA::insert(OrgSchema::TABLE_ROLE, [
			'slug'        => $key,
			'label'       => $label,
			'description' => trim((string) ($fields['description'] ?? '')),
			'rank'        => max(1, min(9999, (int) ($fields['rank'] ?? 50))),
			'scope'       => self::sanitizeScope((string) ($fields['scope'] ?? self::SCOPE_ORGANIZATION)),
			'permissions' => json_encode(Permissions::sanitize($fields['permissions'] ?? [])),
			'is_system'   => false,
			'is_default'  => false,
			'active'      => true,
			'created'     => DateTimeFormat::utcNow(),
			'updated'     => DateTimeFormat::utcNow(),
		]);

		return $inserted ? (int) DBA::lastInsertId() : 0;
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	public static function update(int $id, array $fields): bool
	{
		$role = self::byId($id);

		if ($role === null) {
			return false;
		}

		$update = [
			'permissions' => json_encode(Permissions::sanitize($fields['permissions'] ?? [])),
			'updated'     => DateTimeFormat::utcNow(),
		];

		if (array_key_exists('label', $fields)) {
			$label = trim((string) $fields['label']);

			if ($label !== '') {
				$update['label'] = $label;
			}
		}

		if (array_key_exists('description', $fields)) {
			$update['description'] = trim((string) $fields['description']);
		}

		if (array_key_exists('rank', $fields)) {
			$update['rank'] = max(1, min(9999, (int) $fields['rank']));
		}

		// A system position keeps its key and its scope: renaming is fine, but turning the
		// built-in Member position into a platform one would quietly change what it can reach.
		if (!$role['is_system']) {
			if (array_key_exists('scope', $fields)) {
				$update['scope'] = self::sanitizeScope((string) $fields['scope']);
			}
		}

		if (array_key_exists('active', $fields)) {
			$update['active'] = (bool) $fields['active'];
		}

		return DBA::update(OrgSchema::TABLE_ROLE, $update, ['id' => $id]);
	}

	public static function setActive(int $id, bool $active): bool
	{
		$role = self::byId($id);

		if ($role === null) {
			return false;
		}

		// The fallback position must stay usable or nobody could be added at all.
		if (!$active && $role['is_default']) {
			return false;
		}

		return DBA::update(OrgSchema::TABLE_ROLE, [
			'active'  => $active,
			'updated' => DateTimeFormat::utcNow(),
		], ['id' => $id]);
	}

	/**
	 * Moves everybody holding a position onto another one.
	 *
	 * Deleting a position people hold would leave them with a role id pointing at nothing,
	 * which resolves to "no permissions at all" - effectively suspending them by accident.
	 * So the move is explicit.
	 */
	public static function reassign(int $fromId, int $toId): int
	{
		if ($fromId <= 0 || $toId <= 0 || $fromId === $toId || !OrgSchema::ready()) {
			return 0;
		}

		$target = self::byId($toId);

		if ($target === null) {
			return 0;
		}

		$moved = 0;

		foreach (DBA::selectToArray(OrgSchema::TABLE_MEMBER, ['uid'], ['role_id' => $fromId]) as $row) {
			$ok = DBA::update(OrgSchema::TABLE_MEMBER, [
				'role_id' => $toId,
				'role'    => $target['slug'],
			], ['uid' => (int) $row['uid']]);

			if ($ok) {
				$moved++;
			}
		}

		return $moved;
	}

	/**
	 * Deletes a position. Refuses while anybody still holds it, unless a replacement is given.
	 */
	public static function delete(int $id, int $reassignTo = 0): bool
	{
		$role = self::byId($id);

		if ($role === null || $role['is_system']) {
			return false;
		}

		if (self::countHolders($id) > 0) {
			if ($reassignTo <= 0) {
				return false;
			}

			self::reassign($id, $reassignTo);

			if (self::countHolders($id) > 0) {
				return false;
			}
		}

		return DBA::delete(OrgSchema::TABLE_ROLE, ['id' => $id]);
	}

	public static function setDefault(int $id): bool
	{
		$role = self::byId($id);

		if ($role === null || $role['scope'] !== self::SCOPE_ORGANIZATION) {
			return false;
		}

		DBA::update(OrgSchema::TABLE_ROLE, ['is_default' => false], ['is_default' => true]);
		DBA::update(OrgSchema::TABLE_ROLE, ['is_default' => true], ['id' => $id]);

		return true;
	}

	/**
	 * The member columns for a position, given either a role id or a role slug.
	 *
	 * Every place that assigns a position - approval, roster matching, the members tab - goes
	 * through here, so `role_id` and the legacy `role` slug can never disagree about what
	 * somebody holds. They used to be written independently, which is exactly how a person ends
	 * up showing one position in the console and holding another one's permissions.
	 *
	 * @return array{role_id: int, role: string}
	 */
	public static function memberFieldsFor(string|int $roleIdOrSlug): array
	{
		$role = null;

		if (is_int($roleIdOrSlug) || ctype_digit((string) $roleIdOrSlug)) {
			$role = self::byId((int) $roleIdOrSlug);
		} else {
			$role = self::byKey((string) $roleIdOrSlug);
		}

		if ($role === null) {
			$role = self::defaultRole();
		}

		if ($role === null) {
			// Nothing seeded yet. A member with no position is still a member.
			return ['role_id' => 0, 'role' => 'member'];
		}

		return ['role_id' => (int) $role['id'], 'role' => (string) $role['slug']];
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private static function hydrate(array $row): array
	{
		$permissions = json_decode((string) ($row['permissions'] ?? ''), true);

		$row['id']          = (int) $row['id'];
		$row['rank']        = (int) $row['rank'];
		$row['active']      = (bool) $row['active'];
		$row['is_system']   = (bool) $row['is_system'];
		$row['is_default']  = (bool) $row['is_default'];
		$row['permissions'] = Permissions::sanitize($permissions);

		return $row;
	}

	private static function sanitizeScope(string $scope): string
	{
		return $scope === self::SCOPE_PLATFORM ? self::SCOPE_PLATFORM : self::SCOPE_ORGANIZATION;
	}

	/**
	 * Turns a label into a key nobody else is using, so two positions can share a name.
	 */
	private static function uniqueKey(string $source): string
	{
		$key = strtolower(trim($source));
		$key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
		$key = trim($key, '_');

		if ($key === '') {
			$key = 'position';
		}

		$key    = substr($key, 0, 48);
		$base   = $key;
		$suffix = 2;

		while (self::byKey($key) !== null) {
			$key = $base . '_' . $suffix;
			$suffix++;
		}

		return $key;
	}
}
