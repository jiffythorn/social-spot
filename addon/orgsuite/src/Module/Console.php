<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The officer console.
 *
 * Everything a president, treasurer or board member needs in one place, written for someone
 * who has never administered server software: who is waiting to be verified, who the members
 * are, who owes what, what is currently announced, and which parts of the platform this
 * organization has switched on.
 *
 * Feature switches live here rather than in a config file because the whole point of this
 * addon is that an organization can have club features, or HOA features, or neither, on a
 * single-instance-per-organization deployment.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite\Module;

use Friendica\Addon\OrgSuite\AnnouncementRepository;
use Friendica\Addon\OrgSuite\AuditLog;
use Friendica\Addon\OrgSuite\Branding;
use Friendica\Addon\OrgSuite\DuesRepository;
use Friendica\Addon\OrgSuite\Gate;
use Friendica\Addon\OrgSuite\MemberRepository;
use Friendica\Addon\OrgSuite\OrgConfig;
use Friendica\Addon\OrgSuite\OrgSchema;
use Friendica\Addon\OrgSuite\Permissions;
use Friendica\Addon\OrgSuite\RoleRepository;
use Friendica\Addon\OrgSuite\RosterRepository;
use Friendica\Addon\OrgSuite\Text;
use Friendica\Addon\OrgSuite\VaultRepository;
use Friendica\Addon\OrgSuite\VerificationRepository;
use Friendica\Database\DBStructure;
use Friendica\DI;
use Friendica\Model\Register;
use Friendica\Model\User;

class Console extends BaseOrgModule
{
	use ConsolePositions;

	private const TOKEN = 'orgsuite_console';

	protected function post(array $request = [])
	{
		$this->requireOfficer();

		$action = $this->cleanText($request, 'action', 40);

		if ($action === '') {
			DI::sysmsg()->addNotice('No action was submitted.');
			$this->baseUrl->redirect('orgsuite/console');
		}

		self::checkFormSecurityTokenRedirectOnError('orgsuite/console', self::TOKEN);

		switch ($action) {
			case 'approve_application':
				$this->requireFeature(OrgConfig::FEATURE_GATED_REGISTRATION);
				$this->decideApplication($request, true);
				break;
			case 'reject_application':
				$this->requireFeature(OrgConfig::FEATURE_GATED_REGISTRATION);
				$this->decideApplication($request, false);
				break;
			case 'member_update':
				$this->requireFeature(OrgConfig::FEATURE_MEMBERS);
				$this->updateMember($request);
				break;
			case 'roster_import':
				$this->requireFeature(OrgConfig::FEATURE_ROSTER);
				$this->importRoster($request);
				break;
			case 'roster_add':
				$this->requireFeature(OrgConfig::FEATURE_ROSTER);
				$this->addRosterEntry($request);
				break;
			case 'roster_delete':
				$this->requireFeature(OrgConfig::FEATURE_ROSTER);
				RosterRepository::delete($this->cleanInt($request, 'id'));
				$this->audit('roster.delete', OrgSchema::TABLE_ROSTER, $this->cleanInt($request, 'id'));
				DI::sysmsg()->addInfo('Roster entry removed.');
				break;
			case 'charge_create':
				$this->requireFeature(OrgConfig::FEATURE_DUES);
				$this->createCharge($request);
				break;
			case 'payment_record':
				$this->requireFeature(OrgConfig::FEATURE_DUES);
				$this->recordPayment($request);
				break;
			case 'charge_status':
				$this->requireFeature(OrgConfig::FEATURE_DUES);
				$this->setChargeStatus($request);
				break;
			case 'announcement_create':
				$this->requireFeature(OrgConfig::FEATURE_ANNOUNCEMENTS);
				$this->createAnnouncement($request);
				break;
			case 'announcement_toggle':
				$this->requireFeature(OrgConfig::FEATURE_ANNOUNCEMENTS);
				AnnouncementRepository::setActive(
					$this->cleanInt($request, 'id'),
					$this->cleanBool($request, 'active')
				);
				DI::sysmsg()->addInfo('Announcement updated.');
				break;
			case 'announcement_delete':
				$this->requireFeature(OrgConfig::FEATURE_ANNOUNCEMENTS);
				AnnouncementRepository::delete($this->cleanInt($request, 'id'));
				$this->audit('announcement.delete', OrgSchema::TABLE_ANNOUNCEMENT, $this->cleanInt($request, 'id'));
				DI::sysmsg()->addInfo('Announcement deleted.');
				break;
			case 'role_create':
				$this->createRole($request);
				break;
			case 'role_save':
				$this->saveRole($request);
				break;
			case 'role_delete':
				$this->deleteRole($request);
				break;
			case 'role_default':
				$this->defaultRole($request);
				break;
			case 'committee_create':
				$this->requireFeature(OrgConfig::FEATURE_COMMITTEES);
				$this->createCommittee($request);
				break;
			case 'committee_save':
				$this->requireFeature(OrgConfig::FEATURE_COMMITTEES);
				$this->saveCommittee($request);
				break;
			case 'committee_seat':
				$this->requireFeature(OrgConfig::FEATURE_COMMITTEES);
				$this->seatOnCommittee($request);
				break;
			case 'committee_unseat':
				$this->requireFeature(OrgConfig::FEATURE_COMMITTEES);
				$this->unseatFromCommittee($request);
				break;
			case 'committee_delete':
				$this->requireFeature(OrgConfig::FEATURE_COMMITTEES);
				$this->deleteCommittee($request);
				break;
			case 'settings_save':
				$this->saveSettings($request);
				break;
			case 'icon_save':
				$this->requirePermission(Permissions::MANAGE_SETTINGS);
				$this->saveIcon();
				break;
			case 'icon_remove':
				$this->requirePermission(Permissions::MANAGE_SETTINGS);
				$this->removeIcon();
				break;
			case 'schema_update':
				$this->repairSchema();
				break;
			default:
				DI::sysmsg()->addNotice('Unknown action.');
		}

		$tab = $this->cleanText($request, 'return_tab', 32);
		$this->baseUrl->redirect('orgsuite/console' . ($tab !== '' ? '?tab=' . urlencode($tab) : ''));
	}

