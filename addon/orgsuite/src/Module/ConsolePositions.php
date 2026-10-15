<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The Positions and Committees tabs of the officer console.
 *
 * Kept in its own file because it is one self-contained subject: who holds what position,
 * what that position may do, and who sits on which committee. Everything here is written for
 * a volunteer president rather than an administrator, so positions are described in terms of
 * what a person can do ("Raise assessments and record payments") rather than in terms of
 * system internals.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite\Module;

use Friendica\Addon\OrgSuite\AuditLog;
use Friendica\Addon\OrgSuite\CommitteeRepository;
use Friendica\Addon\OrgSuite\MemberRepository;
use Friendica\Addon\OrgSuite\OrgConfig;
use Friendica\Addon\OrgSuite\OrgSchema;
use Friendica\Addon\OrgSuite\Permissions;
use Friendica\Addon\OrgSuite\RoleRepository;
use Friendica\Addon\OrgSuite\Text;
use Friendica\DI;
use Friendica\Model\User;

trait ConsolePositions
{
	// -------------------------------------------------------------- positions

	private function positionsTab(): string
	{
		$html = '<div class="orgsuite-alert orgsuite-alert--warn"><strong>How positions work</strong>'
			. '<p>A position is a job in this organization — President, Treasurer, Maintenance lead, '
			. 'whatever you need. Tick what each one is allowed to do and assign it to people on the '
			. 'Members tab. You can invent as many as you like, so nothing has to be forced into a '
			. 'shape that does not fit your association.</p></div>';

		$html .= $this->positionsCard();
		$html .= $this->newPositionCard();

		if ($this->isSiteAdmin()) {
			$html .= $this->platformPositionsCard();
		}

		return $html;
	}

	private function positionsCard(): string
	{
		$roles  = RoleRepository::organizationRoles(false);
		$counts = RoleRepository::holderCounts();
		$canEdit = $this->can(Permissions::MANAGE_ROLES);

		$rows = [];

		foreach ($roles as $role) {
			$holders = (int) ($counts[$role['id']] ?? 0);

			$permissions = array_map(
				static fn (string $key): string => Permissions::label($key),
				$role['permissions']
			);

			$summary = $permissions === []
				? '<span class="orgsuite-muted">Nothing beyond being a member</span>'
				: Text::h(implode(' · ', $permissions));

			$name = '<strong>' . Text::h((string) $role['label']) . '</strong>';

			if ($role['is_default']) {
				$name .= ' ' . $this->badge('default for new members', 'ok');
			}

			$name .= '<br /><span class="orgsuite-note">' . Text::h((string) ($role['description'] ?? '')) . '</span>';
			$name .= '<br /><span class="orgsuite-note">' . $summary . '</span>';

			$actions = '';

			if ($canEdit) {
				$actions .= $this->roleEditorForm($role);
			}

			$rows[] = [
				$name,
				$holders === 0 ? '<span class="orgsuite-muted">nobody</span>' : (string) $holders,
				$actions,
			];
		}

		$body = $this->table(['Position', 'Held by', 'Rename or change what it allows'], $rows,
			'No positions yet. Repair the tables in Organization settings to add the built-in ones.');

		return $this->card('Positions in this organization', $body);
	}

	/**
	 * The inline editor for one position: its name, its seniority and its permissions.
	 */
	private function roleEditorForm(array $role): string
	{
		$html = '<details class="orgsuite-details"><summary>Edit "' . Text::h((string) $role['label']) . '"</summary>'
			. $this->formStart('orgsuite/console', self::TOKEN)
			. $this->hiddenField('action', 'role_save')
			. $this->hiddenField('return_tab', 'positions')
			. $this->hiddenField('id', (string) $role['id'])
			. $this->textField('label', 'Position name', (string) $role['label'], 'text', 'What members see, e.g. Treasurer.')
			. $this->textField('description', 'What it is for', (string) ($role['description'] ?? ''), 'text', '')
			. $this->textField('rank', 'Seniority', (string) $role['rank'], 'number',
				'Higher outranks lower. Only used to order lists such as the board.')
			. $this->permissionPicker('permissions', $role['permissions'])
			. '<p class="orgsuite-actions">' . $this->submitButton('Save position') . '</p>'
			. '</form>';

		$html .= '<div class="orgsuite-row-actions">';

		if (!$role['is_default']) {
			$html .= $this->actionForm('orgsuite/console', self::TOKEN, [
				'action' => 'role_default', 'id' => $role['id'], 'return_tab' => 'positions',
			], 'Give to new members', 'secondary');
		}

		if (!$role['is_system']) {
			$holders = RoleRepository::countHolders((int) $role['id']);

			$confirm = $holders > 0
				? 'Delete this position? ' . $holders . ' member(s) holding it will be moved to the default position.'
				: 'Delete this position?';

			$html .= $this->actionForm('orgsuite/console', self::TOKEN, [
				'action' => 'role_delete', 'id' => $role['id'], 'return_tab' => 'positions',
			], 'Delete', 'danger', $confirm);
		} else {
			$html .= '<span class="orgsuite-note">Built-in position; it can be renamed but not deleted.</span>';
		}

		return $html . '</div></details>';
	}

