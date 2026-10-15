<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Per-instance configuration and feature-flag layer for the organization suite.
 *
 * Everything in this class reads and writes the node-wide `orgsuite` config category.
 * Because Friendica is single-tenant (one installation = one community), these flags are
 * exactly the "turn community / club / HOA features on or off for this instance" switch.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\Core\Config\Capability\IManageConfigValues;
use Friendica\DI;
use Friendica\Addon\OrgSuite\Permissions;
use Friendica\Addon\OrgSuite\RoleRepository;
use Friendica\Addon\OrgSuite\OrgSchema;

final class OrgConfig
{
	/** Node-wide config category used for every setting this addon owns. */
	public const CATEGORY = 'orgsuite';

	public const PROFILE_HOA       = 'hoa';
	public const PROFILE_CLUB      = 'club';
	public const PROFILE_NONPROFIT = 'nonprofit';
	public const PROFILE_COMMUNITY = 'community';

	public const PROFILES = [
		self::PROFILE_HOA,
		self::PROFILE_CLUB,
		self::PROFILE_NONPROFIT,
		self::PROFILE_COMMUNITY,
	];

	public const PROFILE_LABELS = [
		self::PROFILE_HOA       => 'Homeowners association (HOA)',
		self::PROFILE_CLUB      => 'Club or society',
		self::PROFILE_NONPROFIT => 'Non-profit organization',
		self::PROFILE_COMMUNITY => 'Neighborhood or general community',
	];

	public const PROFILE_DESCRIPTIONS = [
		self::PROFILE_HOA       => 'Units, owners and tenants, assessments, board-only records, architectural requests.',
		self::PROFILE_CLUB      => 'Individual and family memberships, renewals, rosters, volunteer sign-ups.',
		self::PROFILE_NONPROFIT => 'Members, donors and grants, board records, public transparency documents.',
		self::PROFILE_COMMUNITY => 'Lightweight: announcements, a document library and a member directory.',
	];

	public const FEATURE_DASHBOARD           = 'dashboard';
	public const FEATURE_MEMBERS             = 'members';
	public const FEATURE_GATED_REGISTRATION  = 'gated_registration';
	public const FEATURE_ROSTER              = 'roster';
	public const FEATURE_VAULT               = 'vault';
	public const FEATURE_DUES                = 'dues';
	public const FEATURE_ANNOUNCEMENTS       = 'announcements';

	/**
	 * The feature catalog.
	 *
	 * `defaults` decides what a brand-new instance gets for each profile, so an admin
	 * picking "Club" immediately gets a working club setup and can still override any
	 * individual switch afterwards.
	 *
	 * `requires` is enforced by @see self::isFeatureEnabled(): a feature whose dependency
	 * is switched off reports itself as off rather than half-working.
	 */
	public const FEATURES = [
		self::FEATURE_DASHBOARD => [
			'label'       => 'Organization dashboard',
			'description' => 'Replace the network stream as the home page with a plain-language overview of announcements, documents and dues.',
			'requires'    => [],
			'defaults'    => [
				self::PROFILE_HOA       => true,
				self::PROFILE_CLUB      => true,
				self::PROFILE_NONPROFIT => true,
				self::PROFILE_COMMUNITY => true,
			],
		],
		self::FEATURE_MEMBERS => [
			'label'       => 'Member records and directory',
			'description' => 'Track members, units, roles and standing, and give members a directory of the people in the organization.',
			'requires'    => [],
			'defaults'    => [
				self::PROFILE_HOA       => true,
				self::PROFILE_CLUB      => true,
				self::PROFILE_NONPROFIT => true,
				self::PROFILE_COMMUNITY => false,
			],
		],
		self::FEATURE_GATED_REGISTRATION => [
			'label'       => 'Gated registration',
			'description' => 'Require a property address, unit number or membership ID at sign-up; an officer verifies it before the account is usable.',
			'requires'    => [self::FEATURE_MEMBERS],
			'defaults'    => [
				self::PROFILE_HOA       => true,
				self::PROFILE_CLUB      => true,
				self::PROFILE_NONPROFIT => true,
				self::PROFILE_COMMUNITY => false,
			],
		],
		self::FEATURE_ROSTER => [
			'label'       => 'Pre-loaded roster',
			'description' => 'Import the list of valid units or membership IDs up front so sign-ups can be matched automatically instead of reviewed one by one.',
			'requires'    => [self::FEATURE_GATED_REGISTRATION],
			'defaults'    => [
				self::PROFILE_HOA       => true,
				self::PROFILE_CLUB      => false,
				self::PROFILE_NONPROFIT => false,
				self::PROFILE_COMMUNITY => false,
			],
		],
		self::FEATURE_VAULT => [
			'label'       => 'Document vault (File Cabinet)',
			'description' => 'Structured folders for bylaws, minutes, budgets and forms, with per-folder access control, instead of burying files in the feed.',
			'requires'    => [],
			'defaults'    => [
				self::PROFILE_HOA       => true,
				self::PROFILE_CLUB      => true,
				self::PROFILE_NONPROFIT => true,
				self::PROFILE_COMMUNITY => true,
			],
		],
		self::FEATURE_DUES => [
			'label'       => 'Dues and assessments',
			'description' => 'Record what each member owes and what has been paid, with a simple ledger rather than a full accounting package.',
			'requires'    => [self::FEATURE_MEMBERS],
			'defaults'    => [
				self::PROFILE_HOA       => true,
				self::PROFILE_CLUB      => true,
				self::PROFILE_NONPROFIT => false,
				self::PROFILE_COMMUNITY => false,
			],
		],
		self::FEATURE_ANNOUNCEMENTS => [
			'label'       => 'Announcement banners',
			'description' => 'Officers can pin a notice across the top of every page for as long as it matters.',
			'requires'    => [],
			'defaults'    => [
				self::PROFILE_HOA       => true,
				self::PROFILE_CLUB      => true,
				self::PROFILE_NONPROFIT => true,
				self::PROFILE_COMMUNITY => true,
			],
		],
	];

