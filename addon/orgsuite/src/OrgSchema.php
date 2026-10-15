<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Addon-owned database tables.
 *
 * These definitions are merged into Friendica's schema through the
 * `dbstructure_definition` hook, which means `bin/console dbstructure update`
 * creates and migrates them exactly like core tables. No core file is touched and
 * no hand-written migration list has to be maintained.
 *
 * Relations are declared with "relation" rather than "foreign" on purpose: an addon
 * must be able to install its tables before/independently of foreign-key ordering,
 * and "relation" documents the link without adding a constraint that would make
 * `dbstructure` updates fragile.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\Database\DBA;

final class OrgSchema
{
	/** Cached answer to "have this addon's tables been created yet?". */
	private static ?bool $ready = null;

	/**
	 * Whether this addon's tables exist.
	 *
	 * The tables are created by `bin/console dbstructure update`, or automatically when the
	 * addon is enabled. Every repository checks this first, so a half-installed addon shows a
	 * clear warning in the console instead of throwing database errors on every page load.
	 */
	public static function ready(): bool
	{
		if (self::$ready === null) {
			self::$ready = \Friendica\Database\DBStructure::existsTable(self::TABLE_MEMBER);
		}

		return self::$ready;
	}

	/**
	 * Brings stored data in line with the current schema. Safe to run repeatedly.
	 *
	 * Two things have to happen whenever the tables are created or updated, and both are
	 * idempotent by design:
	 *
	 *  1. the built-in positions are seeded, so a new organization has something to assign;
	 *  2. members created before positions became rows get a role id, so an upgrade cannot
	 *     leave anybody with no permissions at all - which would look like the upgrade having
	 *     silently removed their access.
	 *
	 * @return array{seeded: int, moved: int}
	 */
	public static function migrate(): array
	{
		if (!self::ready()) {
			return ['seeded' => 0, 'moved' => 0];
		}

		$seeded = RoleRepository::seed();
		$moved  = 0;

		// `role_id` is 0 exactly for records written before positions were rows.
		$members = DBA::selectToArray(self::TABLE_MEMBER, ['uid', 'role'], ['`role_id` = 0']);

		foreach ($members as $member) {
			$role = RoleRepository::byKey((string) $member['role']);

			if ($role === null) {
				// A role key that no longer exists (an edited or retired built-in). Falling back
				// to the default position keeps the person a member rather than a ghost.
				$role = RoleRepository::defaultRole();
			}

			if ($role === null) {
				continue;
			}

			if (DBA::update(self::TABLE_MEMBER, [
				'role_id' => $role['id'],
				'role'    => $role['key'],
			], ['uid' => (int) $member['uid']])) {
				$moved++;
			}
		}

		return ['seeded' => $seeded, 'moved' => $moved];
	}

	/**
	 * Resets the cached schema check, so the console can re-check after repairing the schema.
	 */
	public static function resetReadyCache(): void
	{
		self::$ready = null;
	}

	public const TABLE_MEMBER        = 'orgsuite_member';
	public const TABLE_ROSTER        = 'orgsuite_roster';
	public const TABLE_VERIFICATION  = 'orgsuite_verification';
	public const TABLE_FOLDER        = 'orgsuite_folder';
	public const TABLE_DOCUMENT      = 'orgsuite_document';
	public const TABLE_CHARGE        = 'orgsuite_charge';
	public const TABLE_PAYMENT       = 'orgsuite_payment';
	public const TABLE_ANNOUNCEMENT  = 'orgsuite_announcement';
	public const TABLE_AUDIT         = 'orgsuite_audit';
	public const TABLE_ROLE          = 'orgsuite_role';
	public const TABLE_COMMITTEE     = 'orgsuite_committee';
	public const TABLE_COMMITTEE_MEMBER = 'orgsuite_committee_member';

	/**
	 * All tables this addon owns.
	 *
	 * @return string[]
	 */
	public static function tables(): array
	{
		return [
			self::TABLE_MEMBER,
			self::TABLE_ROSTER,
			self::TABLE_VERIFICATION,
			self::TABLE_FOLDER,
			self::TABLE_DOCUMENT,
			self::TABLE_CHARGE,
			self::TABLE_PAYMENT,
			self::TABLE_ANNOUNCEMENT,
			self::TABLE_AUDIT,
			self::TABLE_ROLE,
			self::TABLE_COMMITTEE,
			self::TABLE_COMMITTEE_MEMBER,
		];
	}

