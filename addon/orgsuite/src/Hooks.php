<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Hook handlers.
 *
 * Every hook name used here was read out of the 2026.05 source rather than the wiki, because
 * the 2026.05 release reworked the hook system and the public addon guide predates it:
 *
 *   route_collection       <- Friendica\Event\CollectRoutesEvent::COLLECT_ROUTES
 *   nav_info               <- ArrayFilterEvent::NAV_INFO
 *   page_content_top       <- HtmlFilterEvent::PAGE_CONTENT_TOP
 *   head                   <- HtmlFilterEvent::HEAD
 *   register_post          <- ArrayFilterEvent::ACCOUNT_REGISTER_POST
 *   register_account       <- ArrayFilterEvent::ACCOUNT_REGISTER
 *   moderation_users_tabs  <- ArrayFilterEvent::MODERATION_USERS_TABS
 *   dbstructure_definition <- ArrayFilterEvent::DB_STRUCTURE_DEFINITION
 *   init_1                 <- Event::INIT
 *
 * Handlers are called by Friendica\Core\Hook with a single argument passed by reference.
 * The legacy two-argument `function hook(App $a, &$b)` signature from the old wiki no longer
 * applies.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use FastRoute\RouteCollector;
use Friendica\Addon\OrgSuite\Module\Console;
use Friendica\Addon\OrgSuite\Module\Dashboard;
use Friendica\Addon\OrgSuite\Module\Directory;
use Friendica\Addon\OrgSuite\Module\Vault;
use Friendica\DI;