	/** Roles an officer can assign to a member, ordered from least to most privileged. */
	public const ROLES = ['member', 'resident', 'owner', 'board', 'treasurer', 'president'];

	/** Roles allowed to administer organization data (verification, dues, document upload). */
	public const OFFICER_ROLES = ['board', 'treasurer', 'president'];

	public const ROLE_LABELS = [
		'member'    => 'Member',
		'resident'  => 'Resident',
		'owner'     => 'Owner',
		'board'     => 'Board member',
		'treasurer' => 'Treasurer',
		'president' => 'President',
	];

	public const STATUS_LABELS = [
		'pending'   => 'Awaiting verification',
		'active'    => 'Active',
		'suspended' => 'Suspended',
		'former'    => 'Former member',
	];

	private static function config(): IManageConfigValues
	{
		return DI::config();
	}

	/** Cached contents of config/orgsuite.config.php. */
	private static ?array $fileDefaults = null;

	/**
	 * Optional per-instance defaults file.
	 *
	 * `config/orgsuite.config.php` may return an array of settings. It is read as a fallback
	 * whenever a value has not been stored in the database, which lets the provisioning tool
	 * create an organization with the right profile and feature set before anybody has logged
	 * in, while the officer console still wins once an officer changes something.
	 *
	 * @return array<string, mixed>
	 */
	private static function fileDefaults(): array
	{
		if (self::$fileDefaults === null) {
			$path = DI::basePath() . '/config/orgsuite.config.php';

			$data = is_file($path) ? include $path : [];

			self::$fileDefaults = is_array($data) ? $data : [];
		}

		return self::$fileDefaults;
	}

	/**
	 * Reads a setting: stored value first, then the per-instance file, then the caller default.
	 */
	public static function get(string $key, $default = null)
	{
		$value = self::config()->get(self::CATEGORY, $key, null);

		if ($value !== null) {
			return $value;
		}

		$fileDefaults = self::fileDefaults();

		return $fileDefaults[$key] ?? $default;
	}

	public static function set(string $key, $value): void
	{
		self::config()->set(self::CATEGORY, $key, $value);
	}

	public static function delete(string $key): void
	{
		self::config()->delete(self::CATEGORY, $key);
	}

	/**
	 * The organization profile chosen for this instance.
	 *
	 * Determines the default feature set and the wording used throughout the UI
	 * (units, membership IDs, house rules, ...).
	 */
	public static function getProfile(): string
	{
		$profile = (string) self::get('profile', self::PROFILE_HOA);

		return in_array($profile, self::PROFILES, true) ? $profile : self::PROFILE_HOA;
	}

	public static function setProfile(string $profile): void
	{
		if (!in_array($profile, self::PROFILES, true)) {
			$profile = self::PROFILE_HOA;
		}

		self::set('profile', $profile);

		// Re-seed every switch from the new profile so the instance is internally
		// consistent immediately after switching, then let the admin fine-tune.
		self::setFeaturesFromProfile($profile);
	}

	/**
	 * The stored value for a single feature switch, before dependency resolution.
	 */
	public static function getStoredFeature(string $feature): bool
	{
		$default = self::getFeatureDefault($feature, self::getProfile());

		return (bool) self::get('feature_' . $feature, $default);
	}

	public static function getFeatureDefault(string $feature, string $profile): bool
	{
		if (!isset(self::FEATURES[$feature])) {
			return false;
		}

		$defaults = self::FEATURES[$feature]['defaults'];

		return (bool) ($defaults[$profile] ?? false);
	}

	/**
	 * Whether a feature is usable on this instance.
	 *
	 * Returns false when the feature is switched off *or* when something it depends on
	 * is switched off, so callers never have to reason about partial states.
	 */
	public static function isFeatureEnabled(string $feature): bool
	{
		if (!isset(self::FEATURES[$feature])) {
			return false;
		}

		if (!self::getStoredFeature($feature)) {
			return false;
		}

		foreach (self::FEATURES[$feature]['requires'] as $required) {
			if (!self::isFeatureEnabled($required)) {
				return false;
			}
		}

		return true;
	}

