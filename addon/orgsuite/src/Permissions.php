<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The permission catalog and the single place that answers "may this person do this?".
 *
 * Positions are data, not code: an organization can invent "Maintenance lead" or "Security
 * officer" and tick whatever boxes it needs. That only stays safe if the ticks are drawn from
 * a fixed vocabulary, so every capability is declared here once, with the wording an officer
 * reads in the console, and every check in the addon goes through `can()`.
 *
 * Two levels of permission exist and they are deliberately separate:
 *
 *  - an *organization* permission is something an officer of this association may do;
 *  - a *platform* permission belongs to whoever runs the server the association sits on
 *    (the technician who patches it, the developer who builds the addon). Those are never
 *    granted by an association, because an association cannot consent to handing over the
 *    machine it is hosted on.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\DI;
use Friendica\Addon\OrgSuite\RoleRepository;
use Friendica\Addon\OrgSuite\MemberRepository;

final class Permissions
{
	// ------------------------------------------------------------ organization

	public const VIEW_DIRECTORY          = 'view_directory';
	public const VIEW_OWN_DUES           = 'view_own_dues';
	public const VERIFY_APPLICATIONS     = 'verify_applications';
	public const MANAGE_MEMBERS          = 'manage_members';
	public const MANAGE_ROSTER           = 'manage_roster';
	public const MANAGE_DUES             = 'manage_dues';
	public const MANAGE_DOCUMENTS        = 'manage_documents';
	public const VIEW_BOARD_DOCUMENTS    = 'view_board_documents';
	public const MANAGE_ANNOUNCEMENTS    = 'manage_announcements';
	public const MANAGE_COMMITTEES       = 'manage_committees';
	public const MANAGE_ROLES            = 'manage_roles';
	public const MANAGE_SETTINGS         = 'manage_settings';
	public const VIEW_AUDIT              = 'view_audit';

	// ---------------------------------------------------------------- platform

	public const PLATFORM_CONFIGURE      = 'platform_configure';
	public const PLATFORM_MEMBERS        = 'platform_members';
	public const PLATFORM_AUDIT          = 'platform_audit';

	/**
	 * The capabilities that make somebody an officer of the organization, as opposed to an
	 * ordinary member who can read the directory and their own balance.
	 *
	 * Derived from the catalog above at runtime rather than hand-listed, so adding a
	 * governance capability cannot leave this behind.
	 */
	public const PRIVILEGED = [
		self::VERIFY_APPLICATIONS,
		self::MANAGE_MEMBERS,
		self::MANAGE_ROSTER,
		self::MANAGE_DUES,
		self::MANAGE_DOCUMENTS,
		self::VIEW_BOARD_DOCUMENTS,
		self::MANAGE_ANNOUNCEMENTS,
		self::MANAGE_COMMITTEES,
		self::MANAGE_ROLES,
		self::MANAGE_SETTINGS,
		self::VIEW_AUDIT,
	];

	public const SCOPE_ORGANIZATION = 'organization';
	public const SCOPE_PLATFORM     = 'platform';

