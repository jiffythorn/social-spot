<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Member directory.
 *
 * Members control their own visibility with the "show me in the directory" switch, which is
 * a privacy decision the organization should not be able to make on their behalf. Officers
 * additionally see who has opted out, so the list is still useful for administration without
 * exposing anyone against their wishes.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite\Module;

use Friendica\Addon\OrgSuite\MemberRepository;
use Friendica\Addon\OrgSuite\OrgConfig;
use Friendica\Addon\OrgSuite\Text;

class Directory extends BaseOrgModule
{
	protected function content(array $request = []): string
	{
		$this->requireLogin();

		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_MEMBERS)) {
			$this->baseUrl->redirect('orgsuite');
		}

		$rows = [];
		$seen = [];

		foreach (MemberRepository::directory() as $member) {
			$seen[(int) $member['uid']] = true;

			$rows[] = [
				'<strong>' . Text::h($member['household'] !== '' ? (string) $member['household'] : 'Member #' . (int) $member['uid']) . '</strong>',
				Text::h((string) $member['unit']),
				Text::h(OrgConfig::ROLE_LABELS[$member['role']] ?? (string) $member['role']),
				$this->statusBadge((string) $member['status']),
				Text::h((string) ($member['directory_note'] ?? '')),
			];
		}

		$body = $this->card(
			'Members',
			'<p class="orgsuite-note">Only members who chose to appear are listed here.</p>'
				. $this->table(['Household', OrgConfig::getProfile() === OrgConfig::PROFILE_HOA ? 'Unit' : 'Membership ID', 'Role', 'Standing', 'Note'], $rows, 'No members have opted into the directory yet.')
		);

		if ($this->isOfficerOrAdmin()) {
			$hidden = [];

			foreach (MemberRepository::listAll() as $member) {
				if (isset($seen[(int) $member['uid']]) || !(bool) $member['directory']) {
					if (!(bool) $member['directory'] || isset($seen[(int) $member['uid']])) {
						continue;
					}
				}

				$hidden[] = [
					'#' . (int) $member['uid'],
					Text::h((string) $member['unit']),
					$this->statusBadge((string) $member['status']),
					$this->badge('Hidden from the directory', 'muted'),
				];
			}

			$body .= $this->card(
				'Not listed publicly',
				$this->table(['Account', 'Unit / ID', 'Standing', ''], $hidden, 'Every member is listed.')
					. '<p class="orgsuite-note">Members who opted out are shown here for administration only.</p>'
			);
		}

		return $this->renderPage('Member directory', OrgConfig::getOrgName(), 'directory', $body);
	}
}
