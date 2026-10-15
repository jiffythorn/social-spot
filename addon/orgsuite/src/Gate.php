<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gated registration.
 *
 * The requirement is that a new account must present a property address, unit number or
 * membership ID that an officer cross-references before the account becomes usable. This
 * class is the whole of that behaviour and it never patches core:
 *
 *  - the field arrives through a standards-based `<input form="register-form">` injected
 *    just above the sign-up form, which works in any theme and without JavaScript. A theme
 *    that would rather place the field itself can do so by including the marker string in
 *    its own register.tpl, and themeRendersField() detects that and stops injecting. As
 *    shipped, no bundled theme does, so injection is the path every instance uses;
 *  - `register_post` validates the credential and, when it is unusable, redirects back to
 *    the sign-up page, which aborts account creation entirely;
 *  - `register_account` binds the stored credential to the account Friendica just created;
 *  - officers then review the credential in the console, and only an explicit approval
 *    releases the account.
 *
 * Nothing here trusts the browser: the injected/rendered field only carries a string, and
 * the server re-validates it, re-normalises it and re-checks the roster on every request.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\DI;
use Friendica\Model\Register;
// The registration *policy* and its constants live on the module; the pending-registration
// records live on the model. They are different classes with the same short name, which is
// how `Register::getPolicy()` came to be called on a class that has no such method and broke
// the console's overview page.
use Friendica\Module\Register as RegisterPolicy;
use Friendica\Model\User;

final class Gate
{
	public const FIELD_NAME = 'orgsuite_credential';

	/** The marker the addon looks for inside an effective register.tpl. */
	public const TEMPLATE_MARKER = 'orgsuite_credential';

	public const SESSION_KEY = 'orgsuite_verification_id';

	/** Cached answer for "does the active theme render the credential field itself?". */
	private static ?bool $themeRendersField = null;