final class Hooks
{
	/**
	 * Registers this addon's front-end routes.
	 *
	 * Routes are only added for features that are switched on, so switching a feature off
	 * genuinely removes the page instead of leaving a half-working one behind.
	 */
	public static function routeCollection(RouteCollector &$collector): void
	{
		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_DASHBOARD)) {
			$collector->addRoute(['GET', 'POST'], '/orgsuite', Dashboard::class);
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_VAULT)) {
			$collector->addRoute(['GET', 'POST'], '/orgsuite/documents', Vault::class);
			$collector->addRoute(['GET'], '/orgsuite/documents/download/{id}', Vault::class);
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_MEMBERS)) {
			$collector->addRoute(['GET', 'POST'], '/orgsuite/directory', Directory::class);
		}

		$collector->addRoute(['GET', 'POST'], '/orgsuite/console', Console::class);
	}

	/**
	 * Puts the organization pages in the main navigation and hides the federated-network
	 * entries that mean nothing to a resident who wants the minutes and the dues.
	 *
	 * The important constraint here is that Friendica's shipped themes (vier and frio alike)
	 * render a *fixed* list of navigation keys and never iterate over the array. A brand new
	 * key such as `orgsuite` is therefore accepted by the hook and then silently never drawn,
	 * which looks exactly like the addon being broken. The only way to reach the main menu
	 * without patching core or demanding one specific theme is to re-purpose the keys every
	 * theme already draws, which is also precisely the desired outcome: the reader sees
	 * "Home", "Documents" and "Members" instead of "Network" and "Community".
	 *
	 * @see view/templates/nav.tpl for the base set, and view/theme/vier/templates/nav.tpl
	 */
	public static function navInfo(array &$navInfo): void
	{
		$nav = $navInfo['nav'] ?? [];

		if (!is_array($nav)) {
			return;
		}

		foreach (OrgConfig::getHiddenNavKeys() as $key) {
			unset($nav[$key]);
		}

		// A visitor who is not signed in still has to be able to reach the sign-up page and
		// the public information, so their navigation is left alone.
		if (!DI::userSession()->getLocalUserId()) {
			$navInfo['nav'] = $nav;

			return;
		}

		// An instance that has deliberately kept the social stream in front keeps the core
		// meaning of these two entries.
		if (!OrgConfig::isStreamFirst()) {
			if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_DASHBOARD)) {
				$nav['home'] = [
					'orgsuite',
					'Home',
					'',
					'What is happening in ' . OrgConfig::getOrgName(),
				];
			}

			if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_VAULT)) {
				$nav['network'] = [
					'orgsuite/documents',
					'Documents',
					'',
					'Bylaws, minutes, budgets and forms',
				];
			}
		}

		if (OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_MEMBERS)) {
			$nav['community'] = [
				'orgsuite/directory',
				'Members',
				'',
				'The member directory',
			];
		}

		$isOfficer = DI::userSession()->isSiteAdmin() || OrgConfig::isOfficer();

		if ($isOfficer) {
			$nav['apps'] = [
				'orgsuite/console',
				'Console',
				'',
				'Manage members, dues and documents',
			];
		} else {
			// A plain member must not be offered a door they cannot open.
			unset($nav['apps']);
		}

		$nav['usermenu'] = self::trimUserMenu($nav['usermenu'] ?? []);

		$navInfo['nav'] = $nav;
	}

	/**
	 * Removes the account-menu entries that only make sense on a social network.
	 *
	 * The account menu is built inside Nav.php rather than from the navigation array, so the
	 * only way to tidy it without editing core is to filter the rendered list here. Entries
	 * whose target is on the keep-list are always preserved, so a future upstream addition is
	 * kept by default rather than silently disappearing.
	 *
	 * @param array<int, array<int, string>> $usermenu
	 *
	 * @return array<int, array<int, string>>
	 */
	private static function trimUserMenu(array $usermenu): array
	{
		if (OrgConfig::isStreamFirst()) {
			return $usermenu;
		}

		// href prefixes that stay: the member still needs their own profile and their posts.
		$keep = ['profile/', 'notes/', 'settings', 'logout'];

		$trimmed = [];

		foreach ($usermenu as $entry) {
			$href = (string) ($entry[0] ?? '');

			foreach ($keep as $prefix) {
				if (str_starts_with($href, $prefix)) {
					$trimmed[] = $entry;
					break;
				}
			}
		}

		return $trimmed;
	}

	/**
	 * Registers the addon stylesheet.
	 *
	 * Page::initHead() registers the theme stylesheet and only afterwards dispatches the HEAD
	 * event, and head.tpl is rendered after that, so registering here reliably lands our
	 * stylesheet after the theme's — which is what makes the overrides win.
	 */
	public static function head(string &$html): void
	{
		DI::page()->registerStylesheet('addon/orgsuite/assets/orgsuite.css');
	}

	/**
	 * Prepends the announcement banner, and — when the active theme does not render the
	 * sign-up field itself — the credential field for gated registration.
	 *
	 * The injected input is associated with the real form through the HTML `form` attribute,
	 * so it submits with the form without JavaScript and without a template override.
	 */
	/**
	 * Offers the Corresponding Source, which the AGPL requires of a version served over a network.
	 *
	 * This is a licence obligation rather than a nicety: members are users interacting with our
	 * modified Friendica, and they must be able to obtain its source. The shared footer puts the
	 * offer on every page, signed in or not, which is what "prominently offer" asks for - a link
	 * buried behind a sign-in would not be an offer to the people the clause names.
	 *
	 * Rendering it in the footer rather than the navigation is deliberate: Friendica's themes draw
	 * a fixed set of navigation keys, so a new entry there would simply not appear.
	 */
	public static function footer(string &$html): void
	{
		$url = OrgConfig::getSourceUrl();

		if ($url === '') {
			return;
		}

		$html .= '<div class="orgsuite-source-offer">'
			. DI::l10n()->t('Running a modified Friendica. ')
			. '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" rel="noopener">'
			. DI::l10n()->t('Source code') . '</a></div>';
	}

	public static function pageContentTop(string &$html): void
	{
		$prefix = '';

		$prefix .= self::announcementBanner();

		if (Gate::isEnabled() && self::isRegisterPage() && !Gate::themeRendersField()) {
			$prefix .= '<div class="orgsuite-register-gate">' . Gate::fieldHtml(true) . '</div>';
		}

		$prefix .= self::signInExpectation();

		$html = $prefix . $html;
	}

	private static function announcementBanner(): string
	{
		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_ANNOUNCEMENTS)) {
			return '';
		}

		$uid = (int) DI::userSession()->getLocalUserId();

		if ($uid <= 0) {
			return '';
		}

		$rows = AnnouncementRepository::visible(
			true,
			OrgConfig::isOfficer(),
			(bool) DI::userSession()->isSiteAdmin(),
			MemberRepository::forCurrentUser()
		);

		if ($rows === []) {
			return '';
		}

		$html = '';

		foreach ($rows as $row) {
			$level = (string) $row['level'];

			$html .= '<div class="orgsuite-announcement orgsuite-announcement--' . Text::h($level) . '">'
				. '<p class="orgsuite-announcement__title">' . Text::h((string) $row['title']) . '</p>';

			if ($row['body'] !== null) {
				$html .= '<p class="orgsuite-announcement__body">' . nl2br(Text::h((string) $row['body'])) . '</p>';
			}

			if ($row['link'] !== '') {
				$html .= '<p class="orgsuite-announcement__link"><a href="' . Text::h((string) $row['link']) . '">Read more</a></p>';
			}

			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * Sets expectations on the sign-in page for people who have applied but not been released.
	 *
	 * A pending applicant cannot sign in, because Friendica only authenticates verified
	 * accounts, and the failure they see says to check their credentials. That is both
	 * misleading and self-defeating: the natural reaction is to try again or register a
	 * second time, which leaves an officer tidying up duplicates. Saying what happens next
	 * before they try is more useful than explaining it after they fail, and unlike a notice
	 * raised during sign-in it survives the redirect.
	 */
	private static function signInExpectation(): string
	{
		if (!Gate::isEnabled() || !self::isSignInPage()) {
			return '';
		}

		$subject = strtolower(OrgConfig::getCredentialLabel());

		return '<div class="orgsuite-signin-note">'
			. '<strong>Waiting to hear back?</strong> '
			. 'A new account starts working once an officer of ' . Text::h(OrgConfig::getOrgName())
			. ' has checked the ' . Text::h($subject) . ' you gave at sign-up. '
			. 'If you have already applied, your password is fine and there is nothing more to do; '
			. 'if it has been a while, contact the board.'
			. '</div>';
	}

	private static function isSignInPage(): bool
	{
		$queryString = trim((string) DI::args()->getQueryString(), '/');

		return $queryString === 'login' || str_starts_with($queryString, 'login?');
	}

	private static function isRegisterPage(): bool
	{
		$queryString = trim((string) DI::args()->getQueryString(), '/');

		return $queryString === 'register' || str_starts_with($queryString, 'register?');
	}

	/**
	 * Gated registration, step 1: capture and validate the credential before any account exists.
	 */
	public static function registerPost(array &$data): void
	{
		Gate::onRegisterPost($data);
	}

	/**
	 * Gated registration, step 2: bind the credential to the account Friendica just created.
	 */
	public static function accountRegister(int &$uid): void
	{
		Gate::onAccountRegister($uid);
	}

	/**
	 * The `authenticate` hook carries ['username', 'password', 'authenticated', 'user_record'].
	 */
	public static function authenticate(array &$data): void
	{
		Gate::onAuthenticate($data);
	}

	/**
	 * Adds a tab in core's own moderation area, so an administrator who goes looking there
	 * finds the organization review queue rather than approving an unverified account blindly.
	 */
	public static function moderationUsersTabs(array &$data): void
	{
		if (!Gate::isEnabled()) {
			return;
		}

		$tabs = $data['tabs'] ?? [];

		if (!is_array($tabs)) {
			return;
		}

		$pending = VerificationRepository::pendingCount();

		$tabs[] = [
			'label'     => 'Organization verification' . ($pending > 0 ? ' (' . $pending . ')' : ''),
			'url'       => 'orgsuite/console?tab=applications',
			'sel'       => '',
			'title'     => 'Check the submitted unit number or membership ID before approving an account',
			'id'        => 'orgsuite-verification',
			'accesskey' => 'o',
		];

		$data['tabs'] = $tabs;
	}

	/**
	 * Adds this addon's tables to Friendica's schema.
	 *
	 * `bin/console dbstructure update` loads the definition with addon structures enabled, so
	 * our tables are created and migrated by the same code path as core tables.
	 */
	public static function dbStructureDefinition(array &$definition): void
	{
		foreach (OrgSchema::definition() as $table => $structure) {
			if (!array_key_exists($table, $definition)) {
				$definition[$table] = $structure;
			}
		}
	}

	/**
	 * Makes the organization overview the landing page.
	 *
	 * Only the bare site root is redirected, and only for signed-in members, so stream
	 * filters, deep links and the public pages are untouched. The target itself has a
	 * non-empty path, so this cannot loop.
	 */
	public static function init(&$data): void
	{
		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_DASHBOARD) || OrgConfig::isStreamFirst()) {
			return;
		}

		if (!(bool) OrgConfig::get('dashboard_as_home', true)) {
			return;
		}

		if ((int) DI::userSession()->getLocalUserId() <= 0) {
			return;
		}

		if (trim((string) DI::args()->getQueryString(), '/') !== '') {
			return;
		}

		if (DI::args()->getMethod() !== 'GET') {
			return;
		}

		DI::baseUrl()->redirect('orgsuite');
	}
}
