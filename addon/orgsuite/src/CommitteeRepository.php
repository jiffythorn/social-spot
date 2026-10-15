<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Committees: the working groups an organization actually runs on.
 *
 * An association rarely has one hierarchy. It has a board, and beside it an architectural
 * review committee, a grounds committee, an events committee and a welcoming committee, each
 * with a chair and a handful of members, and people move between them. Modelling that as a
 * single "role" per person is what makes off-the-shelf software unusable for this audience,
 * so committees are first-class here and a person can sit on as many as they like.
 *
 * Sitting on a committee grants nothing on its own. It records who is responsible and gives
 * the chair a scoped say over that committee's own business, which the permission checks ask
 * about separately from organization-wide powers.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\Database\DBA;
use Friendica\Util\DateTimeFormat;

final class CommitteeRepository
{
	public const SCOPE_MEMBERS = 'members';
	public const SCOPE_BOARD   = 'board';

	public const OFFICES = [
		'chair'     => 'Chair',
		'vice'      => 'Vice chair',
		'secretary' => 'Secretary',
		'member'    => 'Member',
	];

	public static function officeLabel(string $office): string
	{
		return self::OFFICES[$office] ?? self::OFFICES['member'];
	}

	public static function sanitizeOffice(string $office): string
	{
		return array_key_exists($office, self::OFFICES) ? $office : 'member';
	}

	public static function sanitizeScope(string $scope): string
	{
		return $scope === self::SCOPE_BOARD ? self::SCOPE_BOARD : self::SCOPE_MEMBERS;
	}

	// ----------------------------------------------------------------- reading

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(bool $includeInactive = true): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		$rows = DBA::selectToArray(
			OrgSchema::TABLE_COMMITTEE,
			[],
			$includeInactive ? [] : ['active' => true],
			['order' => ['name' => 'ASC']]
		);