	/**
	 * Whether gated registration is switched on for this instance.
	 */
	public static function isEnabled(): bool
	{
		return OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_GATED_REGISTRATION);
	}

	/**
	 * Whether Friendica will actually hold a new account until an officer releases it.
	 *
	 * This is deliberately not the same question as "what is register_policy set to".
	 * Friendica derives the *effective* policy with Register::getPolicy(), and that method
	 * returns CLOSED whenever no site administrator has signed in within
	 * `system.admin_inactivity_limit` days, regardless of configuration. A freshly
	 * provisioned organization hits this every time: the administrator account exists but
	 * has never logged in, so sign-ups are closed and the gate has nothing to guard.
	 *
	 * Naming the real cause matters because the three failure modes need three different
	 * actions from the reader.
	 *
	 * @return array{configured:int,effective:int,problem:?string,detail:string,fix:?string}
	 */
	public static function registrationDiagnosis(): array
	{
		$configured = (int) DI::config()->get('config', 'register_policy');
		$effective  = RegisterPolicy::getPolicy();

		$diagnosis = [
			'configured' => $configured,
			'effective'  => $effective,
			'problem'    => null,
			'detail'     => '',
			'fix'        => null,
		];

		if ($effective === RegisterPolicy::APPROVE) {
			return $diagnosis;
		}

		if ($configured === RegisterPolicy::APPROVE) {
			// The setting is already correct, so Friendica must be overriding it.
			$days = (int) DI::config()->get('system', 'admin_inactivity_limit');

			$diagnosis['problem'] = 'overridden_by_inactive_admins';
			$diagnosis['detail']  = 'Sign-ups are closed even though this instance is configured to require '
				. 'approval. Friendica closes registration on its own when no site administrator has signed in '
				. 'for ' . ($days > 0 ? $days . ' days' : 'the configured period')
				. ', and this instance\'s administrator has not signed in yet. '
				. 'Sign in as the site administrator to open registration again; no setting needs changing.';
			$diagnosis['fix'] = 'sign_in_as_admin';

			return $diagnosis;
		}

		if ($effective === RegisterPolicy::OPEN) {
			$diagnosis['problem'] = 'open_without_approval';
			$diagnosis['detail']  = 'Anyone who can reach the sign-up page can create an account that works '
				. 'immediately, so the credential is not checked before the account is usable.';
			$diagnosis['fix'] = 'require_approval';

			return $diagnosis;
		}

		$diagnosis['problem'] = 'closed';
		$diagnosis['detail']  = 'Registration is closed, so nobody can apply to join through the sign-up page.';
		$diagnosis['fix']     = 'require_approval';

		return $diagnosis;
	}

	/**
	 * Switches Friendica's registration policy to "approval required".
	 *
	 * Only ever called after @see self::registrationDiagnosis() reported a fixable policy,
	 * because on an instance where Friendica has overridden the policy the stored value is
	 * already correct and writing it again would change nothing while appearing to help.
	 *
	 * @return bool whether the policy was actually changed
	 */
	public static function requireApproval(): bool
	{
		if ((int) DI::config()->get('config', 'register_policy') === RegisterPolicy::APPROVE) {
			return false;
		}

		DI::config()->set('config', 'register_policy', RegisterPolicy::APPROVE);

		return true;
	}

	/**
	 * Whether the credential field is rendered by the active theme rather than injected.
	 */
	public static function themeRendersField(): bool
	{
		if (self::$themeRendersField === null) {
			$template = self::effectiveRegisterTemplate();

			self::$themeRendersField = $template !== ''
				&& str_contains((string) @file_get_contents($template), self::TEMPLATE_MARKER);
		}

		return self::$themeRendersField;
	}

	public static function resetTemplateCache(): void
	{
		self::$themeRendersField = null;
	}

	/**
	 * Resolves the register.tpl that will actually be used, following Friendica's own search
	 * order: active theme -> parent theme -> shared view directory.
	 *
	 * The order mirrors FriendicaSmartyEngine::getTemplateFile() and
	 * Friendica\Core\Theme::getPathForFile(), which is what makes the check meaningful.
	 */
	public static function effectiveRegisterTemplate(): string
	{
		$appHelper = DI::appHelper();
		$theme     = $appHelper->getCurrentTheme();
		$parent    = (string) $appHelper->getThemeInfoValue('extends', '');

		$candidates = [
			'view/theme/' . $theme . '/templates/register.tpl',
			'view/theme/' . $parent . '/templates/register.tpl',
			'view/templates/register.tpl',
		];

		foreach ($candidates as $candidate) {
			if ($parent === '' && str_contains($candidate, '/templates/register.tpl') && str_contains($candidate, 'view/theme//')) {
				continue;
			}

			if (is_file($candidate)) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * HTML for the credential field, used both by the injected fallback and available to any
	 * theme that wants to render it through a hook instead of a template override.
	 */
	public static function fieldHtml(bool $asFormAssociated = false): string
	{
		$name  = self::FIELD_NAME;
		$label = OrgConfig::getCredentialLabel();
		$help  = OrgConfig::getCredentialHelp();

		// When the field lives outside the <form>, associate it explicitly. This is standard
		// HTML and needs no JavaScript, which keeps the gate working in every theme.
		$formAttribute = $asFormAssociated ? ' form="register-form"' : '';

		return '<div class="orgsuite-credential-field">'
			. '<label for="' . $name . '">' . Text::h($label) . ' <span class="orgsuite-required">*</span></label>'
			. '<input type="text" name="' . $name . '" id="' . $name . '" maxlength="191" required'
			. $formAttribute
			. ' autocomplete="off" placeholder="' . Text::h(self::placeholder()) . '" />'
			. '<p class="orgsuite-field-help">' . Text::h($help) . '</p>'
			. '</div>';
	}

	private static function placeholder(): string
	{
		return match (OrgConfig::getProfile()) {
			OrgConfig::PROFILE_HOA       => 'e.g. 14 Sunset Hill Road or Unit 4B',
			OrgConfig::PROFILE_CLUB      => 'e.g. CLUB-1092',
			OrgConfig::PROFILE_NONPROFIT => 'e.g. MEM-204 or the organisation you represent',
			default                      => 'e.g. 14 Sunset Hill Road or MEM-1092',
		};
	}

	/**
	 * The credential kind this instance expects, per its profile.
	 */
	public static function credentialType(): string
	{
		$type = (string) OrgConfig::get('credential_type', '');

		if ($type !== '' && in_array($type, RosterRepository::types(), true)) {
			return $type;
		}

		return match (OrgConfig::getProfile()) {
			OrgConfig::PROFILE_HOA       => 'unit',
			OrgConfig::PROFILE_CLUB      => 'membership_id',
			OrgConfig::PROFILE_NONPROFIT => 'membership_id',
			default                      => 'address',
		};
	}

	/**
	 * Validates a submitted credential.
	 *
	 * Returns an error message for an officer-readable rejection, or null when acceptable.
	 */
	public static function validateCredential(string $raw): ?string
	{
		$raw = trim($raw);

		if ($raw === '') {
			return 'Please enter your ' . OrgConfig::getCredentialLabel() . '.';
		}

		if (mb_strlen($raw) < 2) {
			return 'That does not look like a complete ' . OrgConfig::getCredentialLabel() . '.';
		}

		if (mb_strlen($raw) > 191) {
			return 'That entry is too long. Please enter just the address, unit or membership ID.';
		}

		if (preg_match('/[\p{L}\p{N}]/u', $raw) !== 1) {
			return 'Please enter the address, unit number or membership ID we have on file.';
		}

		return null;
	}

	/**
	 * Handles the `register_post` hook.
	 *
	 * Called before Friendica validates the rest of the form and before the account is
	 * created, so rejecting here means no account exists at all.
	 *
	 * @param array<string, mixed> $data the hook payload, with a 'post' key
	 */
	public static function onRegisterPost(array &$data): void
	{
		if (!self::isEnabled()) {
			return;
		}

		$post = is_array($data['post'] ?? null) ? $data['post'] : $_POST;
		$raw  = Text::postValue($post, self::FIELD_NAME);

		// Additional accounts created by an existing member from their own dashboard are not
		// membership applications, so the gate does not apply to them.
		if (!empty($post['parent_password'])) {
			return;
		}

		$error = self::validateCredential($raw);

		if ($error !== null) {
			self::rejectSubmission($error, $post);

			return;
		}

		$normalized   = Text::normalizeCredential($raw);
		$credentialType = self::credentialType();

		$rosterEntry = OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_ROSTER)
			? RosterRepository::findByCredential($normalized)
			: null;

		// Strict mode: a credential that is not on the imported list never becomes an account
		// at all, which removes the "unknown applicant" case entirely.
		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_ROSTER)
			&& OrgConfig::isRosterStrict()
			&& $rosterEntry === null
		) {
			self::rejectSubmission(
				'We could not find that entry in our records. Please check the ' . OrgConfig::getCredentialLabel()
					. ' or contact the office for help.',
				$post
			);

			return;
		}

		$verificationId = VerificationRepository::create([
			'uid'             => 0,
			'username'        => Text::postValue($post, 'username'),
			'nickname'        => Text::postValue($post, 'nickname'),
			'email'           => strtolower(Text::postValue($post, 'field1')),
			'credential_raw'  => $raw,
			'credential'      => $normalized,
			'credential_type' => $credentialType,
			'roster_id'       => $rosterEntry !== null ? (int) $rosterEntry['id'] : 0,
		]);

		if ($verificationId === 0) {
			DI::logger()->error('OrgSuite: could not record an application credential; allowing registration to continue unverified.');

			return;
		}

		DI::session()->set(self::SESSION_KEY, $verificationId);

		AuditLog::record('verification.submit', OrgSchema::TABLE_VERIFICATION, $verificationId, [
			'credential_type' => $credentialType,
			'roster_match'    => $rosterEntry !== null,
		], 0);
	}

	/**
	 * Handles the `authenticate` hook.
	 *
	 * This never grants access - it only replaces a misleading failure with a true one. An
	 * account awaiting verification genuinely cannot sign in, because Friendica authenticates
	 * only verified accounts, so a pending applicant who tries is told to check their
	 * credentials. They then assume their password is wrong and often register again, which
	 * leaves an officer with two applications to sort out. Recognising the applicant here and
	 * saying what is actually happening is the difference between a product that feels broken
	 * and one that feels like it is waiting on a person.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function onAuthenticate(array &$data): void
	{
		if (!self::isEnabled()) {
			return;
		}

		// Never authenticate. An addon may only report success, and we are reporting the
		// opposite: the account exists but is not released yet.
		if (!empty($data['authenticated'])) {
			return;
		}

		$identifier = (string) ($data['username'] ?? '');

		if ($identifier === '') {
			return;
		}

		$verification = VerificationRepository::pendingForIdentifier($identifier);

		if ($verification === null) {
			return;
		}

		DI::sysmsg()->addInfo(
			'Your password is not the problem: this application is still waiting to be checked by '
			. 'an officer of ' . OrgConfig::getOrgName() . '. They will confirm the '
			. strtolower(OrgConfig::getCredentialLabel()) . ' you gave and your account will start '
			. 'working. There is nothing further you need to do.'
		);

		AuditLog::record('gate.login_before_verification', OrgSchema::TABLE_VERIFICATION, (int) $verification['id'], null, 0);
	}

	/**
	 * Aborts the registration request and sends the applicant back to the form with a reason.
	 *
	 * BaseURL::redirect() ends the request, which is exactly the behaviour we want: nothing
	 * after this point in core's Register module runs, so no account and no pending
	 * registration row are created.
	 */
	private static function rejectSubmission(string $message, array $post): void
	{
		DI::sysmsg()->addNotice($message);

		$regdata = array_filter([
			'username' => Text::postValue($post, 'username'),
			'nickname' => Text::postValue($post, 'nickname'),
			'email'    => Text::postValue($post, 'field1'),
		]);

		DI::baseUrl()->redirect('register' . ($regdata !== [] ? '?' . http_build_query($regdata) : ''));
	}

	/**
	 * Handles the `register_account` hook, fired by Friendica once the account row exists.
	 *
	 * Binds the credential captured at sign-up to the new account and creates the membership
	 * record. The account stays blocked unless this instance has opted into automatic
	 * approval based on a roster match.
	 */
	public static function onAccountRegister(int &$uid): void
	{
		if (!self::isEnabled()) {
			return;
		}

		if ($uid <= 0) {
			return;
		}

		$verificationId = (int) DI::session()->get(self::SESSION_KEY, 0);
		$verification   = $verificationId > 0 ? VerificationRepository::byId($verificationId) : null;

		if ($verification === null || (int) $verification['uid'] !== 0) {
			// Fall back to the newest unlinked application for this account's email address,
			// which covers a session that was lost between submitting and account creation.
			$user = User::getById($uid, ['email']);

			if (!empty($user['email'])) {
				$verification = VerificationRepository::latestUnlinkedForEmail(strtolower((string) $user['email']));
			}
		}

		if ($verification === null) {
			// No credential on record. Create a pending membership record so an officer sees
			// the account in the console rather than it silently becoming an active member.
			MemberRepository::upsert($uid, [
				...RoleRepository::memberFieldsFor('member'),
				'status' => 'pending',
			]);

			AuditLog::record('member.created_without_credential', OrgSchema::TABLE_MEMBER, $uid, null, $uid);

			return;
		}

		$verificationId = (int) $verification['id'];
		VerificationRepository::attachUid($verificationId, $uid);

		$rosterEntry = (int) $verification['roster_id'] > 0 ? RosterRepository::findByCredential((string) $verification['credential']) : null;

		// The position comes from the roster entry when the credential matched one, otherwise
		// the organization's default. `memberFieldsFor` writes `role_id` and the legacy `role`
		// slug together, so the two can never drift apart.
		$position = RoleRepository::memberFieldsFor(
			$rosterEntry !== null ? (string) $rosterEntry['default_role'] : 'member'
		);

		MemberRepository::upsert($uid, [
			...$position,
			'status'        => 'pending',
			'unit'          => $rosterEntry !== null ? (string) $rosterEntry['label'] : (string) $verification['credential_raw'],
			'membership_id' => (string) $verification['credential'],
			'household'     => $rosterEntry !== null ? (string) $rosterEntry['household'] : '',
		]);

		$autoApprove = !OrgConfig::isManualReviewRequired()
			&& OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_ROSTER)
			&& $rosterEntry !== null
			&& (int) $rosterEntry['claimed_uid'] === 0;

		if ($autoApprove) {
			self::approve($verificationId, 0, 'Matched against the imported roster and approved automatically.');

			return;
		}

		AuditLog::record('member.created_pending', OrgSchema::TABLE_MEMBER, $uid, [
			'roster_match' => $rosterEntry !== null,
			'credential'   => $verification['credential'],
		], $uid);
	}

	/**
	 * Approves an application: records the decision, activates the membership and releases
	 * the still-blocked Friendica account.
	 *
	 * @param int $officerUid 0 for an automatic decision made by the addon itself
	 */
	public static function approve(int $verificationId, int $officerUid, string $note = ''): bool
	{
		$verification = VerificationRepository::byId($verificationId);

		if ($verification === null) {
			return false;
		}

		$uid = (int) $verification['uid'];

		VerificationRepository::setStatus($verificationId, VerificationRepository::STATUS_APPROVED, $officerUid, $note);

		if ($uid > 0) {
			MemberRepository::activate($uid, $officerUid, [
				'unit'          => $verification['credential_raw'] !== '' ? (string) $verification['credential_raw'] : null,
				'membership_id' => (string) $verification['credential'],
			]);

			if ((int) $verification['roster_id'] > 0) {
				RosterRepository::claim((int) $verification['roster_id'], $uid);
			}

			// Release the pending Friendica registration. User::allow() also sends the
			// "your account has been approved" email, so we do not duplicate that.
			$pending = Register::getPendingForUser($uid);

			if (!empty($pending['hash'])) {
				User::allow((string) $pending['hash']);
			}
		}

		AuditLog::record('verification.approve', OrgSchema::TABLE_VERIFICATION, $verificationId, [
			'uid'  => $uid,
			'note' => $note,
		], $officerUid);

		return true;
	}

	/**
	 * Rejects an application and revokes the pending registration.
	 */
	public static function reject(int $verificationId, int $officerUid, string $note = ''): bool
	{
		$verification = VerificationRepository::byId($verificationId);

		if ($verification === null) {
			return false;
		}

		$uid = (int) $verification['uid'];

		VerificationRepository::setStatus($verificationId, VerificationRepository::STATUS_REJECTED, $officerUid, $note);

		if ($uid > 0) {
			MemberRepository::setStatus($uid, 'former');

			$pending = Register::getPendingForUser($uid);

			if (!empty($pending['hash'])) {
				User::deny((string) $pending['hash']);
			}
		}

		AuditLog::record('verification.reject', OrgSchema::TABLE_VERIFICATION, $verificationId, [
			'uid'  => $uid,
			'note' => $note,
		], $officerUid);

		return true;
	}

	/**
	 * Convenience wrapper used by the console for the "release an account that was approved
	 * in core's own moderation screen instead" case.
	 */
	public static function pendingHashFor(int $uid): string
	{
		$pending = Register::getPendingForUser($uid);

		return (string) ($pending['hash'] ?? '');
	}
}