	/**
	 * Schema definition in Friendica's static/dbstructure.config.php format.
	 */
	public static function definition(): array
	{
		return [
			self::TABLE_MEMBER => [
				'comment' => 'Organization membership record for a local account',
				'fields'  => [
					'id'            => ['type' => 'int unsigned', 'not null' => '1', 'extra' => 'auto_increment', 'primary' => '1', 'comment' => 'sequential ID'],
					'uid'           => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'relation' => ['user' => 'uid'], 'comment' => 'Local user id this record belongs to'],
					'role'          => ['type' => 'varchar(32)', 'not null' => '1', 'default' => 'member', 'comment' => 'Key of the built-in role, kept so existing records keep working'],
					'role_id'       => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'Assigned role row; 0 means fall back to `role`'],
					'status'        => ['type' => 'varchar(16)', 'not null' => '1', 'default' => 'pending', 'comment' => 'pending, active, suspended or former'],
					'unit'          => ['type' => 'varchar(128)', 'not null' => '1', 'default' => '', 'comment' => 'Unit / lot / household identifier'],
					'membership_id' => ['type' => 'varchar(128)', 'not null' => '1', 'default' => '', 'comment' => 'External membership identifier'],
					'household'     => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'Household or family name for the directory'],
					'directory'     => ['type' => 'boolean', 'not null' => '1', 'default' => '1', 'comment' => 'May this member appear in the member directory'],
					'directory_note' => ['type' => 'text', 'comment' => 'Short self-description shown in the directory'],
					'verified_by'   => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'uid of the officer who verified the credential'],
					'verified_at'   => ['type' => 'datetime', 'comment' => 'When the credential was verified'],
					'created'       => ['type' => 'datetime', 'comment' => 'When the record was created'],
					'updated'       => ['type' => 'datetime', 'comment' => 'When the record was last changed'],
				],
				'indexes' => [
					'PRIMARY' => ['id'],
					'uid'     => ['UNIQUE', 'uid'],
					'status'  => ['status'],
					'role'    => ['role'],
					'role_id' => ['role_id'],
				],
			],

			self::TABLE_ROSTER => [
				'comment' => 'Pre-loaded list of valid units / membership IDs used to match new sign-ups',
				'fields'  => [
					'id'               => ['type' => 'int unsigned', 'not null' => '1', 'extra' => 'auto_increment', 'primary' => '1', 'comment' => 'sequential ID'],
					'credential'       => ['type' => 'varchar(191)', 'not null' => '1', 'default' => '', 'comment' => 'Normalised credential value as typed by an officer'],
					'credential_type'  => ['type' => 'varchar(32)', 'not null' => '1', 'default' => 'unit', 'comment' => 'unit, membership_id, address or email'],
					'label'            => ['type' => 'varchar(191)', 'not null' => '1', 'default' => '', 'comment' => 'Human readable label, e.g. the street address'],
					'household'        => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'Household / member name'],
					'default_role'     => ['type' => 'varchar(32)', 'not null' => '1', 'default' => 'member', 'comment' => 'Role granted on a successful match'],
					'claimed_uid'      => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'uid that has already claimed this entry'],
					'claimed_at'       => ['type' => 'datetime', 'comment' => 'When the entry was claimed'],
					'created'          => ['type' => 'datetime', 'comment' => 'When the entry was imported'],
				],
				'indexes' => [
					'PRIMARY'    => ['id'],
					'credential' => ['UNIQUE', 'credential'],
					'claimed'    => ['claimed_uid'],
				],
			],

			self::TABLE_VERIFICATION => [
				'comment' => 'Credential submitted at sign-up, awaiting officer review',
				'fields'  => [
					'id'             => ['type' => 'int unsigned', 'not null' => '1', 'extra' => 'auto_increment', 'primary' => '1', 'comment' => 'sequential ID'],
					'uid'            => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'Local user id once the account exists'],
					'username'       => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'Login name supplied at sign-up'],
					'nickname'       => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'Nickname supplied at sign-up'],
					'email'          => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'Email supplied at sign-up'],
					'credential_raw' => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'Credential exactly as the applicant typed it'],
					'credential'     => ['type' => 'varchar(191)', 'not null' => '1', 'default' => '', 'comment' => 'Normalised credential used for roster matching'],
					'credential_type' => ['type' => 'varchar(32)', 'not null' => '1', 'default' => 'unit', 'comment' => 'unit, membership_id, address or email'],
					'roster_id'      => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'Matching roster entry, 0 when none matched'],
					'status'         => ['type' => 'varchar(16)', 'not null' => '1', 'default' => 'pending', 'comment' => 'pending, approved or rejected'],
					'reviewed_by'    => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'uid of the reviewing officer'],
					'reviewed_at'    => ['type' => 'datetime', 'comment' => 'When the review happened'],
					'admin_note'     => ['type' => 'text', 'comment' => 'Officer note, included in the audit trail'],
					'created'        => ['type' => 'datetime', 'comment' => 'When the application was submitted'],
				],
				'indexes' => [
					'PRIMARY'    => ['id'],
					'uid'        => ['uid'],
					'status'     => ['status'],
					'credential' => ['credential'],
					'email'      => ['email'],
				],
			],

			self::TABLE_FOLDER => [
				'comment' => 'File Cabinet folder',
				'fields'  => [
					'id'          => ['type' => 'int unsigned', 'not null' => '1', 'extra' => 'auto_increment', 'primary' => '1', 'comment' => 'sequential ID'],
					'slug'        => ['type' => 'varchar(128)', 'not null' => '1', 'default' => '', 'comment' => 'URL-safe folder identifier'],
					'name'        => ['type' => 'varchar(191)', 'not null' => '1', 'default' => '', 'comment' => 'Folder name shown to members'],
					'description' => ['type' => 'text', 'comment' => 'One line explaining what belongs in this folder'],
					'visibility'  => ['type' => 'varchar(16)', 'not null' => '1', 'default' => 'members', 'comment' => 'public, members, board or admin'],
					'sort_order'  => ['type' => 'smallint unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'Display order'],
					'created'     => ['type' => 'datetime', 'comment' => 'When the folder was created'],
					'created_by'  => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'uid of the creator'],
				],
				'indexes' => [
					'PRIMARY' => ['id'],
					'slug'    => ['UNIQUE', 'slug'],
					'order'   => ['sort_order'],
				],
			],

			self::TABLE_DOCUMENT => [
				'comment' => 'File Cabinet document',
				'fields'  => [
					'id'          => ['type' => 'int unsigned', 'not null' => '1', 'extra' => 'auto_increment', 'primary' => '1', 'comment' => 'sequential ID'],
					'folder_id'   => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'relation' => [self::TABLE_FOLDER => 'id'], 'comment' => 'Owning folder'],
					'title'       => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'Document title shown to members'],
					'description' => ['type' => 'text', 'comment' => 'What this document is and who it is for'],
					'filename'    => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'Original file name'],
					'filetype'    => ['type' => 'varchar(128)', 'not null' => '1', 'default' => '', 'comment' => 'MIME type'],
					'filesize'    => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'Size in bytes'],
					'storage_ref' => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'Reference returned by the Friendica storage backend'],
					'visibility'  => ['type' => 'varchar(16)', 'not null' => '1', 'default' => 'inherit', 'comment' => 'inherit, public, members, board or admin'],
					'revision'    => ['type' => 'smallint unsigned', 'not null' => '1', 'default' => '1', 'comment' => 'Revision number'],
					'is_current'  => ['type' => 'boolean', 'not null' => '1', 'default' => '1', 'comment' => 'Is this the current revision'],
					'supersedes'  => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'Document id this revision replaces'],
					'uploaded_by' => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'uid of the uploader'],
					'created'     => ['type' => 'datetime', 'comment' => 'Upload date'],
				],
				'indexes' => [
					'PRIMARY'   => ['id'],
					'folder'    => ['folder_id'],
					'current'   => ['is_current'],
					'visibility' => ['visibility'],
				],
			],

			self::TABLE_CHARGE => [
				'comment' => 'Dues or assessment charged to a member',
				'fields'  => [
					'id'          => ['type' => 'int unsigned', 'not null' => '1', 'extra' => 'auto_increment', 'primary' => '1', 'comment' => 'sequential ID'],
					'uid'         => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'relation' => ['user' => 'uid'], 'comment' => 'Member the charge belongs to'],
					'unit'        => ['type' => 'varchar(128)', 'not null' => '1', 'default' => '', 'comment' => 'Unit snapshot at the time of billing'],
					'period'      => ['type' => 'varchar(64)', 'not null' => '1', 'default' => '', 'comment' => 'e.g. 2026 Q1 or 2026 annual'],
					'description' => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'What is being charged for'],
					'amount_cents' => ['type' => 'int', 'not null' => '1', 'default' => '0', 'comment' => 'Amount owed in cents, negative for credits'],
					'due_date'    => ['type' => 'date', 'comment' => 'Payment due date'],
					'status'      => ['type' => 'varchar(16)', 'not null' => '1', 'default' => 'open', 'comment' => 'open, paid, waived or void'],
					'created'     => ['type' => 'datetime', 'comment' => 'When the charge was raised'],
					'created_by'  => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'uid of the officer'],
				],
				'indexes' => [
					'PRIMARY' => ['id'],
					'uid'     => ['uid'],
					'status'  => ['status'],
					'period'  => ['period'],
				],
			],

			self::TABLE_PAYMENT => [
				'comment' => 'Payment recorded against a dues charge',
				'fields'  => [
					'id'           => ['type' => 'int unsigned', 'not null' => '1', 'extra' => 'auto_increment', 'primary' => '1', 'comment' => 'sequential ID'],
					'charge_id'    => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'relation' => [self::TABLE_CHARGE => 'id'], 'comment' => 'Charge being settled'],
					'uid'          => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'Member who paid'],
					'amount_cents' => ['type' => 'int', 'not null' => '1', 'default' => '0', 'comment' => 'Amount received in cents'],
					'method'       => ['type' => 'varchar(16)', 'not null' => '1', 'default' => 'other', 'comment' => 'cash, check, card, ach or other'],
					'reference'    => ['type' => 'varchar(191)', 'not null' => '1', 'default' => '', 'comment' => 'Cheque number or processor reference'],
					'paid_at'      => ['type' => 'date', 'comment' => 'Date the payment was received'],
					'note'         => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'Free-text note'],
					'recorded_by'  => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'uid of the officer who recorded it'],
					'created'      => ['type' => 'datetime', 'comment' => 'When the payment was recorded'],
				],
				'indexes' => [
					'PRIMARY' => ['id'],
					'charge'  => ['charge_id'],
					'uid'     => ['uid'],
				],
			],

			self::TABLE_ANNOUNCEMENT => [
				'comment' => 'Organization announcement banner',
				'fields'  => [
					'id'         => ['type' => 'int unsigned', 'not null' => '1', 'extra' => 'auto_increment', 'primary' => '1', 'comment' => 'sequential ID'],
					'title'      => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'Headline'],
					'body'       => ['type' => 'text', 'comment' => 'Body text, BBCode allowed'],
					'level'      => ['type' => 'varchar(16)', 'not null' => '1', 'default' => 'info', 'comment' => 'info, important or urgent'],
					'link'       => ['type' => 'varchar(255)', 'not null' => '1', 'default' => '', 'comment' => 'Optional read-more target'],
					'visibility' => ['type' => 'varchar(16)', 'not null' => '1', 'default' => 'members', 'comment' => 'public, members, board or admin'],
					'active'     => ['type' => 'boolean', 'not null' => '1', 'default' => '1', 'comment' => 'Is the banner live'],
					'starts_at'  => ['type' => 'datetime', 'comment' => 'When the banner starts showing'],
					'ends_at'    => ['type' => 'datetime', 'comment' => 'When the banner stops showing'],
					'created'    => ['type' => 'datetime', 'comment' => 'When the banner was created'],
					'created_by' => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'uid of the officer'],
				],
				'indexes' => [
					'PRIMARY' => ['id'],
					'active'  => ['active'],
				],
			],

			self::TABLE_ROLE => [
				'comment' => 'A position someone can hold, with the permissions it grants',
				'fields'  => [
					'id'          => ['type' => 'int unsigned', 'not null' => '1', 'extra' => 'auto_increment', 'primary' => '1', 'comment' => 'sequential ID'],
					'slug'        => ['type' => 'varchar(64)', 'not null' => '1', 'default' => '', 'comment' => 'Stable machine name, e.g. treasurer. Not `key`: that is a reserved word.'],
					'label'       => ['type' => 'varchar(191)', 'not null' => '1', 'default' => '', 'comment' => 'What members see, e.g. Treasurer'],
					'description' => ['type' => 'text', 'comment' => 'What this position is responsible for'],
					'rank'        => ['type' => 'smallint unsigned', 'not null' => '1', 'default' => '50', 'comment' => 'Seniority; higher ranks outrank lower ones'],
					'scope'       => ['type' => 'varchar(16)', 'not null' => '1', 'default' => 'organization', 'comment' => 'organization for members and officers, platform for host staff'],
					'permissions' => ['type' => 'text', 'comment' => 'JSON list of permission keys this position grants'],
					'is_system'   => ['type' => 'boolean', 'not null' => '1', 'default' => '0', 'comment' => 'Built-in positions cannot be deleted'],
					'is_default'  => ['type' => 'boolean', 'not null' => '1', 'default' => '0', 'comment' => 'Position given to a newly approved member'],
					'active'      => ['type' => 'boolean', 'not null' => '1', 'default' => '1', 'comment' => 'Retired positions stay for history but grant nothing'],
					'created'     => ['type' => 'datetime', 'comment' => 'When the position was created'],
					'updated'     => ['type' => 'datetime', 'comment' => 'When the position was last changed'],
				],
				'indexes' => [
					'PRIMARY'    => ['id'],
					'slug'       => ['UNIQUE', 'slug'],
					'rank'       => ['rank'],
					'scope'      => ['scope'],
					'is_default' => ['is_default'],
				],
			],

			self::TABLE_COMMITTEE => [
				'comment' => 'A standing committee of the organization, such as grounds or events',
				'fields'  => [
					'id'          => ['type' => 'int unsigned', 'not null' => '1', 'extra' => 'auto_increment', 'primary' => '1', 'comment' => 'sequential ID'],
					'slug'        => ['type' => 'varchar(64)', 'not null' => '1', 'default' => '', 'comment' => 'Stable machine name. Not `key`: that is a reserved word.'],
					'name'        => ['type' => 'varchar(191)', 'not null' => '1', 'default' => '', 'comment' => 'Display name, e.g. Architectural Review Committee'],
					'description' => ['type' => 'text', 'comment' => 'What this committee is for'],
					'chair_uid'   => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'uid of the chair, or 0 for none'],
					'visibility'  => ['type' => 'varchar(16)', 'not null' => '1', 'default' => 'members', 'comment' => 'members or board: who can see the committee and its membership'],
					'active'      => ['type' => 'boolean', 'not null' => '1', 'default' => '1', 'comment' => 'Inactive committees are kept but hidden'],
					'created'     => ['type' => 'datetime', 'comment' => 'When the committee was created'],
					'updated'     => ['type' => 'datetime', 'comment' => 'When the committee was last changed'],
				],
				'indexes' => [
					'PRIMARY' => ['id'],
					'slug'    => ['UNIQUE', 'slug'],
					'active'  => ['active'],
				],
			],

			self::TABLE_COMMITTEE_MEMBER => [
				'comment' => 'Who sits on which committee, and in what office',
				'fields'  => [
					'id'           => ['type' => 'int unsigned', 'not null' => '1', 'extra' => 'auto_increment', 'primary' => '1', 'comment' => 'sequential ID'],
					'committee_id' => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'relation' => [self::TABLE_COMMITTEE => 'id'], 'comment' => 'Owning committee'],
					'uid'          => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'relation' => ['user' => 'uid'], 'comment' => 'Local user on the committee'],
					'office'       => ['type' => 'varchar(32)', 'not null' => '1', 'default' => 'member', 'comment' => 'chair, vice, secretary or member'],
					'created'      => ['type' => 'datetime', 'comment' => 'When they joined'],
				],
				'indexes' => [
					'PRIMARY'    => ['id'],
					'seat'       => ['UNIQUE', 'committee_id', 'uid'],
					'uid'        => ['uid'],
				],
			],

			self::TABLE_AUDIT => [
				'comment' => 'Audit trail of organization actions',
				'fields'  => [
					'id'          => ['type' => 'int unsigned', 'not null' => '1', 'extra' => 'auto_increment', 'primary' => '1', 'comment' => 'sequential ID'],
					'actor_uid'   => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'uid of the acting user, 0 for anonymous'],
					'action'      => ['type' => 'varchar(64)', 'not null' => '1', 'default' => '', 'comment' => 'e.g. verification.approve, dues.charge.create'],
					'target_type' => ['type' => 'varchar(64)', 'not null' => '1', 'default' => '', 'comment' => 'Table or concept the action applied to'],
					'target_id'   => ['type' => 'int unsigned', 'not null' => '1', 'default' => '0', 'comment' => 'Row id the action applied to'],
					'detail'      => ['type' => 'text', 'comment' => 'JSON or plain text detail'],
					'created'     => ['type' => 'datetime', 'comment' => 'When the action happened'],
				],
				'indexes' => [
					'PRIMARY' => ['id'],
					'actor'   => ['actor_uid'],
					'action'  => ['action'],
					'created' => ['created'],
				],
			],
		];
	}
}
