<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The organization dashboard.
 *
 * The problem this solves is not technical. A president or treasurer who has never
 * administered a federated server should be able to land on one page and see what is
 * outstanding without knowing what a relay, a circle or an ActivityPub actor is. So this page
 * shows plain-language cards: what is being announced, whether your own membership is in
 * order, what you owe, and which documents changed recently.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite\Module;

use Friendica\Addon\OrgSuite\AnnouncementRepository;
use Friendica\Addon\OrgSuite\DuesRepository;
use Friendica\Addon\OrgSuite\Gate;
use Friendica\Addon\OrgSuite\MemberRepository;
use Friendica\Addon\OrgSuite\OrgConfig;
use Friendica\Addon\OrgSuite\Text;
use Friendica\Addon\OrgSuite\VaultRepository;
use Friendica\DI;

class Dashboard extends BaseOrgModule
{
	protected function content(array $request = []): string
	{
		$this->requireLogin();

		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_DASHBOARD)) {
			$this->baseUrl->redirect('network');
		}

		$member = $this->member();

		$body = $this->announcements()
			. $this->membershipCard($member)
			. '<div class="orgsuite-grid">'
			. $this->duesCard($member)
			. $this->documentsCard()
			. '</div>'
			. $this->officerCard();

		return $this->renderPage(
			OrgConfig::getOrgName(),
			OrgConfig::getOrgTagline(),
			'dashboard',
			$body
		);
	}

	private function announcements(): string
	{
		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_ANNOUNCEMENTS)) {
			return '';
		}

		$rows = AnnouncementRepository::visible($this->localUid() > 0, $this->isOfficer(), $this->isSiteAdmin());

		if ($rows === []) {
			return '';
		}

		$html = '';

		foreach ($rows as $row) {
			$level = (string) $row['level'];

			$html .= '<div class="orgsuite-announcement orgsuite-announcement--' . Text::h($level) . '">'
				. '<h3>' . Text::h((string) $row['title']) . '</h3>'
				. ($row['body'] !== null ? '<p>' . nl2br(Text::h((string) $row['body'])) . '</p>' : '')
				. ($row['link'] !== '' ? '<p><a href="' . Text::h((string) $row['link']) . '">Read more</a></p>' : '')
				. '</div>';
		}

		return '<div class="orgsuite-announcements">' . $html . '</div>';
	}

	/**
	 * @param array<string, mixed>|null $member
	 */
	private function membershipCard(?array $member): string
	{
		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_MEMBERS)) {
			return '';
		}

		if ($member === null) {
			return $this->card(
				'Your membership',
				$this->emptyState('There is no membership record linked to your account yet.')
			);
		}

		$rows = [
			[
				'<strong>' . Text::h(OrgConfig::STATUS_LABELS[$member['status']] ?? (string) $member['status']) . '</strong>',
				$this->statusBadge((string) $member['status']),
			],
			['Role', Text::h(OrgConfig::ROLE_LABELS[$member['role']] ?? (string) $member['role'])],
		];

		if ($member['unit'] !== '') {
			$rows[] = ['Unit / membership', Text::h((string) $member['unit'])];
		}

		if ($member['verified_at'] !== null) {
			$rows[] = ['Verified on', Text::h((string) $member['verified_at'])];
		}

		$footer = '';

		if ($member['status'] === 'pending') {
			$footer = '<p class="orgsuite-note">An officer still needs to confirm your '
				. Text::h(OrgConfig::getCredentialLabel()) . '. You can browse the site, but '
				. 'organization features unlock once that is done.</p>';
		} elseif ($member['status'] !== 'active') {
			$footer = '<p class="orgsuite-note">Your membership is not active. Contact an officer if you believe this is wrong.</p>';
		}

		$html = '';

		foreach ($rows as $row) {
			$html .= '<dt class="orgsuite-definition__term">' . $row[0] . '</dt>'
				. '<dd class="orgsuite-definition__value">' . $row[1] . '</dd>';
		}

		return $this->card('Your membership', '<dl class="orgsuite-definition">' . $html . '</dl>', $footer);
	}

	/**
	 * @param array<string, mixed>|null $member
	 */
	private function duesCard(?array $member): string
	{
		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_DUES) || $member === null) {
			return '';
		}

		$balance = DuesRepository::balanceFor((int) $member['uid']);

		$body = '<p class="orgsuite-amount orgsuite-amount--' . ($balance > 0 ? 'due' : 'clear') . '">'
			. Text::h(Text::formatMoney($balance)) . '</p>'
			. '<p class="orgsuite-note">' . ($balance > 0 ? 'Outstanding balance' : 'Nothing outstanding') . '</p>';

		$charges = DuesRepository::chargesFor((int) $member['uid']);
		$rows    = [];

		foreach (array_slice($charges, 0, 5) as $charge) {
			$rows[] = [
				Text::h((string) ($charge['period'] !== '' ? $charge['period'] : $charge['description'])),
				Text::h(Text::formatMoney((int) $charge['amount_cents'])),
				Text::h(DuesRepository::CHARGE_STATUS[$charge['status']] ?? (string) $charge['status']),
			];
		}

		$body .= $this->table(['Period', 'Amount', 'Status'], $rows, 'No charges have been raised.');

		$footer = '<p class="orgsuite-note">Payments are recorded by the treasurer. '
			. 'Bring your payment to an officer or ask them to mark it received.</p>';

		return $this->card('Dues', $body, $footer);
	}

	private function documentsCard(): string
	{
		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_VAULT)) {
			return '';
		}

		$rows = [];

		foreach (VaultRepository::recentDocuments(6) as $document) {
			$folder   = VaultRepository::folderById((int) $document['folder_id']);
			$rows[] = [
				'<a href="orgsuite/documents/download/' . (int) $document['id'] . '">' . Text::h((string) $document['title']) . '</a>',
				Text::h($folder !== null ? (string) $folder['name'] : 'Unfiled'),
				Text::h((string) $document['created']),
			];
		}

		return $this->card(
			'Recently added documents',
			$this->table(['Document', 'Folder', 'Added'], $rows, 'Nothing has been filed yet.'),
			'<p><a href="orgsuite/documents">Open the document library</a></p>'
		);
	}

	private function officerCard(): string
	{
		if (!$this->isOfficerOrAdmin()) {
			return '';
		}

		$pending = \Friendica\Addon\OrgSuite\VerificationRepository::pendingCount();
		$items   = [];

		$items[] = '<li><a href="orgsuite/console">Officer console</a></li>';

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_GATED_REGISTRATION)) {
			$items[] = '<li>' . ($pending > 0
				? '<strong>' . $pending . '</strong> application(s) waiting for verification &mdash; <a href="orgsuite/console?tab=applications">review now</a>'
				: 'No applications waiting for verification') . '</li>';
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_VAULT)) {
			$items[] = '<li>' . VaultRepository::documentCount() . ' document(s) in the library</li>';
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_DUES)) {
			$summary = DuesRepository::summary();
			$items[] = '<li>Outstanding dues: <strong>' . Text::h(Text::formatMoney($summary['outstanding_cents'])) . '</strong>'
				. ' across ' . $summary['open_charges'] . ' charge(s)</li>';
		}

		if (!Gate::isEnabled()) {
			$items[] = '<li class="orgsuite-warn">Gated registration is switched off. Anyone can register.</li>';
		}

		return $this->card('For officers', '<ul class="orgsuite-list">' . implode('', $items) . '</ul>');
	}
}