	protected function content(array $request = []): string
	{
		$this->requireOfficer();

		$tab = Text::postValue($_GET, 'tab', 'overview');

		// A restricted tab is checked before it is rendered rather than merely hidden from the
		// tab bar, so typing the address of a tab you may not use is refused with an
		// explanation instead of quietly working.
		$required = $this->tabPermissions()[$tab] ?? '';

		if ($required !== '' && !$this->can($required)) {
			$this->requirePermission($required);
		}

		$body = match ($tab) {
			'applications'  => $this->applicationsTab(),
			'members'       => $this->membersTab(),
			'roster'        => $this->rosterTab(),
			'dues'          => $this->duesTab(),
			'announcements' => $this->announcementsTab(),
			'positions'     => $this->positionsTab(),
			'committees'    => $this->committeesTab(),
			'settings'      => $this->settingsTab(),
			'audit'         => $this->auditTab(),
			default         => $this->overviewTab(),
		};

		return $this->renderPage('Officer console', OrgConfig::getOrgName(), 'console', $body, $this->consoleTabs($tab));
	}

	/**
	 * @return array<string, array{label: string, url: string}>
	 */
	/**
	 * The permission each tab requires.
	 *
	 * Held in one place so a tab cannot be shown to somebody the request handler would then
	 * refuse, and a new tab cannot be added without deciding who may use it.
	 *
	 * @return array<string, string>
	 */
	private function tabPermissions(): array
	{
		return [
			'overview'      => '',
			'positions'     => Permissions::MANAGE_ROLES,
			'committees'    => Permissions::MANAGE_COMMITTEES,
			'applications'  => Permissions::VERIFY_APPLICATIONS,
			'members'       => Permissions::MANAGE_MEMBERS,
			'roster'        => Permissions::MANAGE_ROSTER,
			'dues'          => Permissions::MANAGE_DUES,
			'announcements' => Permissions::MANAGE_ANNOUNCEMENTS,
			'settings'      => Permissions::MANAGE_SETTINGS,
			'audit'         => Permissions::VIEW_AUDIT,
		];
	}

	/**
	 * Refuses a write to a switched-off feature.
	 *
	 * The tab bar hides disabled features, but a crafted POST can still name the action
	 * directly, so every mutating action checks its switch first. Redirects with an
	 * explanation when the feature is off, mirroring requirePermission().
	 */
	private function requireFeature(string $feature): void
	{
		if (OrgConfig::isFeatureEnabled($feature)) {
			return;
		}

		DI::logger()->notice('OrgSuite: write to disabled feature refused', [
			'feature' => $feature,
			'uid'     => $this->localUid(),
		]);

		DI::sysmsg()->addNotice('That feature is switched off. Turn it on in Organization settings to use it.');
		$this->baseUrl->redirect('orgsuite/console');
	}

	/** Whether the reader may open a tab at all. */
	private function mayOpenTab(string $tab): bool
	{
		$required = $this->tabPermissions()[$tab] ?? '';

		return $required === '' || $this->can($required);
	}

	private function consoleTabs(string $active): array
	{
		$tabs = ['overview' => ['label' => 'Overview', 'url' => 'orgsuite/console']];

		// Positions appear to anybody who can change them. Somebody who cannot should not be
		// offered a door they cannot open, but a position that can edit them is exactly the
		// person who needs to find this.
		if ($this->can(Permissions::MANAGE_ROLES)) {
			$tabs['positions'] = ['label' => 'Positions', 'url' => 'orgsuite/console?tab=positions'];
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_COMMITTEES) && $this->mayOpenTab('committees')) {
			$tabs['committees'] = ['label' => 'Committees', 'url' => 'orgsuite/console?tab=committees'];
		}