	private function newPositionCard(): string
	{
		if (!$this->can(Permissions::MANAGE_ROLES)) {
			return $this->card('Add a position',
				$this->emptyState('Only someone whose position includes "Manage positions and permissions" can add positions.'));
		}

		$body = '<p class="orgsuite-note">For example "Maintenance lead", "Security officer", '
			. '"Architectural review chair" or "Welcoming committee". Tick only what it needs.</p>'
			. $this->formStart('orgsuite/console', self::TOKEN)
			. $this->hiddenField('action', 'role_create')
			. $this->hiddenField('return_tab', 'positions')
			. $this->textField('label', 'Position name', '', 'text', '', true)
			. $this->textField('description', 'What it is for', '', 'text', '')
			. $this->textField('rank', 'Seniority', '50', 'number', 'Higher outranks lower.')
			. $this->permissionPicker('permissions')
			. '<p class="orgsuite-actions">' . $this->submitButton('Add position') . '</p>'
			. '</form>';

		return $this->card('Add a position', $body);
	}

	/**
	 * The permission checkboxes, grouped, with the field names the request handlers read.
	 */
	private function permissionPicker(string $prefix, array $granted = []): string
	{
		$html = '';

		foreach (Permissions::CATALOG as $group) {
			$boxes = '';

			foreach ($group['permissions'] as $key => $definition) {
				if (Permissions::isPlatform($key)) {
					continue;
				}

				$field = $prefix . '[' . $key . ']';

				$boxes .= '<label class="orgsuite-checkbox">'
					. '<input type="hidden" name="' . Text::h($field) . '" value="0" />'
					. '<input type="checkbox" name="' . Text::h($field) . '" value="1"'
					. (in_array($key, $granted, true) ? ' checked' : '') . ' />'
					. '<span>' . Text::h($definition['label']) . '<br />'
					. '<span class="orgsuite-note">' . Text::h($definition['description']) . '</span></span>'
					. '</label>';
			}

			if ($boxes !== '') {
				$html .= '<fieldset class="orgsuite-fieldset"><legend>' . Text::h($group['label']) . '</legend>'
					. $boxes . '</fieldset>';
			}
		}

		return $html;
	}

	/**
	 * Platform positions, shown only to a site administrator.
	 *
	 * These describe who runs the server rather than who runs the association, and an
	 * association cannot consent to giving away the machine it is hosted on, so the choice is
	 * deliberately not in an officer's hands.
	 */
	private function platformPositionsCard(): string
	{
		$rows = [];

		foreach (RoleRepository::platformRoles(false) as $role) {
			$permissions = array_map(
				static fn (string $key): string => Permissions::label($key),
				$role['permissions']
			);

			$rows[] = [
				'<strong>' . Text::h((string) $role['label']) . '</strong><br />'
					. '<span class="orgsuite-note">' . Text::h((string) ($role['description'] ?? '')) . '</span>',
				Text::h(implode(' · ', $permissions)),
			];
		}

		return $this->card('Who runs this server',
			$this->table(['Position', 'What it allows'], $rows)
				. '<p class="orgsuite-note">These are decided by whoever administers the installation, '
				. 'not by the organization. Site administration itself stays Friendica\'s own administrator '
				. 'flag and cannot be granted from here.</p>'
		);
	}

	// ------------------------------------------------------------- committees