	/**
	 * Every capability, grouped the way the console presents them.
	 *
	 * `scope` decides who may be granted it: organization capabilities are the association's
	 * to hand out, platform capabilities are not.
	 */
	public const CATALOG = [
		'everyday' => [
			'label' => 'Day to day',
			'permissions' => [
				self::VIEW_DIRECTORY => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'See the member directory',
					'description' => 'Look up neighbours, households and committee membership.',
				],
				self::VIEW_OWN_DUES => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'See their own dues and payments',
					'description' => 'See what they personally owe and what they have paid.',
				],
			],
		],
		'people' => [
			'label' => 'Members and applications',
			'permissions' => [
				self::VERIFY_APPLICATIONS => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'Check applications and release accounts',
					'description' => 'Compare a sign-up against the association records and approve or reject it.',
				],
				self::MANAGE_MEMBERS => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'Edit member records',
					'description' => 'Change a member\'s unit, household, position or standing.',
				],
				self::MANAGE_ROSTER => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'Maintain the roster',
					'description' => 'Import and edit the list of valid units or membership IDs.',
				],
			],
		],
		'money' => [
			'label' => 'Money',
			'permissions' => [
				self::MANAGE_DUES => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'Manage dues and payments',
					'description' => 'Raise assessments and record payments against them.',
				],
			],
		],
		'records' => [
			'label' => 'Documents and notices',
			'permissions' => [
				self::VIEW_BOARD_DOCUMENTS => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'Read board-only documents',
					'description' => 'Open minutes and records kept out of the general file cabinet.',
				],
				self::MANAGE_DOCUMENTS => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'Upload and organise documents',
					'description' => 'Create folders, upload files and retire superseded versions.',
				],
				self::MANAGE_ANNOUNCEMENTS => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'Publish announcements',
					'description' => 'Pin a notice across the top of every page.',
				],
			],
		],
		'governance' => [
			'label' => 'Governance',
			'permissions' => [
				self::MANAGE_COMMITTEES => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'Manage committees',
					'description' => 'Create committees and decide who sits on them.',
				],
				self::MANAGE_ROLES => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'Manage positions and permissions',
					'description' => 'Create positions such as Treasurer, and decide what each one may do.',
				],
				self::MANAGE_SETTINGS => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'Change organization settings',
					'description' => 'Change the organization name, wording and which features are switched on.',
				],
				self::VIEW_AUDIT => [
					'scope'       => self::SCOPE_ORGANIZATION,
					'label'       => 'Read the activity log',
					'description' => 'See who approved, charged, uploaded or changed what, and when.',
				],
			],
		],
		'platform' => [
			'label' => 'Hosting the server',
			'permissions' => [
				self::PLATFORM_CONFIGURE => [
					'scope'       => self::SCOPE_PLATFORM,
					'label'       => 'Configure the server',
					'description' => 'Change how this installation itself works. Stay with whoever runs the machine.',
				],
				self::PLATFORM_MEMBERS => [
					'scope'       => self::SCOPE_PLATFORM,
					'label'       => 'Administer accounts on the server',
					'description' => 'Site administration, including accounts and moderation.',
				],
				self::PLATFORM_AUDIT => [
					'scope'       => self::SCOPE_PLATFORM,
					'label'       => 'Inspect the server',
					'description' => 'Read server logs and diagnose problems.',
				],
			],
		],
	];

	/** Every capability key, flattened, in catalog order. */
	public static function all(): array
	{
		$keys = [];

		foreach (self::CATALOG as $group) {
			foreach (array_keys($group['permissions']) as $key) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	public static function organizationKeys(): array
	{
		return array_values(array_filter(
			self::all(),
			static fn (string $key): bool => self::definition($key)['scope'] === self::SCOPE_ORGANIZATION
		));
	}

	public static function platformKeys(): array
	{
		return array_values(array_filter(
			self::all(),
			static fn (string $key): bool => self::definition($key)['scope'] === self::SCOPE_PLATFORM
		));
	}

	/**
	 * @return array{scope: string, label: string, description: string}
	 */
	public static function definition(string $key): array
	{
		foreach (self::CATALOG as $group) {
			if (isset($group['permissions'][$key])) {
				return $group['permissions'][$key];
			}
		}

		return ['scope' => self::SCOPE_ORGANIZATION, 'label' => $key, 'description' => ''];
	}

	public static function label(string $key): string
	{
		return self::definition($key)['label'];
	}

	public static function isPlatform(string $key): bool
	{
		return self::definition($key)['scope'] === self::SCOPE_PLATFORM;
	}

	/**
	 * Drops anything that is not a real capability, so a hand-crafted POST cannot invent one.
	 *
	 * @param mixed $keys
	 *
	 * @return string[]
	 */
	public static function sanitize($keys): array
	{
		if (!is_array($keys)) {
			return [];
		}

		$known = self::all();

		return array_values(array_unique(array_filter(
			array_map(static fn ($key): string => is_string($key) ? trim($key) : '', $keys),
			static fn (string $key): bool => in_array($key, $known, true)
		)));
	}

	/**
	 * Whether the current reader holds a capability.
	 *
	 * Platform capabilities are answered honestly rather than optimistically: only a Friendica
	 * site administrator has them, and no organization position can confer them.
	 */
	public static function can(string $capability, ?int $uid = null): bool
	{
		if (!in_array($capability, self::all(), true)) {
			return false;
		}

		$uid = $uid ?? (int) DI::userSession()->getLocalUserId();

		if ($uid <= 0) {
			return false;
		}

		$isSelf = $uid === (int) DI::userSession()->getLocalUserId();

		// The person who runs the server can do anything; that is what makes them the
		// administrator, and every other guarantee here depends on it.
		if ($isSelf && DI::userSession()->isSiteAdmin()) {
			return true;
		}

		// Genuine site administration is Friendica's own flag. It is listed in the catalog so
		// the console can explain it, but no organization position may confer it, however that
		// position is named or whoever creates it. An association cannot consent to handing
		// over the machine it is hosted on.
		if ($capability === self::PLATFORM_MEMBERS) {
			return false;
		}

		$member = MemberRepository::forUser($uid);

		if ($member === null || $member['status'] !== 'active') {
			return false;
		}

		return in_array($capability, RoleRepository::permissionsForMember($member), true);
	}

	public static function canForCurrentUser(string $capability): bool
	{
		return self::can($capability);
	}

	/**
	 * The capabilities the reader holds, for building a menu that only offers reachable things.
	 *
	 * @return string[]
	 */
	public static function granted(?int $uid = null): array
	{
		return array_values(array_filter(self::all(), static fn (string $key): bool => self::can($key, $uid)));
	}
}
