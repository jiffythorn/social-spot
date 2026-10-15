<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared behaviour for the organization suite pages.
 *
 * Every page in this addon renders its own markup instead of relying on templates. That is a
 * deliberate consequence of how Friendica 2026.05 resolves templates: the search path is the
 * active theme, then its parent theme, then the shared view directory, so an addon cannot
 * ship a template at all. Generating markup here also means the pages keep working whichever
 * theme an organization picks, and there is no second template language to maintain when
 * upstream is merged.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite\Module;

use Friendica\Addon\OrgSuite\AuditLog;
use Friendica\Addon\OrgSuite\MemberRepository;
use Friendica\Addon\OrgSuite\OrgConfig;
use Friendica\Addon\OrgSuite\OrgSchema;
use Friendica\Addon\OrgSuite\Text;
use Friendica\BaseModule;
use Friendica\DI;
use Friendica\Addon\OrgSuite\Permissions;
use Friendica\Addon\OrgSuite\RoleRepository;

abstract class BaseOrgModule extends BaseModule
{
	/** Maximum length of any free-text field accepted from a form in this addon. */
	protected const MAX_TEXT = 5000;

	protected function localUid(): int
	{
		return (int) DI::userSession()->getLocalUserId();
	}

	protected function requireLogin(): int
	{
		$uid = $this->localUid();

		if ($uid <= 0) {
			$this->baseUrl->redirect('login?return=' . urlencode($this->args->getQueryString()));
		}

		return $uid;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	protected function member(): ?array
	{
		return MemberRepository::forCurrentUser();
	}

	protected function isOfficer(): bool
	{
		$member = $this->member();

		if ($member === null || $member['status'] !== 'active') {
			return false;
		}

		if (!OrgSchema::ready()) {
			return in_array((string) $member['role'], OrgConfig::OFFICER_ROLES, true);
		}

		return RoleRepository::isOfficerMember($member);
	}

	/**
	 * Whether the reader holds a specific permission.
	 *
	 * Every gate that used to ask "is this person an officer?" can now ask the narrower and
	 * more truthful question instead, which is what makes a custom position worth creating.
	 */
	protected function can(string $capability): bool
	{
		return Permissions::can($capability, $this->localUid());
	}

	/**
	 * A specific permission, or out. Sends the reader somewhere useful with an explanation
	 * rather than showing a bare error, because the reader is a volunteer on a phone.
	 */
	protected function requirePermission(string $capability): void
	{
		$this->requireLogin();

		if ($this->can($capability)) {
			return;
		}

		DI::logger()->notice('OrgSuite: permission denied', [
			'capability' => $capability,
			'uid'        => $this->localUid(),
		]);

		DI::sysmsg()->addNotice(
			'Your position in ' . OrgConfig::getOrgName() . ' does not include "' . Permissions::label($capability)
			. '". Ask whoever manages positions to grant it.'
		);

		$this->baseUrl->redirect('orgsuite');
	}

	protected function isSiteAdmin(): bool
	{
		return (bool) DI::userSession()->isSiteAdmin();
	}

	protected function isOfficerOrAdmin(): bool
	{
		return $this->isSiteAdmin() || $this->isOfficer();
	}

	/**
	 * Officers and site administrators only. Redirects with an explanation rather than
	 * showing a bare "page not found".
	 */
	protected function requireOfficer(): void
	{
		$this->requireLogin();

		if (!$this->isOfficerOrAdmin()) {
			DI::sysmsg()->addNotice('That area is limited to organization officers and site administrators.');
			$this->baseUrl->redirect('orgsuite');
		}
	}

	protected function requireSiteAdmin(): void
	{
		$this->requireLogin();

		if (!$this->isSiteAdmin()) {
			DI::sysmsg()->addNotice('That section is limited to site administrators.');
			$this->baseUrl->redirect('orgsuite/console');
		}
	}

	/**
	 * Truncates free text coming from a form and strips control characters.
	 */
	protected function cleanText(array $request, string $key, int $max = self::MAX_TEXT): string
	{
		$value = $request[$key] ?? '';
		$value = is_string($value) ? $value : '';

		$value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';

		return mb_substr(trim($value), 0, $max);
	}

	protected function cleanInt(array $request, string $key, int $default = 0): int
	{
		$value = $request[$key] ?? null;

		return is_numeric($value) ? (int) $value : $default;
	}

	protected function cleanBool(array $request, string $key): bool
	{
		$value = $request[$key] ?? null;

		return $value === '1' || $value === 1 || $value === true || $value === 'on';
	}

	/**
	 * Accepts a YYYY-MM-DD date, or returns null. Anything else is treated as "not set"
	 * rather than being written into the database.
	 */
	protected function cleanDate(array $request, string $key): ?string
	{
		$value = trim((string) ($request[$key] ?? ''));

		if ($value === '') {
			return null;
		}

		$parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

		return ($parsed !== false && $parsed->format('Y-m-d') === $value) ? $value : null;
	}

	// ------------------------------------------------------------- rendering

	/**
	 * Page shell: heading, tab bar, body and the persistent schema warning.
	 */
	protected function renderPage(string $title, string $subtitle, string $activeTab, string $body, ?array $tabs = null): string
	{
		DI::page()['title'] = $title;

		$tabs = $tabs ?? $this->mainTabs();

		$tabBar = '';

		if ($tabs !== []) {
			$items = '';

			foreach ($tabs as $key => $tab) {
				$selected = $key === $activeTab ? ' orgsuite-tab--active' : '';
				$items .= '<a class="orgsuite-tab' . $selected . '" href="' . Text::h($tab['url']) . '">'
					. Text::h($tab['label']) . '</a>';
			}

			$tabBar = '<nav class="orgsuite-tabs" aria-label="Sections">' . $items . '</nav>';
		}

		return '<div class="orgsuite orgsuite-page">'
			. $this->schemaWarning()
			. '<header class="orgsuite-header">'
			. '<h1>' . Text::h($title) . '</h1>'
			. ($subtitle !== '' ? '<p class="orgsuite-subtitle">' . Text::h($subtitle) . '</p>' : '')
			. '</header>'
			. $tabBar
			. '<div class="orgsuite-body">' . $body . '</div>'
			. '</div>';
	}

	/**
	 * The tab bar for member-facing pages, with officer-only entries filtered out.
	 *
	 * @return array<string, array{label: string, url: string}>
	 */
	protected function mainTabs(): array
	{
		$tabs = [];

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_DASHBOARD)) {
			$tabs['dashboard'] = ['label' => 'Overview', 'url' => 'orgsuite'];
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_VAULT)) {
			$tabs['documents'] = ['label' => 'Documents', 'url' => 'orgsuite/documents'];
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_MEMBERS)) {
			$tabs['directory'] = ['label' => 'Members', 'url' => 'orgsuite/directory'];
		}

		if ($this->isOfficerOrAdmin()) {
			$tabs['console'] = ['label' => 'Officer console', 'url' => 'orgsuite/console'];
		}

		return $tabs;
	}

	/**
	 * Warning shown while the addon's tables are missing, with the exact command to fix it.
	 */
	protected function schemaWarning(): string
	{
		if (OrgSchema::ready()) {
			return '';
		}

		return '<div class="orgsuite-alert orgsuite-alert--error">'
			. '<strong>The organization tables have not been created yet.</strong>'
			. '<p>Run <code>bin/console dbstructure update</code> on this instance, then reload this page. '
			. 'Enable the addon first: tables are created from the addon\'s own schema definition.</p>'
			. '</div>';
	}

	protected function card(string $title, string $body, string $footer = ''): string
	{
		return '<section class="orgsuite-card">'
			. ($title !== '' ? '<h2 class="orgsuite-card__title">' . Text::h($title) . '</h2>' : '')
			. '<div class="orgsuite-card__body">' . $body . '</div>'
			. ($footer !== '' ? '<div class="orgsuite-card__footer">' . $footer . '</div>' : '')
			. '</section>';
	}

	protected function emptyState(string $message): string
	{
		return '<p class="orgsuite-empty">' . Text::h($message) . '</p>';
	}

	/**
	 * @param string[]           $headers
	 * @param array<int, string[]> $rows
	 */
	protected function table(array $headers, array $rows, string $emptyMessage = 'Nothing to show yet.'): string
	{
		if ($rows === []) {
			return $this->emptyState($emptyMessage);
		}

		$head = '';

		foreach ($headers as $header) {
			$head .= '<th>' . Text::h($header) . '</th>';
		}

		$body = '';

		foreach ($rows as $row) {
			$cells = '';

			foreach ($row as $cell) {
				// Cells are pre-rendered HTML so callers can embed links and forms.
				$cells .= '<td>' . $cell . '</td>';
			}

			$body .= '<tr>' . $cells . '</tr>';
		}

		return '<div class="orgsuite-table-wrap"><table class="orgsuite-table">'
			. '<thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
	}

	protected function link(string $label, string $url, string $variant = ''): string
	{
		$class = 'orgsuite-button' . ($variant !== '' ? ' orgsuite-button--' . $variant : '');

		return '<a class="' . $class . '" href="' . Text::h($url) . '">' . Text::h($label) . '</a>';
	}

	protected function formStart(string $action, string $tokenName): string
	{
		return '<form class="orgsuite-form" method="post" action="' . Text::h($action) . '" enctype="multipart/form-data">'
			. '<input type="hidden" name="form_security_token" value="' . Text::h(self::getFormSecurityToken($tokenName)) . '" />';
	}

	protected function textField(string $name, string $label, string $value = '', string $type = 'text', string $help = '', bool $required = false): string
	{
		return '<label class="orgsuite-field">'
			. '<span class="orgsuite-field__label">' . Text::h($label) . ($required ? ' <span class="orgsuite-required">*</span>' : '') . '</span>'
			. '<input type="' . Text::h($type) . '" name="' . Text::h($name) . '" value="' . Text::h($value) . '"' . ($required ? ' required' : '') . ' />'
			. ($help !== '' ? '<span class="orgsuite-field__help">' . Text::h($help) . '</span>' : '')
			. '</label>';
	}

	protected function textArea(string $name, string $label, string $value = '', string $help = ''): string
	{
		return '<label class="orgsuite-field">'
			. '<span class="orgsuite-field__label">' . Text::h($label) . '</span>'
			. '<textarea name="' . Text::h($name) . '" rows="3">' . Text::h($value) . '</textarea>'
			. ($help !== '' ? '<span class="orgsuite-field__help">' . Text::h($help) . '</span>' : '')
			. '</label>';
	}

	/**
	 * @param array<string, string> $options value => label
	 */
	protected function selectField(string $name, string $label, array $options, string $selected = '', string $help = ''): string
	{
		$html = '';

		foreach ($options as $value => $optionLabel) {
			$html .= '<option value="' . Text::h((string) $value) . '"' . ((string) $value === $selected ? ' selected' : '') . '>'
				. Text::h($optionLabel) . '</option>';
		}

		return '<label class="orgsuite-field">'
			. '<span class="orgsuite-field__label">' . Text::h($label) . '</span>'
			. '<select name="' . Text::h($name) . '">' . $html . '</select>'
			. ($help !== '' ? '<span class="orgsuite-field__help">' . Text::h($help) . '</span>' : '')
			. '</label>';
	}

	protected function checkbox(string $name, string $label, bool $checked, string $help = ''): string
	{
		return '<label class="orgsuite-checkbox">'
			. '<input type="hidden" name="' . Text::h($name) . '" value="0" />'
			. '<input type="checkbox" name="' . Text::h($name) . '" value="1"' . ($checked ? ' checked' : '') . ' />'
			. '<span>' . Text::h($label) . '</span>'
			. ($help !== '' ? '<span class="orgsuite-field__help">' . Text::h($help) . '</span>' : '')
			. '</label>';
	}

	protected function hiddenField(string $name, string $value): string
	{
		return '<input type="hidden" name="' . Text::h($name) . '" value="' . Text::h($value) . '" />';
	}

	protected function submitButton(string $label, string $variant = 'primary', array $hidden = []): string
	{
		$fields = '';

		foreach ($hidden as $name => $value) {
			$fields .= $this->hiddenField((string) $name, (string) $value);
		}

		return $fields
			. '<button type="submit" class="orgsuite-button orgsuite-button--' . Text::h($variant) . '">'
			. Text::h($label) . '</button>';
	}

	/**
	 * A single POST form rendered as a button, used for approve / reject / delete actions.
	 *
	 * @param array<string, string> $fields
	 */
	protected function actionForm(string $action, string $tokenName, array $fields, string $label, string $variant = 'secondary', string $confirm = ''): string
	{
		$html = '<form class="orgsuite-inline-form" method="post" action="' . Text::h($action) . '"'
			. ($confirm !== '' ? ' onsubmit="return confirm(\'' . Text::h(addslashes($confirm)) . '\')"' : '')
			. '>'
			. '<input type="hidden" name="form_security_token" value="' . Text::h(self::getFormSecurityToken($tokenName)) . '" />';

		foreach ($fields as $name => $value) {
			$html .= $this->hiddenField((string) $name, (string) $value);
		}

		return $html . '<button type="submit" class="orgsuite-button orgsuite-button--' . Text::h($variant) . '">'
			. Text::h($label) . '</button></form>';
	}

	protected function badge(string $label, string $variant = 'neutral'): string
	{
		return '<span class="orgsuite-badge orgsuite-badge--' . Text::h($variant) . '">' . Text::h($label) . '</span>';
	}

	protected function statusBadge(string $status): string
	{
		$variant = match ($status) {
			'active'  => 'ok',
			'pending' => 'warn',
			'former'  => 'muted',
			default   => 'bad',
		};

		return $this->badge(OrgConfig::STATUS_LABELS[$status] ?? $status, $variant);
	}

	protected function audit(string $action, string $targetType = '', int $targetId = 0, $detail = null): void
	{
		AuditLog::record($action, $targetType, $targetId, $detail);
	}
}