	private function committeesTab(): string
	{
		$canManage = $this->can(Permissions::MANAGE_COMMITTEES);

		$html = '<div class="orgsuite-alert orgsuite-alert--warn"><strong>Committees</strong>'
			. '<p>Most organizations run on committees as much as on a board. Create one for each '
			. 'working group, give it a chair, and add the people on it. Sitting on a committee does not '
			. 'by itself give anybody extra powers — it records who is responsible.</p></div>';

		$committees = CommitteeRepository::all();

		$rows = [];

		foreach ($committees as $committee) {
			$seats = CommitteeRepository::members((int) $committee['id']);

			$people = [];

			foreach ($seats as $seat) {
				$user = User::getById((int) $seat['uid'], ['username', 'nickname']);
				$name = $user !== false ? (string) $user['username'] : '#' . (int) $seat['uid'];

				$people[] = Text::h($name) . ' <span class="orgsuite-note">('
					. Text::h(CommitteeRepository::officeLabel((string) $seat['office'])) . ')</span>';
			}

			$membersCell = $people === []
				? '<span class="orgsuite-muted">nobody yet</span>'
				: implode('<br />', $people);

			$actions = $canManage ? $this->committeeEditorForm($committee, $seats) : '';

			$rows[] = [
				'<strong>' . Text::h((string) $committee['name']) . '</strong>'
					. ' ' . $this->badge($committee['visibility'] === CommitteeRepository::SCOPE_BOARD ? 'board only' : 'visible to members', 'muted')
					. '<br /><span class="orgsuite-note">' . Text::h((string) ($committee['description'] ?? '')) . '</span>',
				$membersCell,
				$actions,
			];
		}

		$html .= $this->card(
			'Committees',
			$this->table(['Committee', 'Who is on it', ''], $rows,
				'No committees yet. Add one below, for example "Grounds" or "Events".')
		);

		if ($canManage) {
			$html .= $this->card('Add a committee',
				$this->formStart('orgsuite/console', self::TOKEN)
				. $this->hiddenField('action', 'committee_create')
				. $this->hiddenField('return_tab', 'committees')
				. $this->textField('name', 'Committee name', '', 'text', 'For example "Architectural review".', true)
				. $this->textField('description', 'What it is for', '', 'text', '')
				. $this->selectField('visibility', 'Who can see it', [
					CommitteeRepository::SCOPE_MEMBERS => 'All members',
					CommitteeRepository::SCOPE_BOARD   => 'Board and officers only',
				], CommitteeRepository::SCOPE_MEMBERS)
				. '<p class="orgsuite-actions">' . $this->submitButton('Add committee') . '</p>'
				. '</form>'
			);
		}

		return $html;
	}

	private function committeeEditorForm(array $committee, array $seats): string
	{
		$memberOptions = ['' => 'Choose a member…'];

		foreach (MemberRepository::listAll('active') as $member) {
			$user = User::getById((int) $member['uid'], ['username', 'email']);

			if ($user === false) {
				continue;
			}

			$memberOptions[(string) $member['uid']] = (string) $user['username'];
		}

		$officeOptions = CommitteeRepository::OFFICES;

		$seatRows = [];

		foreach ($seats as $seat) {
			$user = User::getById((int) $seat['uid'], ['username']);
			$name = $user !== false ? (string) $user['username'] : '#' . (int) $seat['uid'];

			$seatRows[] = [
				Text::h($name),
				Text::h(CommitteeRepository::officeLabel((string) $seat['office'])),
				$this->actionForm('orgsuite/console', self::TOKEN, [
					'action' => 'committee_unseat',
					'committee' => $committee['id'],
					'uid' => $seat['uid'],
					'return_tab' => 'committees',
				], 'Remove', 'secondary'),
			];
		}

		$html = '<details class="orgsuite-details"><summary>Manage "' . Text::h((string) $committee['name']) . '"</summary>';

		if ($seatRows !== []) {
			$html .= $this->table(['On the committee', 'Office', ''], $seatRows);
		}

		$html .= '<div class="orgsuite-row-actions">'
			. $this->formStart('orgsuite/console', self::TOKEN)
			. $this->hiddenField('action', 'committee_seat')
			. $this->hiddenField('return_tab', 'committees')
			. $this->hiddenField('committee', (string) $committee['id'])
			. $this->selectField('uid', 'Add someone', $memberOptions)
			. $this->selectField('office', 'As', $officeOptions, 'member')
			. $this->submitButton('Add to committee')
			. '</form>'
			. $this->formStart('orgsuite/console', self::TOKEN)
			. $this->hiddenField('action', 'committee_save')
			. $this->hiddenField('return_tab', 'committees')
			. $this->hiddenField('id', (string) $committee['id'])
			. $this->textField('name', 'Name', (string) $committee['name'], 'text')
			. $this->textField('description', 'What it is for', (string) ($committee['description'] ?? ''), 'text')
			. $this->selectField('visibility', 'Who can see it', [
				CommitteeRepository::SCOPE_MEMBERS => 'All members',
				CommitteeRepository::SCOPE_BOARD   => 'Board and officers only',
			], (string) $committee['visibility'])
			. $this->submitButton('Save committee')
			. '</form>'
			. '</div>';

		$html .= '<div class="orgsuite-row-actions">'
			. $this->actionForm('orgsuite/console', self::TOKEN, [
				'action' => 'committee_delete', 'id' => $committee['id'], 'return_tab' => 'committees',
			], 'Delete committee', 'danger', 'Delete this committee and its membership list?')
			. '</div></details>';

		return $html;
	}