	public static function setFeature(string $feature, bool $enabled): void
	{
		if (!isset(self::FEATURES[$feature])) {
			return;
		}

		self::set('feature_' . $feature, $enabled);
	}

	public static function setFeaturesFromProfile(?string $profile = null): void
	{
		$profile = $profile ?? self::getProfile();

		foreach (array_keys(self::FEATURES) as $feature) {
			self::setFeature($feature, self::getFeatureDefault($feature, $profile));
		}
	}

	/**
	 * Feature switch states as a lookup table, including resolved dependencies.
	 *
	 * @return array<string, array{label: string, description: string, enabled: bool, stored: bool, blocked_by: string[]}>
	 */
	public static function getFeatureStates(): array
	{
		$states = [];

		foreach (self::FEATURES as $feature => $meta) {
			$blockedBy = [];

			if (self::getStoredFeature($feature)) {
				foreach ($meta['requires'] as $required) {
					if (!self::isFeatureEnabled($required)) {
						$blockedBy[] = $required;
					}
				}
			}

			$states[$feature] = [
				'label'       => $meta['label'],
				'description' => $meta['description'],
				'enabled'     => self::isFeatureEnabled($feature),
				'stored'      => self::getStoredFeature($feature),
				'blocked_by'  => $blockedBy,
			];
		}

		return $states;
	}

	/**
	 * Organization display name, falling back to the node name.
	 */
	public static function getOrgName(): string
	{
		$name = trim((string) self::get('org_name', ''));

		return $name !== '' ? $name : (string) DI::config()->get('config', 'sitename', '');
	}

	public static function getOrgTagline(): string
	{
		return trim((string) self::get('org_tagline', ''));
	}

	/** The label this organization uses for a member's identifier, e.g. "Unit number". */
	public static function getCredentialLabel(): string
	{
		$label = trim((string) self::get('credential_label', ''));

		if ($label !== '') {
			return $label;
		}

		return match (self::getProfile()) {
			self::PROFILE_HOA       => 'Property address or unit number',
			self::PROFILE_CLUB      => 'Membership ID',
			self::PROFILE_NONPROFIT => 'Membership ID or organisation',
			default                 => 'Property address or membership ID',
		};
	}

	/** Longer explanation shown under the sign-up field. */
	public static function getCredentialHelp(): string
	{
		$help = trim((string) self::get('credential_help', ''));

		if ($help !== '') {
			return $help;
		}

		return match (self::getProfile()) {
			self::PROFILE_HOA       => 'Enter the address or unit you own or rent. An officer checks this against association records before activating your account.',
			self::PROFILE_CLUB      => 'Enter the membership ID from your renewal notice. An officer checks it before activating your account.',
			self::PROFILE_NONPROFIT => 'Enter your membership ID or the organisation you represent. An officer checks it before activating your account.',
			default                 => 'Enter the address or membership ID we have on file. An officer checks it before activating your account.',
		};
	}

	/**
	 * Whether an officer must manually approve every sign-up even when the roster matches.
	 */
	public static function isManualReviewRequired(): bool
	{
		return (bool) self::get('manual_review', true);
	}

	/**
	 * Whether a sign-up whose credential is not on the roster is rejected outright.
	 * Only meaningful with the roster feature enabled.
	 */
	public static function isRosterStrict(): bool
	{
		return (bool) self::get('roster_strict', false);
	}

	/** Hide the federated-network navigation from members by default, unless switched back on. */
	public static function isStreamFirst(): bool
	{
		return (bool) self::get('stream_first', false);
	}

	/**
	 * Nav keys hidden from ordinary members when the instance is not stream-first.
	 *
	 * These are real keys from Friendica\Content\Nav::getInfo().
	 *
	 * @return string[]
	 */
	public static function getHiddenNavKeys(): array
	{
		if (self::isStreamFirst() || !self::isFeatureEnabled(self::FEATURE_DASHBOARD)) {
			return [];
		}

		// Keys that are pure Fediverse vocabulary on an organization's instance. `home`,
		// `network`, `community` and `apps` are re-purposed rather than hidden, and account
		// `settings` always stays reachable so a member can change their own password.
		return [
			'directory',
			'navigation',
			'contacts',
			'delegation',
			'channel',
			'calendar',
			'introductions',
		];
	}

	/**
	 * Whether the current reader holds a position that can administer the organization.
	 *
	 * This used to compare against a fixed list of three role strings, which meant an
	 * organization could not invent a position that mattered. It now asks the positions
	 * system, so a custom "Maintenance lead" with the right permissions counts.
	 */
	public static function isOfficer(): bool
	{
		$member = MemberRepository::forCurrentUser();

		if ($member === null || $member['status'] !== 'active') {
			return false;
		}

		if (!OrgSchema::ready()) {
			// Before the tables exist there are no positions to consult; fall back to the
			// built-in keys so the addon does not lock everybody out of its own installer.
			return in_array((string) $member['role'], self::OFFICER_ROLES, true);
		}

		return RoleRepository::isOfficerMember($member);
	}

	/** Whether the current reader may do something specific. */
	public static function can(string $capability): bool
	{
		return Permissions::can($capability);
	}
}