		return array_map([self::class, 'hydrate'], $rows);
	}

	/**
	 * Committees the given reader may see, which is what members are shown.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function visibleTo(bool $isOfficer, bool $isAdmin): array
	{
		return array_values(array_filter(
			self::all(false),
			static fn (array $committee): bool => $committee['visibility'] !== self::SCOPE_BOARD || $isOfficer || $isAdmin
		));
	}

	public static function byId(int $id): ?array
	{
		if ($id <= 0 || !OrgSchema::ready()) {
			return null;
		}

		$row = DBA::selectFirst(OrgSchema::TABLE_COMMITTEE, [], ['id' => $id]);

		return DBA::isResult($row) ? self::hydrate($row) : null;
	}

	public static function byKey(string $key): ?array
	{
		if ($key === '' || !OrgSchema::ready()) {
			return null;
		}

		$row = DBA::selectFirst(OrgSchema::TABLE_COMMITTEE, [], ['slug' => $key]);

		return DBA::isResult($row) ? self::hydrate($row) : null;
	}

	/**
	 * Who sits on a committee, most senior office first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function members(int $committeeId): array
	{
		if ($committeeId <= 0 || !OrgSchema::ready()) {
			return [];
		}

		$rows = DBA::selectToArray(
			OrgSchema::TABLE_COMMITTEE_MEMBER,
			[],
			['committee_id' => $committeeId],
			['order' => ['created' => 'ASC']]
		);

		$order = array_flip(array_keys(self::OFFICES));

		usort($rows, static function (array $a, array $b) use ($order): int {
			return ($order[$a['office']] ?? 99) <=> ($order[$b['office']] ?? 99);
		});

		return $rows;
	}

	/** The committees a person sits on, with their office in each. */
	public static function forUser(int $uid): array
	{
		if ($uid <= 0 || !OrgSchema::ready()) {
			return [];
		}

		$seats = DBA::selectToArray(OrgSchema::TABLE_COMMITTEE_MEMBER, [], ['uid' => $uid]);

		$committees = [];

		foreach ($seats as $seat) {
			$committee = self::byId((int) $seat['committee_id']);

			if ($committee !== null) {
				$committee['office'] = (string) $seat['office'];
				$committees[]        = $committee;
			}
		}

		return $committees;
	}

	/**
	 * Whether somebody may act on a specific committee's business.
	 *
	 * This is the scoped counterpart to a permission: a grounds chair does not get to rule on
	 * architectural requests, and being on one committee does not make somebody a board
	 * member. Organization-wide managers still pass, because otherwise nobody could set a
	 * committee up in the first place.
	 */
	public static function canActOn(int $committeeId, ?int $uid = null): bool
	{
		if ($committeeId <= 0) {
			return false;
		}

		if (Permissions::can(Permissions::MANAGE_COMMITTEES, $uid)) {
			return true;
		}

		$uid = $uid ?? (int) \Friendica\DI::userSession()->getLocalUserId();

		if ($uid <= 0) {
			return false;
		}

		$committee = self::byId($committeeId);

		if ($committee === null) {
			return false;
		}

		if ((int) $committee['chair_uid'] === $uid) {
			return true;
		}

		$seat = DBA::selectFirst(
			OrgSchema::TABLE_COMMITTEE_MEMBER,
			['office'],
			['committee_id' => $committeeId, 'uid' => $uid]
		);

		return DBA::isResult($seat) && in_array((string) $seat['office'], ['chair', 'vice'], true);
	}

	/** Committee ids the reader runs, for scoping the parts of the console they may touch. */
	public static function chairedBy(?int $uid = null): array
	{
		$uid = $uid ?? (int) \Friendica\DI::userSession()->getLocalUserId();

		if ($uid <= 0 || !OrgSchema::ready()) {
			return [];
		}

		$ids = [];

		foreach (self::all(false) as $committee) {
			if ((int) $committee['chair_uid'] === $uid) {
				$ids[] = $committee['id'];
			}
		}

		foreach (DBA::selectToArray(OrgSchema::TABLE_COMMITTEE_MEMBER, ['committee_id'], ['uid' => $uid, 'office' => ['chair', 'vice']]) as $row) {
			$ids[] = (int) $row['committee_id'];
		}

		return array_values(array_unique($ids));
	}

	public static function count(): int
	{
		return OrgSchema::ready() ? (int) DBA::count(OrgSchema::TABLE_COMMITTEE, ['active' => true]) : 0;
	}

	// ----------------------------------------------------------------- writing

	/**
	 * @param array<string, mixed> $fields
	 *
	 * @return int the new committee id, or 0
	 */
	public static function create(array $fields): int
	{
		if (!OrgSchema::ready()) {
			return 0;
		}

		$name = trim((string) ($fields['name'] ?? ''));

		if ($name === '') {
			return 0;
		}

		$inserted = DBA::insert(OrgSchema::TABLE_COMMITTEE, [
			'slug'        => self::uniqueKey((string) ($fields['key'] ?? $name)),
			'name'        => $name,
			'description' => trim((string) ($fields['description'] ?? '')),
			'chair_uid'   => max(0, (int) ($fields['chair_uid'] ?? 0)),
			'visibility'  => self::sanitizeScope((string) ($fields['visibility'] ?? self::SCOPE_MEMBERS)),
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
		if (self::byId($id) === null) {
			return false;
		}

		$update = ['updated' => DateTimeFormat::utcNow()];

		if (array_key_exists('name', $fields)) {
			$name = trim((string) $fields['name']);

			if ($name !== '') {
				$update['name'] = $name;
			}
		}

		if (array_key_exists('description', $fields)) {
			$update['description'] = trim((string) $fields['description']);
		}

		if (array_key_exists('visibility', $fields)) {
			$update['visibility'] = self::sanitizeScope((string) $fields['visibility']);
		}

		if (array_key_exists('active', $fields)) {
			$update['active'] = (bool) $fields['active'];
		}

		if (array_key_exists('chair_uid', $fields)) {
			$update['chair_uid'] = max(0, (int) $fields['chair_uid']);
		}

		return DBA::update(OrgSchema::TABLE_COMMITTEE, $update, ['id' => $id]);
	}

	/**
	 * Adds somebody to a committee, or changes the office they already hold.
	 *
	 * Written as an upsert because the unique index is on (committee, person): asking twice
	 * should promote somebody, not fail.
	 */
	public static function seat(int $committeeId, int $uid, string $office = 'member'): bool
	{
		if ($committeeId <= 0 || $uid <= 0 || !OrgSchema::ready()) {
			return false;
		}

		if (self::byId($committeeId) === null) {
			return false;
		}

		$office = self::sanitizeOffice($office);

		$existing = DBA::selectFirst(OrgSchema::TABLE_COMMITTEE_MEMBER, ['id'], [
			'committee_id' => $committeeId,
			'uid'          => $uid,
		]);

		if (DBA::isResult($existing)) {
			return DBA::update(
				OrgSchema::TABLE_COMMITTEE_MEMBER,
				['office' => $office],
				['id' => (int) $existing['id']]
			);
		}

		$inserted = DBA::insert(OrgSchema::TABLE_COMMITTEE_MEMBER, [
			'committee_id' => $committeeId,
			'uid'          => $uid,
			'office'       => $office,
			'created'      => DateTimeFormat::utcNow(),
		]);

		// A chair is also the committee's recorded chair, so the two ways of asking the same
		// question cannot disagree.
		if ($inserted && $office === 'chair') {
			DBA::update(OrgSchema::TABLE_COMMITTEE, ['chair_uid' => $uid], ['id' => $committeeId]);
		}

		return $inserted;
	}

	public static function remove(int $committeeId, int $uid): bool
	{
		if ($committeeId <= 0 || $uid <= 0 || !OrgSchema::ready()) {
			return false;
		}

		$committee = self::byId($committeeId);

		if ($committee !== null && (int) $committee['chair_uid'] === $uid) {
			// Leaving the chair empty is better than leaving it pointing at somebody who is
			// no longer on the committee.
			DBA::update(OrgSchema::TABLE_COMMITTEE, ['chair_uid' => 0], ['id' => $committeeId]);
		}

		return DBA::delete(OrgSchema::TABLE_COMMITTEE_MEMBER, [
			'committee_id' => $committeeId,
			'uid'          => $uid,
		]);
	}

	/**
	 * Deletes a committee and its seats.
	 *
	 * Seats are removed first: the seat rows reference the committee, and leaving them behind
	 * would make "which committees am I on?" report a committee that no longer exists.
	 */
	public static function delete(int $id): bool
	{
		if (self::byId($id) === null) {
			return false;
		}

		DBA::delete(OrgSchema::TABLE_COMMITTEE_MEMBER, ['committee_id' => $id]);

		return DBA::delete(OrgSchema::TABLE_COMMITTEE, ['id' => $id]);
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private static function hydrate(array $row): array
	{
		$row['id']        = (int) $row['id'];
		$row['chair_uid'] = (int) $row['chair_uid'];
		$row['active']    = (bool) $row['active'];

		return $row;
	}

	private static function uniqueKey(string $source): string
	{
		$key = strtolower(trim($source));
		$key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
		$key = trim($key, '_');

		if ($key === '') {
			$key = 'committee';
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