	// --------------------------------------------------------------- handlers

	/**
	 * Reads the ticked permissions out of a submitted position form.
	 *
	 * The field names are `permissions[<key>]`, and an unticked box still submits a 0 thanks to
	 * the hidden input the checkbox helper emits, so the set of keys present is exactly the set
	 * the form offered.
	 */
	private function permissionsFromRequest(array $request): array
	{
		$submitted = $request['permissions'] ?? [];
		$granted   = [];

		if (is_array($submitted)) {
			foreach ($submitted as $key => $value) {
				if ($value === '1' || $value === 1 || $value === true) {
					$granted[] = (string) $key;
				}
			}
		}

		return Permissions::sanitize($granted);
	}

	private function createRole(array $request): void
	{
		$this->requirePermission(Permissions::MANAGE_ROLES);

		$label = $this->cleanText($request, 'label', 191);

		if ($label === '') {
			DI::sysmsg()->addNotice('A position needs a name.');

			return;
		}

		$id = RoleRepository::create([
			'label'       => $label,
			'description' => $this->cleanText($request, 'description', 255),
			'rank'        => $this->cleanInt($request, 'rank', 50),
			'permissions' => $this->permissionsFromRequest($request),
		]);

		if ($id === 0) {
			DI::sysmsg()->addNotice('That position could not be created.');

			return;
		}

		$this->audit('position.create', OrgSchema::TABLE_ROLE, $id, ['label' => $label]);
		DI::sysmsg()->addInfo('Position "' . $label . '" created. Assign it to people on the Members tab.');
	}

	private function saveRole(array $request): void
	{
		$this->requirePermission(Permissions::MANAGE_ROLES);

		$id   = $this->cleanInt($request, 'id');
		$role = RoleRepository::byId($id);

		if ($role === null) {
			DI::sysmsg()->addNotice('That position could not be found.');

			return;
		}

		RoleRepository::update($id, [
			'label'       => $this->cleanText($request, 'label', 191),
			'description' => $this->cleanText($request, 'description', 255),
			'rank'        => $this->cleanInt($request, 'rank', (int) $role['rank']),
			'permissions' => $this->permissionsFromRequest($request),
		]);

		$this->audit('position.update', OrgSchema::TABLE_ROLE, $id, [
			'permissions' => $this->permissionsFromRequest($request),
		]);

		DI::sysmsg()->addInfo('Position saved.');
	}

	private function deleteRole(array $request): void
	{
		$this->requirePermission(Permissions::MANAGE_ROLES);

		$id = $this->cleanInt($request, 'id');

		if (RoleRepository::countHolders($id) > 0) {
			$fallback = RoleRepository::defaultRole();

			if ($fallback === null || $fallback['id'] === $id) {
				DI::sysmsg()->addNotice('People still hold this position and there is no other position to move them to.');

				return;
			}

			$moved = RoleRepository::reassign($id, (int) $fallback['id']);

			DI::sysmsg()->addInfo($moved . ' member(s) moved to "' . $fallback['label'] . '".');
		}

		if (!RoleRepository::delete($id)) {
			DI::sysmsg()->addNotice('That position could not be deleted. Built-in positions can be renamed instead.');

			return;
		}

		$this->audit('position.delete', OrgSchema::TABLE_ROLE, $id);
		DI::sysmsg()->addInfo('Position deleted.');
	}