		// A tab is offered when the feature is switched on AND the reader's position allows it.
		// Both conditions matter: the feature flag says whether this organization has the
		// capability at all, the permission says whether this particular person may use it.
		if (Gate::isEnabled() && $this->mayOpenTab('applications')) {
			$pending = VerificationRepository::pendingCount();
			$tabs['applications'] = [
				'label' => 'Applications' . ($pending > 0 ? ' (' . $pending . ')' : ''),
				'url'   => 'orgsuite/console?tab=applications',
			];
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_MEMBERS) && $this->mayOpenTab('members')) {
			$tabs['members'] = ['label' => 'Members', 'url' => 'orgsuite/console?tab=members'];
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_ROSTER) && $this->mayOpenTab('roster')) {
			$tabs['roster'] = ['label' => 'Roster', 'url' => 'orgsuite/console?tab=roster'];
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_DUES) && $this->mayOpenTab('dues')) {
			$tabs['dues'] = ['label' => 'Dues', 'url' => 'orgsuite/console?tab=dues'];
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_ANNOUNCEMENTS) && $this->mayOpenTab('announcements')) {
			$tabs['announcements'] = ['label' => 'Announcements', 'url' => 'orgsuite/console?tab=announcements'];
		}

		if ($this->mayOpenTab('settings')) {
			$tabs['settings'] = ['label' => 'Organization settings', 'url' => 'orgsuite/console?tab=settings'];
		}

		if ($this->mayOpenTab('audit')) {
			$tabs['audit'] = ['label' => 'History', 'url' => 'orgsuite/console?tab=audit'];
		}

		return $tabs;
	}

	// -------------------------------------------------------------- overview

	private function overviewTab(): string
	{
		$body = $this->warnings();

		$counts         = MemberRepository::countsByStatus();
		$memberRows     = [];
		$memberRows[]   = ['Active', (string) $counts['active']];
		$memberRows[]   = ['Awaiting verification', (string) $counts['pending']];

		if ($counts['suspended'] > 0) {
			$memberRows[] = ['Suspended', (string) $counts['suspended']];
		}

		if ($counts['former'] > 0) {
			$memberRows[] = ['Former members', (string) $counts['former']];
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_MEMBERS)) {
			$body .= $this->card('Membership', $this->table(['Status', 'Members'], $memberRows));
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_DUES)) {
			$summary = DuesRepository::summary();

			$body .= $this->card('Dues', $this->table(['Measure', 'Value'], [
				['Outstanding', Text::formatMoney($summary['outstanding_cents'])],
				['Collected (all time)', Text::formatMoney($summary['collected_cents'])],
				['Charges still open', (string) $summary['open_charges']],
				['Charges settled', (string) $summary['paid_charges']],
			]), '<p>' . $this->link('Open the dues ledger', 'orgsuite/console?tab=dues') . '</p>');
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_VAULT)) {
			$body .= $this->card('Document library', $this->table(['Measure', 'Value'], [
				['Folders', (string) count(VaultRepository::folders())],
				['Documents', (string) VaultRepository::documentCount()],
			]), '<p>' . $this->link('Open the library', 'orgsuite/documents') . '</p>');
		}

		return $body;
	}

	/**
	 * Conditions that silently weaken the setup. Surfacing these here is deliberate: each one
	 * is a case where an officer would otherwise believe the platform is protecting them when
	 * it is not.
	 */
	private function warnings(): string
	{
		$warnings = [];

		if (!OrgSchema::ready()) {
			$warnings[] = 'The organization tables do not exist yet. '
				. ($this->isSiteAdmin()
					? 'Use the "Create the tables now" button in Organization settings.'
					: 'Ask the site administrator to run "bin/console dbstructure update".');
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_GATED_REGISTRATION)) {
			$diagnosis = Gate::registrationDiagnosis();

			if ($diagnosis['problem'] !== null) {
				$warnings[] = Text::h($diagnosis['detail']);
			}
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_ROSTER) && RosterRepository::count() === 0) {
			$warnings[] = 'The roster feature is on but the list is empty, so every application has to be checked by hand.';
		}

		if (!$this->isSiteAdmin() && !OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_GATED_REGISTRATION)) {
			$warnings[] = 'Gated registration is switched off: anyone who can reach the sign-up page can create an account.';
		}

		if ($warnings === []) {
			return '';
		}

		$html = '';

		foreach ($warnings as $warning) {
			$html .= '<li>' . $warning . '</li>';
		}

		return '<div class="orgsuite-alert orgsuite-alert--warn"><strong>Please check</strong><ul>' . $html . '</ul></div>';
	}

	// ---------------------------------------------------------- applications

	private function applicationsTab(): string
	{
		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_GATED_REGISTRATION)) {
			return $this->emptyState('Gated registration is switched off.');
		}

		$rows = [];

		foreach (VerificationRepository::byStatus(VerificationRepository::STATUS_PENDING) as $application) {
			$uid = (int) $application['uid'];

			$rosterNote = (int) $application['roster_id'] > 0
				? $this->badge('Matches the roster', 'ok')
				: $this->badge('Not on the roster', 'warn');

			$coreNote = '';

			if ($uid > 0) {
				$pending = Register::getPendingForUser($uid);
				$coreNote = !empty($pending['note'])
					? '<div class="orgsuite-cell-note">Their message: ' . Text::h((string) $pending['note']) . '</div>'
					: '';
			}

			$decide = $this->actionForm(
				'orgsuite/console',
				self::TOKEN,
				['action' => 'approve_application', 'id' => (string) $application['id'], 'return_tab' => 'applications'],
				'Approve',
				'primary'
			) . ' ' . $this->actionForm(
				'orgsuite/console',
				self::TOKEN,
				['action' => 'reject_application', 'id' => (string) $application['id'], 'return_tab' => 'applications'],
				'Reject',
				'danger',
				'Reject this application and revoke the registration?'
			);

			$rows[] = [
				Text::h((string) $application['created']),
				Text::h((string) $application['nickname']) . '<div class="orgsuite-cell-note">' . Text::h((string) $application['email']) . '</div>',
				'<strong>' . Text::h((string) $application['credential_raw']) . '</strong>'
					. '<div class="orgsuite-cell-note">' . Text::h(RosterRepository::typeLabel((string) $application['credential_type'])) . '</div>',
				$rosterNote . $coreNote,
				$decide,
			];
		}

		return $this->card(
			'Waiting for verification',
			'<p class="orgsuite-note">Compare each entry against association records before approving. '
				. 'Approving releases the account and emails the applicant.</p>'
				. $this->table(['Applied', 'Applicant', 'Submitted', 'Checks', 'Decision'], $rows, 'Nobody is waiting for verification.')
		);
	}

	private function decideApplication(array $request, bool $approve): void
	{
		$id   = $this->cleanInt($request, 'id');
		$note = $this->cleanText($request, 'review_note', 1000);

		$ok = $approve
			? Gate::approve($id, $this->localUid(), $note)
			: Gate::reject($id, $this->localUid(), $note);

		DI::sysmsg()->{$ok ? 'addInfo' : 'addNotice'}($ok
			? ($approve ? 'Application approved and the account released.' : 'Application rejected.')
			: 'That application could not be found.');
	}

	// --------------------------------------------------------------- members

	/**
	 * Position choices for a dropdown, keyed by role id.
	 *
	 * Keyed by id rather than by the role name, so renaming a position later does not orphan
	 * everybody holding it - the assignment follows the position, not the word.
	 *
	 * @return array<string, string>
	 */
	private function positionOptions(): array
	{
		$options = [];

		foreach (RoleRepository::organizationRoles(false) as $role) {
			$options[(string) $role['id']] = (string) $role['label'];
		}

		return $options;
	}

	private function membersTab(): string
	{
		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_MEMBERS)) {
			return $this->emptyState('Member records are switched off. Turn them on in Organization settings.');
		}

		$rows = [];

		foreach (MemberRepository::listAll() as $member) {
			$uid   = (int) $member['uid'];
			$user  = User::getById($uid, ['username', 'email', 'blocked']);
			$label = $user ? (string) $user['username'] : '#' . $uid;

			$currentRole = RoleRepository::resolveForMember($member);

			$controls = $this->hiddenField('member_uid', (string) $uid)
				. $this->selectField('role_id', 'Position', $this->positionOptions(), (string) ($currentRole['id'] ?? ''))
				. $this->selectField('status', 'Standing', OrgConfig::STATUS_LABELS, (string) $member['status'])
				. $this->textField('unit', 'Unit / membership', (string) $member['unit'])
				. $this->textField('household', 'Household name', (string) $member['household'])
				. $this->checkbox('directory', 'Show in the member directory', (bool) $member['directory'])
				. $this->hiddenField('action', 'member_update')
				. $this->hiddenField('return_tab', 'members')
				. '<p class="orgsuite-actions">' . $this->submitButton('Save', 'secondary') . '</p>';

			$rows[] = [
				'<strong>' . Text::h($label) . '</strong><div class="orgsuite-cell-note">#' . $uid . '</div>',
				$this->statusBadge((string) $member['status']),
				Text::h((string) ($currentRole['label'] ?? $member['role'])),
				Text::h((string) $member['unit']),
				$this->formStart('orgsuite/console', self::TOKEN) . $controls . '</form>',
			];
		}

		return $this->card(
			'Members',
			$this->table(['Member', 'Standing', 'Position', 'Unit / ID', 'Change'], $rows, 'No membership records yet.')
		);
	}

	private function updateMember(array $request): void
	{
		$uid = $this->cleanInt($request, 'member_uid');

		if (MemberRepository::forUser($uid) === null) {
			DI::sysmsg()->addNotice('Member not found.');

			return;
		}

		$status = $this->cleanText($request, 'status', 16);

		if (!array_key_exists($status, OrgConfig::STATUS_LABELS)) {
			DI::sysmsg()->addNotice('That standing is not recognised.');

			return;
		}

		$roleId = $this->cleanInt($request, 'role_id');
		$role   = $roleId > 0 ? RoleRepository::byId($roleId) : null;

		if ($role === null || $role['scope'] !== RoleRepository::SCOPE_ORGANIZATION) {
			DI::sysmsg()->addNotice('That position is not one an officer of this organization can assign.');

			return;
		}

		MemberRepository::upsert($uid, [
			'role_id'   => $roleId,
			'role'      => $role['slug'],
			'status'    => $status,
			'unit'      => $this->cleanText($request, 'unit', 191),
			'household' => $this->cleanText($request, 'household', 255),
			'directory' => $this->cleanBool($request, 'directory'),
		]);

		$this->audit('member.update', OrgSchema::TABLE_MEMBER, $uid, [
			'position' => $role['label'],
			'status'   => $status,
		]);
		DI::sysmsg()->addInfo('Member updated.');
	}

	// ---------------------------------------------------------------- roster

	private function rosterTab(): string
	{
		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_ROSTER)) {
			return $this->emptyState('The roster feature is switched off. Turn it on in Organization settings.');
		}

		$importForm = $this->card(
			'Import the roster',
			$this->formStart('orgsuite/console', self::TOKEN)
				. '<p class="orgsuite-note">Paste one line per household or member. Columns in order: '
				. '<code>credential, label, household, role</code>. Only the credential is required; a header row is ignored.</p>'
				. $this->textArea('roster_csv', 'Paste the list', '', 'e.g. 14 Sunset Hill Road,14 Sunset Hill Road,The Alvarez family,owner')
				. $this->selectField('roster_type', 'These values are', RosterRepository::typeLabels(), Gate::credentialType())
				. $this->checkbox(
					'roster_strict_apply',
					'Reject anyone whose entry is not on this list',
					OrgConfig::isRosterStrict(),
					'Leave this off if legitimate members might not appear on the list — they will be held for manual review instead.'
				)
				. $this->hiddenField('action', 'roster_import')
				. $this->hiddenField('return_tab', 'roster')
				. '<p class="orgsuite-actions">' . $this->submitButton('Import') . '</p>'
				. '</form>'
		);

		$singleForm = $this->card(
			'Add one entry',
			$this->formStart('orgsuite/console', self::TOKEN)
				. $this->textField('roster_credential', 'Unit, address or membership ID', '', 'text', '', true)
				. $this->textField('roster_label', 'Label', '', 'text', 'Shown to the member and in the console.')
				. $this->textField('roster_household', 'Household / member name', '')
				. $this->selectField('roster_role', 'Position on approval', $this->positionOptions(), (string) (RoleRepository::defaultRole()['id'] ?? ''))
				. $this->hiddenField('action', 'roster_add')
				. $this->hiddenField('return_tab', 'roster')
				. '<p class="orgsuite-actions">' . $this->submitButton('Add entry') . '</p>'
				. '</form>'
		);

		$rows = [];

		foreach (RosterRepository::all() as $entry) {
			$rows[] = [
				Text::h((string) $entry['label']),
				Text::h((string) $entry['credential']),
				Text::h(RosterRepository::typeLabel((string) $entry['credential_type'])),
				Text::h((string) $entry['household']),
				(int) $entry['claimed_uid'] > 0
					? $this->badge('Claimed', 'muted')
					: $this->badge('Available', 'ok'),
				$this->actionForm(
					'orgsuite/console',
					self::TOKEN,
					['action' => 'roster_delete', 'id' => (string) $entry['id'], 'return_tab' => 'roster'],
					'Remove',
					'danger'
				),
			];
		}

		$summary = $this->table(
			['Entry', 'Normalised', 'Kind', 'Household', 'State', ''],
			$rows,
			'The roster is empty. Import a list to enable automatic matching.'
		);

		return $this->warningsForRoster()
			. $importForm
			. $singleForm
			. $this->card('Roster (' . RosterRepository::count() . ' entries)', $summary);
	}

	private function warningsForRoster(): string
	{
		if (!Gate::themeRendersField()) {
			return '<div class="orgsuite-alert orgsuite-alert--warn">'
				. '<strong>The active theme does not render the sign-up field.</strong>'
				. '<p>The addon injects it above the form instead, which works in every theme and without JavaScript. '
				. 'The orgtool theme renders it natively for a tidier result.</p></div>';
		}

		return '';
	}

	private function importRoster(array $request): void
	{
		$csv = (string) ($request['roster_csv'] ?? '');

		if (trim($csv) === '') {
			DI::sysmsg()->addNotice('Paste at least one line before importing.');

			return;
		}

		$summary = RosterRepository::importCsv($csv, $this->cleanText($request, 'roster_type', 32));

		OrgConfig::set('roster_strict', $this->cleanBool($request, 'roster_strict_apply'));

		$this->audit('roster.import', OrgSchema::TABLE_ROSTER, 0, [
			'added'     => $summary['added'],
			'duplicate' => $summary['duplicate'],
			'invalid'   => $summary['invalid'],
		]);

		$message = $summary['added'] . ' added, ' . $summary['duplicate'] . ' already present, ' . $summary['invalid'] . ' skipped.';

		if ($summary['errors'] !== []) {
			$message .= ' First problem: ' . $summary['errors'][0];
		}

		DI::sysmsg()->addInfo('Roster imported: ' . $message);
	}

	private function addRosterEntry(array $request): void
	{
		$credential = $this->cleanText($request, 'roster_credential', 191);

		$result = RosterRepository::add(
			$credential,
			$this->cleanText($request, 'roster_type', 32),
			$this->cleanText($request, 'roster_label', 191),
			$this->cleanText($request, 'roster_household', 255),
			RoleRepository::memberFieldsFor($this->cleanInt($request, 'roster_role'))['role']
		);

		match ($result) {
			RosterRepository::RESULT_ADDED     => DI::sysmsg()->addInfo('Entry added to the roster.'),
			RosterRepository::RESULT_DUPLICATE => DI::sysmsg()->addInfo('That entry is already on the roster.'),
			default                            => DI::sysmsg()->addNotice('Enter a usable unit, address or membership ID.'),
		};
	}

	// ------------------------------------------------------------------ dues

	private function duesTab(): string
	{
		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_DUES)) {
			return $this->emptyState('Dues tracking is switched off. Turn it on in Organization settings.');
		}

		$chargeForm = $this->card(
			'Raise a charge',
			$this->formStart('orgsuite/console', self::TOKEN)
				. $this->selectField('charge_target', 'Charge', $this->chargeTargets(), 'all')
				. $this->textField('charge_period', 'Period', date('Y'), 'text', 'e.g. 2026 annual or 2026 Q1')
				. $this->textField('charge_description', 'Description', 'Annual assessment', 'text')
				. $this->textField('charge_amount', 'Amount', '', 'text', 'e.g. 450 or 450.00', true)
				. $this->textField('charge_due', 'Due date', '', 'date')
				. $this->hiddenField('action', 'charge_create')
				. $this->hiddenField('return_tab', 'dues')
				. '<p class="orgsuite-actions">' . $this->submitButton('Raise charge') . '</p>'
				. '</form>'
		);

		$outstanding = [];

		foreach (DuesRepository::outstandingByMember() as $entry) {
			$member = MemberRepository::forUser($entry['uid']);
			$user   = User::getById($entry['uid'], ['username']);

			$outstanding[] = [
				Text::h($user ? (string) $user['username'] : '#' . $entry['uid']),
				Text::h($member !== null ? (string) $member['unit'] : ''),
				Text::h(Text::formatMoney($entry['balance_cents'])),
				$this->recordPaymentForm($entry['uid']),
			];
		}

		$charges = [];

		foreach (DuesRepository::allCharges('', 100) as $charge) {
			$user = User::getById((int) $charge['uid'], ['username']);

			$charges[] = [
				Text::h((string) $charge['period']),
				Text::h($user ? (string) $user['username'] : '#' . $charge['uid']),
				Text::h((string) $charge['description']),
				Text::h(Text::formatMoney((int) $charge['amount_cents'])),
				Text::h((string) ($charge['due_date'] ?? '')),
				Text::h(DuesRepository::CHARGE_STATUS[$charge['status']] ?? (string) $charge['status']),
				$this->chargeActions($charge),
			];
		}

		return $chargeForm
			. $this->card(
				'Who owes what',
				$this->table(['Member', 'Unit', 'Outstanding', 'Record a payment'], $outstanding, 'Nothing is outstanding.')
			)
			. $this->card(
				'Charges',
				$this->table(['Period', 'Member', 'Description', 'Amount', 'Due', 'Status', ''], $charges, 'No charges have been raised.')
			);
	}

	/**
	 * @return array<string, string>
	 */
	private function chargeTargets(): array
	{
		$targets = ['all' => 'Every active member'];

		foreach (MemberRepository::listAll('active') as $member) {
			$user = User::getById((int) $member['uid'], ['username']);

			if ($user) {
				$targets[(string) $member['uid']] = (string) $user['username']
					. ($member['unit'] !== '' ? ' (' . $member['unit'] . ')' : '');
			}
		}

		return $targets;
	}

	private function recordPaymentForm(int $uid): string
	{
		$charges = [];

		foreach (DuesRepository::chargesFor($uid) as $charge) {
			if ($charge['status'] !== 'open') {
				continue;
			}

			$charges[(string) $charge['id']] = $charge['period'] . ' — '
				. Text::formatMoney((int) $charge['amount_cents'] - DuesRepository::paidTotal((int) $charge['id']));
		}

		if ($charges === []) {
			return '<span class="orgsuite-note">No open charge</span>';
		}

		return '<form class="orgsuite-form orgsuite-form--inline" method="post" action="orgsuite/console">'
			. '<input type="hidden" name="form_security_token" value="' . Text::h(self::getFormSecurityToken(self::TOKEN)) . '" />'
			. $this->hiddenField('action', 'payment_record')
			. $this->hiddenField('return_tab', 'dues')
			. $this->selectField('payment_charge', 'Charge', $charges, (string) array_key_first($charges))
			. $this->textField('payment_amount', 'Amount', '', 'text', 'Leave empty to pay the balance in full.')
			. $this->selectField('payment_method', 'Method', DuesRepository::PAYMENT_METHODS, 'check')
			. $this->textField('payment_reference', 'Reference', '', 'text', 'Cheque number or bank reference.')
			. $this->submitButton('Record', 'secondary')
			. '</form>';
	}

	private function chargeActions(array $charge): string
	{
		$id = (int) $charge['id'];

		$html = $this->actionForm(
			'orgsuite/console',
			self::TOKEN,
			['action' => 'charge_status', 'id' => (string) $id, 'status' => 'waived', 'return_tab' => 'dues'],
			'Waive',
			'secondary',
			'Waive this charge? Members will no longer owe it.'
		);

		if ($charge['status'] !== 'void') {
			$html .= ' ' . $this->actionForm(
				'orgsuite/console',
				self::TOKEN,
				['action' => 'charge_status', 'id' => (string) $id, 'status' => 'void', 'return_tab' => 'dues'],
				'Void',
				'danger'
			);
		}

		return $html;
	}

	private function createCharge(array $request): void
	{
		$amount = Text::parseMoneyToCents($this->cleanText($request, 'charge_amount', 32));

		if ($amount === null || $amount === 0) {
			DI::sysmsg()->addNotice('Enter the amount as a number, for example 450 or 450.00.');

			return;
		}

		$target = $this->cleanText($request, 'charge_target', 32);

		$uids = [];

		if ($target === 'all') {
			foreach (MemberRepository::listAll('active') as $member) {
				$uids[] = (int) $member['uid'];
			}
		} else {
			$uids[] = (int) $target;
		}

		if ($uids === []) {
			DI::sysmsg()->addNotice('There is nobody to charge yet. Approve some members first.');

			return;
		}

		$period      = $this->cleanText($request, 'charge_period', 64);
		$description = $this->cleanText($request, 'charge_description', 255);
		$dueDate     = $this->cleanDate($request, 'charge_due');

		$created = 0;

		foreach ($uids as $uid) {
			$member = MemberRepository::forUser($uid);

			if (DuesRepository::createCharge(
				$uid,
				$period,
				$description,
				$amount,
				$dueDate,
				$member !== null ? (string) $member['unit'] : '',
				$this->localUid()
			) > 0) {
				$created++;
			}
		}

		$this->audit('dues.charge.create', OrgSchema::TABLE_CHARGE, 0, [
			'count'  => $created,
			'amount' => $amount,
			'period' => $period,
		]);

		DI::sysmsg()->addInfo($created . ' charge(s) raised for ' . Text::formatMoney($amount) . ' each.');
	}

	private function recordPayment(array $request): void
	{
		$chargeId = $this->cleanInt($request, 'payment_charge');
		$charge   = DuesRepository::chargeById($chargeId);

		if ($charge === null) {
			DI::sysmsg()->addNotice('Charge not found.');

			return;
		}

		$amount = Text::parseMoneyToCents($this->cleanText($request, 'payment_amount', 32));

		if ($amount === null) {
			// Empty means "settle it": the outstanding part of the charge.
			$amount = (int) $charge['amount_cents'] - DuesRepository::paidTotal($chargeId);
		}

		if ($amount === 0) {
			DI::sysmsg()->addNotice('Nothing left to pay on that charge.');

			return;
		}

		$paymentId = DuesRepository::recordPayment(
			$chargeId,
			$amount,
			$this->cleanText($request, 'payment_method', 16),
			$this->cleanText($request, 'payment_reference', 191),
			$this->cleanDate($request, 'payment_date') ?? date('Y-m-d'),
			'',
			$this->localUid()
		);

		if ($paymentId === 0) {
			DI::sysmsg()->addNotice('The payment could not be recorded.');

			return;
		}

		$this->audit('dues.payment.record', OrgSchema::TABLE_CHARGE, $chargeId, ['amount' => $amount]);
		DI::sysmsg()->addInfo('Payment of ' . Text::formatMoney($amount) . ' recorded.');
	}

	private function setChargeStatus(array $request): void
	{
		$id     = $this->cleanInt($request, 'id');
		$status = $this->cleanText($request, 'status', 16);

		if (!DuesRepository::setChargeStatus($id, $status)) {
			DI::sysmsg()->addNotice('That charge could not be updated.');

			return;
		}

		$this->audit('dues.charge.status', OrgSchema::TABLE_CHARGE, $id, ['status' => $status]);
		DI::sysmsg()->addInfo('Charge marked as ' . (DuesRepository::CHARGE_STATUS[$status] ?? $status) . '.');
	}

	// --------------------------------------------------------- announcements

	private function announcementsTab(): string
	{
		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_ANNOUNCEMENTS)) {
			return $this->emptyState('Announcements are switched off. Turn them on in Organization settings.');
		}

		$form = $this->card(
			'New announcement',
			$this->formStart('orgsuite/console', self::TOKEN)
				. $this->textField('announcement_title', 'Headline', '', 'text', '', true)
				. $this->textArea('announcement_body', 'Message', '', 'Plain text. Line breaks are kept.')
				. $this->selectField('announcement_level', 'Importance', AnnouncementRepository::LEVELS, 'info')
				. $this->selectField('announcement_visibility', 'Who sees it', VaultRepository::visibilityOptions(), VaultRepository::VIS_MEMBERS)
				. $this->textField('announcement_ends', 'Stop showing after', '', 'date', 'Leave empty to keep it until it is switched off.')
				. $this->textField('announcement_link', 'Read more link', '', 'url', 'Optional.')
				. $this->hiddenField('action', 'announcement_create')
				. $this->hiddenField('return_tab', 'announcements')
				. '<p class="orgsuite-actions">' . $this->submitButton('Publish') . '</p>'
				. '</form>'
		);

		$rows = [];

		foreach (AnnouncementRepository::all() as $announcement) {
			$id = (int) $announcement['id'];

			$rows[] = [
				'<strong>' . Text::h((string) $announcement['title']) . '</strong>'
					. ($announcement['body'] !== null
						? '<div class="orgsuite-cell-note">' . Text::h(Text::excerpt((string) $announcement['body'], 160)) . '</div>'
						: ''),
				Text::h(AnnouncementRepository::LEVELS[$announcement['level']] ?? (string) $announcement['level']),
				Text::h(VaultRepository::visibilityLabel((string) $announcement['visibility'])),
				(bool) $announcement['active'] ? $this->badge('Showing', 'ok') : $this->badge('Hidden', 'muted'),
				Text::h((string) ($announcement['ends_at'] ?? '—')),
				$this->actionForm(
					'orgsuite/console',
					self::TOKEN,
					[
						'action'     => 'announcement_toggle',
						'id'         => (string) $id,
						'active'     => (bool) $announcement['active'] ? '0' : '1',
						'return_tab' => 'announcements',
					],
					(bool) $announcement['active'] ? 'Hide' : 'Show',
					'secondary'
				) . ' ' . $this->actionForm(
					'orgsuite/console',
					self::TOKEN,
					['action' => 'announcement_delete', 'id' => (string) $id, 'return_tab' => 'announcements'],
					'Delete',
					'danger'
				),
			];
		}

		return $form . $this->card(
			'Announcements',
			$this->table(['Headline', 'Importance', 'Audience', 'State', 'Stops', ''], $rows, 'No announcements yet.')
		);
	}

	private function createAnnouncement(array $request): void
	{
		$id = AnnouncementRepository::create(
			$this->cleanText($request, 'announcement_title', 255),
			$this->cleanText($request, 'announcement_body', 2000),
			$this->cleanText($request, 'announcement_level', 16),
			$this->cleanText($request, 'announcement_visibility', 16),
			$this->cleanDate($request, 'announcement_ends'),
			$this->cleanText($request, 'announcement_link', 255),
			$this->localUid()
		);

		if ($id === 0) {
			DI::sysmsg()->addNotice('An announcement needs a headline.');

			return;
		}

		$this->audit('announcement.create', OrgSchema::TABLE_ANNOUNCEMENT, $id);
		DI::sysmsg()->addInfo('Announcement published.');
	}

	// -------------------------------------------------------------- settings

	private function settingsTab(): string
	{
		$profileOptions = [];

		foreach (OrgConfig::PROFILES as $profile) {
			$profileOptions[$profile] = OrgConfig::PROFILE_LABELS[$profile];
		}

		$html = '<div class="orgsuite-alert orgsuite-alert--warn"><strong>About these settings</strong>'
			. '<p>This installation serves exactly one organization. Switching a feature off removes it from '
			. 'the navigation and stops its pages and checks from running — no code changes, and nothing else on '
			. 'the instance is affected.</p></div>';

		$html .= $this->card(
			'Organization',
			$this->formStart('orgsuite/console', self::TOKEN)
				. $this->textField('org_name', 'Organization name', OrgConfig::getOrgName(), 'text', '', true)
				. $this->textField('org_tagline', 'Tagline', OrgConfig::getOrgTagline(), 'text', 'Shown under the title.')
				. $this->selectField('profile', 'Kind of organization', $profileOptions, OrgConfig::getProfile(),
					'Changing this re-applies the default feature set below, then you can fine-tune it.')
				. $this->textField('credential_label', 'Sign-up field label', OrgConfig::getCredentialLabel(), 'text',
					'What you ask new members for.')
				. $this->textArea('credential_help', 'Sign-up field explanation', OrgConfig::getCredentialHelp())
				. $this->selectField('credential_type', 'These values are', RosterRepository::typeLabels(), Gate::credentialType())
				. $this->checkbox('manual_review', 'An officer must approve every application', OrgConfig::isManualReviewRequired(),
					'With this off, an applicant whose entry is on the roster is approved automatically.')
				. $this->checkbox('stream_first', 'Show the social stream in the main navigation', OrgConfig::isStreamFirst(),
					'Leave this off to keep the dashboard as the front page for members.')
				. $this->textField('source_url', 'Source code address', OrgConfig::getSourceUrl(), 'text',
					'Shown in the footer of every page. This instance runs a modified Friendica, so members '
					. 'must be able to obtain its source. Clearing this field restores the default.')
				. $this->hiddenField('action', 'settings_save')
				. $this->hiddenField('return_tab', 'settings')
				. '<p class="orgsuite-actions">' . $this->submitButton('Save settings') . '</p>'
				. '</form>'
		);

		$html .= $this->brandingCard();
		$html .= $this->featureFlagsCard();
		$html .= $this->maintenanceCard();

		return $html;
	}

	private function featureFlagsCard(): string
	{
		$states = OrgConfig::getFeatureStates();

		if (!$this->isSiteAdmin()) {
			$rows = [];

			foreach ($states as $state) {
				$rows[] = [
					Text::h($state['label']),
					$state['enabled'] ? $this->badge('On', 'ok') : $this->badge('Off', 'muted'),
				];
			}

			return $this->card(
				'Features',
				$this->table(['Feature', 'State'], $rows)
					. '<p class="orgsuite-note">Only a site administrator can change these.</p>'
			);
		}

		$html = '';

		foreach ($states as $feature => $state) {
			$note = '';

			if ($state['blocked_by'] !== []) {
				$missing = array_map(static fn (string $key): string => OrgConfig::FEATURES[$key]['label'] ?? $key, $state['blocked_by']);
				$note = 'Needs: ' . implode(', ', $missing) . '. It is switched on but inactive until those are enabled.';
			}

			$html .= $this->checkbox(
				'feature_' . $feature,
				$state['label'] . ' — ' . $state['description'],
				$state['stored'],
				$note
			);
		}

		return $this->card(
			'Features',
			$this->formStart('orgsuite/console', self::TOKEN)
				. $html
				. $this->hiddenField('action', 'settings_save')
				. $this->hiddenField('return_tab', 'settings')
				. $this->hiddenField('features_only', '1')
				. '<p class="orgsuite-actions">' . $this->submitButton('Save features') . '</p>'
				. '</form>'
		);
	}

	/**
	 * The site icon: what members see in their browser tab and on their home screen.
	 *
	 * Governed by "change organization settings" rather than by site administration. The
	 * two core values an upload writes (`system.touch_icon` and `system.shortcut_icon`)
	 * point at this addon's own routes, so replacing the artwork is the organization
	 * changing its own face, not administering the machine it is hosted on - and whoever
	 * grants the permission to edit the organization name and wording is granting this too.
	 * A site administrator holds it without the tick, as everywhere else.
	 */
	private function brandingCard(): string
	{
		$preview = '<img class="orgsuite-branding__preview" src="orgsuite/icon?size=192" '
			. 'width="96" height="96" alt="Current site icon" />';

		$source = Branding::hasCustomIcon()
			? 'Using the logo uploaded here.'
			: 'Using the built-in site icon - upload a logo to replace it.';

		$body = $preview
			. '<p class="orgsuite-note">' . Text::h($source) . ' This is what members see in '
			. 'their browser tab and when they add this site to their phone\'s home screen.</p>'
			. $this->formStart('orgsuite/console', self::TOKEN)
			. $this->hiddenField('action', 'icon_save')
			. $this->hiddenField('return_tab', 'settings')
			. '<label class="orgsuite-field">'
			. '<span class="orgsuite-field__label">Upload a logo <span class="orgsuite-required">*</span></span>'
			. '<input type="file" name="org_icon" accept="image/*" required />'
			. '<span class="orgsuite-field__help">A PNG or JPEG up to 4 MB. A square image works best; '
			. 'it is resized for every place the icon appears.</span>'
			. '</label>'
			. '<p class="orgsuite-actions">' . $this->submitButton('Use this logo') . '</p>'
			. '</form>';

		if (Branding::hasCustomIcon()) {
			$body .= '<div class="orgsuite-row-actions">'
				. $this->actionForm(
					'orgsuite/console',
					self::TOKEN,
					['action' => 'icon_remove', 'return_tab' => 'settings'],
					'Remove - go back to the built-in icon',
					'danger',
					'Replace your uploaded logo with the built-in site icon?'
				)
				. '</div>';
		}

		return $this->card('Site icon', $body);
	}

	/**
	 * Reads the posted logo and hands it to Branding, which validates it properly: what the
	 * bytes are, not what the file is called or what the browser claimed.
	 */
	private function saveIcon(): void
	{
		$file = $_FILES['org_icon'] ?? null;

		if (!is_array($file) || !isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
			$errorCode = is_array($file) ? (int) ($file['error'] ?? -1) : -1;
			$message = $errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE
				? 'That image is larger than this server accepts. Resize it (a square under 4 MB is plenty) and try again.'
				: 'No image was received. Choose a file and try again.';

			DI::sysmsg()->addNotice($message);

			return;
		}

		$data = @file_get_contents((string) $file['tmp_name']);

		$error = null;

		if (!Branding::saveUpload($data === false ? '' : $data, $this->localUid(), $error)) {
			DI::sysmsg()->addNotice($error ?? 'That image could not be used.');

			return;
		}

		// Who changed the site's face, and when: the same reason every other settings write
		// is recorded, and the reason it matters now is that more than one person can.
		$this->audit('settings.icon.save', OrgConfig::CATEGORY, 0);

		DI::sysmsg()->addInfo('Site icon updated. It may take a few minutes to refresh on '
			. 'phones that already had the site saved.');
	}

	private function removeIcon(): void
	{
		if (!Branding::hasCustomIcon()) {
			DI::sysmsg()->addNotice('There is no uploaded logo to remove.');

			return;
		}

		Branding::remove($this->localUid());

		$this->audit('settings.icon.remove', OrgConfig::CATEGORY, 0);

		DI::sysmsg()->addInfo('Site icon reset to the built-in one.');
	}

	private function maintenanceCard(): string
	{
		if (!$this->isSiteAdmin()) {
			return '';
		}

		$body = '<p class="orgsuite-note">Creating or updating this addon\'s database tables runs the same '
			. 'schema update as <code>bin/console dbstructure update</code>. It is safe to run again.</p>'
			. $this->actionForm(
				'orgsuite/console',
				self::TOKEN,
				['action' => 'schema_update', 'return_tab' => 'settings'],
				OrgSchema::ready() ? 'Check and update the tables' : 'Create the tables now',
				'primary'
			);

		return $this->card('Maintenance', $body);
	}

	private function saveSettings(array $request): void
	{
		$featuresOnly = $this->cleanBool($request, 'features_only');

		$profileChanged = false;

		if (!$featuresOnly) {
			$profile = $this->cleanText($request, 'profile', 32);

			if (in_array($profile, OrgConfig::PROFILES, true) && $profile !== OrgConfig::getProfile()) {
				// Re-seeds every feature switch from the new profile's defaults.
				OrgConfig::setProfile($profile);
				$profileChanged = true;
				$this->audit('settings.profile', OrgConfig::CATEGORY, 0, ['profile' => $profile]);
			}

			OrgConfig::set('org_name', $this->cleanText($request, 'org_name', 191));
			OrgConfig::set('org_tagline', $this->cleanText($request, 'org_tagline', 255));

			// Set unconditionally rather than only when non-empty: clearing the field must restore the
			// default offer, not silently keep pointing at an address the officer just removed.
			OrgConfig::setSourceUrl($this->cleanText($request, 'source_url', 255));

			$credentialLabel = $this->cleanText($request, 'credential_label', 191);

			if ($credentialLabel !== '') {
				OrgConfig::set('credential_label', $credentialLabel);
			}

			OrgConfig::set('credential_help', $this->cleanText($request, 'credential_help', 500));

			$credentialType = $this->cleanText($request, 'credential_type', 32);

			if (in_array($credentialType, RosterRepository::types(), true)) {
				OrgConfig::set('credential_type', $credentialType);
			}

			OrgConfig::set('manual_review', $this->cleanBool($request, 'manual_review'));
			OrgConfig::set('stream_first', $this->cleanBool($request, 'stream_first'));

			$this->audit('settings.update', OrgConfig::CATEGORY, 0);
		}

		if ($this->isSiteAdmin()) {
			// The feature switches are their own form. The organization form does not submit any
			// checkbox, so an absent feature_* field there means "this form has nothing to say
			// about features", not "the officer wants every feature off". Treating the two the
			// same way silently switched the whole instance off whenever the name was edited, and
			// threw away the defaults a profile change had just applied. Only read the switches
			// when the request actually came from the form that renders them.
			if ($featuresOnly) {
				$changed = [];

				foreach (array_keys(OrgConfig::FEATURES) as $feature) {
					$enabled = $this->cleanBool($request, 'feature_' . $feature);

					if ($enabled !== OrgConfig::getStoredFeature($feature)) {
						$changed[$feature] = $enabled;
					}

					OrgConfig::setFeature($feature, $enabled);
				}

				if ($changed !== []) {
					$this->audit('settings.features', OrgConfig::CATEGORY, 0, $changed);
				}
			}

			if ($featuresOnly || $profileChanged) {
				// The route table, the navigation and the sign-up field all depend on which
				// features are on, so cached decisions have to go.
				Gate::resetTemplateCache();
				OrgSchema::resetReadyCache();

				// Friendica caches the whole route table for a day and only recomputes it when
				// the addon list changes - but our routes are added *per feature*, so a switch
				// flipped here would otherwise take up to 24 hours to actually do anything.
				// Clearing the key costs one recompute on the next request.
				DI::cache()->delete('routerDispatchData');
			}

			if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_GATED_REGISTRATION)) {
				$this->enforceApprovalPolicy();
			}
		}

		DI::sysmsg()->addInfo('Settings saved.');
	}

	/**
	 * Gated registration only means something if a new account is blocked until an officer
	 * releases it. Rather than silently rewriting a core setting, this records the change and
	 * tells the administrator exactly what happened.
	 */
	/**
	 * Makes sure a gated sign-up can actually result in a held account.
	 *
	 * Called after settings change, because switching gated registration on is the moment the
	 * policy matters. The stored policy is corrected when it is genuinely wrong, but when the
	 * only problem is that no administrator has ever signed in, writing the (already correct)
	 * policy would be a no-op dressed up as a fix, so the officer is told what to do instead.
	 */
	private function enforceApprovalPolicy(): void
	{
		$diagnosis = Gate::registrationDiagnosis();

		if ($diagnosis['problem'] === null) {
			return;
		}

		if (Gate::requireApproval()) {
			DI::sysmsg()->addInfo('Registration was changed to "approval required" so an officer checks every application before the account works.');

			$this->audit('settings.register_policy', 'config', 0, ['register_policy' => \Friendica\Module\Register::APPROVE]);

			return;
		}

		// Nothing to write: the setting is already right, so say what is actually wrong.
		DI::sysmsg()
			->addNotice('Registration is still closed because no site administrator has signed in recently. '
				. 'Sign in as the site administrator to open it.');

		$this->audit('settings.register_policy_blocked', 'config', 0, [
			'problem'    => $diagnosis['problem'],
			'configured' => $diagnosis['configured'],
			'effective'  => $diagnosis['effective'],
		]);
	}

	private function repairSchema(): void
	{
		$this->requireSiteAdmin();

		try {
			$result = DBStructure::performUpdate(false, true);
		} catch (\Throwable $e) {
			DI::logger()->error('OrgSuite: schema update failed', ['exception' => $e]);
			DI::sysmsg()->addNotice('The schema update failed: ' . $e->getMessage());

			return;
		}

		OrgSchema::resetReadyCache();

		// Seeding and back-filling run here too, so an administrator who repairs the tables
		// after an update gets the new positions without a second, less obvious step.
		$migrated = OrgSchema::migrate();

		$messages = array_filter([
			trim($result),
			$migrated['seeded'] > 0 ? $migrated['seeded'] . ' built-in position(s) added.' : '',
			$migrated['moved'] > 0 ? $migrated['moved'] . ' member record(s) given a position.' : '',
		]);

		DI::sysmsg()->addInfo($messages !== [] ? implode(' ', $messages) : 'The organization tables are up to date.');
		$this->audit('schema.update', OrgConfig::CATEGORY, 0);
	}

	// ----------------------------------------------------------------- audit

	private function auditTab(): string
	{
		$rows = [];

		foreach (AuditLog::recent(200) as $entry) {
			$actorUid = (int) $entry['actor_uid'];
			$actor     = 'system';

			if ($actorUid > 0) {
				$actorUser = User::getById($actorUid, ['username']);
				$actor     = $actorUser ? (string) $actorUser['username'] : '#' . $actorUid;
			}

			$rows[] = [
				Text::h((string) $entry['created']),
				Text::h($actor),
				Text::h((string) $entry['action']),
				Text::h((string) ($entry['detail'] ?? '')),
			];
		}

		return $this->card(
			'History',
			$this->table(['When', 'Who', 'Action', 'Detail'], $rows, 'Nothing recorded yet.')
		);
	}
}