	private function defaultRole(array $request): void
	{
		$this->requirePermission(Permissions::MANAGE_ROLES);

		$id = $this->cleanInt($request, 'id');

		if (!RoleRepository::setDefault($id)) {
			DI::sysmsg()->addNotice('That position cannot be the default.');

			return;
		}

		$this->audit('position.default', OrgSchema::TABLE_ROLE, $id);
		DI::sysmsg()->addInfo('New members will now be given this position when their application is approved.');
	}

	// ------------------------------------------------------ committee handlers

	private function createCommittee(array $request): void
	{
		$this->requirePermission(Permissions::MANAGE_COMMITTEES);

		$name = $this->cleanText($request, 'name', 191);

		if ($name === '') {
			DI::sysmsg()->addNotice('A committee needs a name.');

			return;
		}

		$id = CommitteeRepository::create([
			'name'        => $name,
			'description' => $this->cleanText($request, 'description', 255),
			'visibility'  => $this->cleanText($request, 'visibility', 16),
			'chair_uid'   => $this->localUid(),
		]);

		if ($id === 0) {
			DI::sysmsg()->addNotice('That committee could not be created.');

			return;
		}

		$this->audit('committee.create', OrgSchema::TABLE_COMMITTEE, $id, ['name' => $name]);
		DI::sysmsg()->addInfo('Committee "' . $name . '" created. Add people to it below.');
	}

	private function saveCommittee(array $request): void
	{
		$this->requirePermission(Permissions::MANAGE_COMMITTEES);

		$id = $this->cleanInt($request, 'id');

		if (!CommitteeRepository::update($id, [
			'name'        => $this->cleanText($request, 'name', 191),
			'description' => $this->cleanText($request, 'description', 255),
			'visibility'  => $this->cleanText($request, 'visibility', 16),
		])) {
			DI::sysmsg()->addNotice('That committee could not be saved.');

			return;
		}

		$this->audit('committee.update', OrgSchema::TABLE_COMMITTEE, $id);
		DI::sysmsg()->addInfo('Committee saved.');
	}

	private function seatOnCommittee(array $request): void
	{
		$this->requirePermission(Permissions::MANAGE_COMMITTEES);

		$committee = $this->cleanInt($request, 'committee');
		$uid       = $this->cleanInt($request, 'uid');
		$office    = $this->cleanText($request, 'office', 32);

		if ($uid <= 0) {
			DI::sysmsg()->addNotice('Choose somebody to add to the committee.');

			return;
		}

		if (!CommitteeRepository::seat($committee, $uid, $office)) {
			DI::sysmsg()->addNotice('That person could not be added to the committee.');

			return;
		}

		$this->audit('committee.seat', OrgSchema::TABLE_COMMITTEE, $committee, ['uid' => $uid, 'office' => $office]);
		DI::sysmsg()->addInfo('Added to the committee as ' . CommitteeRepository::officeLabel(CommitteeRepository::sanitizeOffice($office)) . '.');
	}

	private function unseatFromCommittee(array $request): void
	{
		$this->requirePermission(Permissions::MANAGE_COMMITTEES);

		$committee = $this->cleanInt($request, 'committee');
		$uid       = $this->cleanInt($request, 'uid');

		if (!CommitteeRepository::remove($committee, $uid)) {
			DI::sysmsg()->addNotice('That person is not on the committee.');

			return;
		}

		$this->audit('committee.unseat', OrgSchema::TABLE_COMMITTEE, $committee, ['uid' => $uid]);
		DI::sysmsg()->addInfo('Removed from the committee.');
	}

	private function deleteCommittee(array $request): void
	{
		$this->requirePermission(Permissions::MANAGE_COMMITTEES);

		$id = $this->cleanInt($request, 'id');

		if (!CommitteeRepository::delete($id)) {
			DI::sysmsg()->addNotice('That committee could not be deleted.');

			return;
		}

		$this->audit('committee.delete', OrgSchema::TABLE_COMMITTEE, $id);
		DI::sysmsg()->addInfo('Committee deleted.');
	}
}
