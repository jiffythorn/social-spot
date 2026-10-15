<?php
/**
 * Name: Organization Suite
 * Description: HOA, club, non-profit and community features for one organization per instance: gated registration with officer verification, member records and directory, a structured document vault, a small dues ledger, announcement banners, and a non-technical officer console. Every feature can be switched on or off per instance.
 * Version: 1.0.0
 * Author: Social Spot <https://example.invalid>
 * Maintainer: Social Spot <https://example.invalid>
 * Status: in development
 */

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Bootstrap for the Organization Suite addon.
 *
 * Contract notes, all verified against the Friendica 2026.05 source rather than the wiki:
 *
 *  - an addon lives at addon/<id>/<id>.php and its metadata is parsed from the first comment
 *    block in that file (Friendica\Core\Addon\AddonManagerHelper::getAddonInfo());
 *  - <id>_install() is called once when an admin enables the addon, <id>_uninstall() when it
 *    is disabled, and <id>_addon_admin() renders a settings panel;
 *  - hooks are persisted in the `hook` table by Friendica\Core\Hook::register(), and the
 *    handler receives exactly one argument, by reference;
 *  - addon classes need an autoloader. Friendica's composer.json classmaps the addon/
 *    directory, but that only takes effect after `composer dump-autoload`, so this addon
 *    registers its own PSR-4 loader and stays self-contained across a re-fork.
 */

declare(strict_types=1);

use Friendica\Addon\OrgSuite\Gate;
use Friendica\Addon\OrgSuite\Hooks;
use Friendica\Addon\OrgSuite\OrgConfig;
use Friendica\Addon\OrgSuite\OrgSchema;
use Friendica\Addon\OrgSuite\Text;
use Friendica\Addon\OrgSuite\VaultRepository;
use Friendica\Core\Hook;
use Friendica\Database\DBStructure;
use Friendica\DI;

/*
 * -------------------------------------------------------------------------------- autoload
 */

(static function (): void {
	$prefix = 'Friendica\\Addon\\OrgSuite\\';
	$baseDir = __DIR__ . '/src/';

	spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
		if (!str_starts_with($class, $prefix)) {
			return;
		}

		$relative = str_replace('\\', '/', substr($class, strlen($prefix)));
		$file     = $baseDir . $relative . '.php';

		if (is_file($file)) {
			require_once $file;
		}
	});
})();

/** The file this addon registers its hooks against. */
const ORGSUITE_HOOK_FILE = 'addon/orgsuite/orgsuite.php';

/**
 * Hook name => handler function in this file.
 *
 * Keeping the list in one place means install and uninstall can never drift apart.
 */
const ORGSUITE_HOOKS = [
	'route_collection'       => 'orgsuite_route_collection',
	'nav_info'               => 'orgsuite_nav_info',
	'head'                   => 'orgsuite_head',
	'page_content_top'       => 'orgsuite_page_content_top',
	'register_post'          => 'orgsuite_register_post',
	'register_account'       => 'orgsuite_register_account',
	'authenticate'           => 'orgsuite_authenticate',
	'moderation_users_tabs'  => 'orgsuite_moderation_users_tabs',
	'dbstructure_definition' => 'orgsuite_dbstructure_definition',
	'init_1'                 => 'orgsuite_init_1',
];

/*
 * --------------------------------------------------------------------------------- install
 */

/**
 * Called once when an administrator enables the addon.
 */
function orgsuite_install(): void
{
	foreach (ORGSUITE_HOOKS as $hook => $handler) {
		Hook::register($hook, ORGSUITE_HOOK_FILE, $handler);
	}

	// Seed the organization name from the node name so the console is not empty on first use.
	if (trim((string) OrgConfig::get('org_name', '')) === '') {
		OrgConfig::set('org_name', (string) DI::config()->get('config', 'sitename', ''));
	}

	// Create this addon's tables straight away rather than making the admin run an extra
	// command. This is the same code path as `bin/console dbstructure update`.
	//
	// Hook::register() persists the hook but, as its docblock states, deliberately does not add
	// it to the running process. Without the Hook::add() below, the schema update triggered
	// here would not see this addon's tables at all and would silently create nothing. Adding
	// it in memory makes the install self-contained; the persisted row still covers every
	// later request.
	Hook::add('dbstructure_definition', ORGSUITE_HOOK_FILE, ORGSUITE_HOOKS['dbstructure_definition']);

	try {
		DBStructure::performUpdate(false, false);
		OrgSchema::resetReadyCache();

		// Creates the built-in positions so the console has something to assign immediately.
		$result = OrgSchema::migrate();
		DI::logger()->info('OrgSuite: seeded positions', $result);
	} catch (\Throwable $e) {
		DI::logger()->error('OrgSuite: the schema update failed during install', ['exception' => $e]);
	}

	// Gated registration is only meaningful if new accounts are held until an officer
	// releases them, so ask for the matching core policy.
	if (Gate::isEnabled()) {
		orgsuite_enforce_approval_policy();
	}

	DI::logger()->info('OrgSuite: installed (profile: ' . OrgConfig::getProfile() . ')');
}

/**
 * Called when an administrator disables the addon.
 *
 * Organization data is deliberately kept. Disabling an addon is not a request to delete a
 * board's membership records and minutes, so the tables and their contents stay put and are
 * reused if the addon is enabled again. Removing the data is a separate, explicit action.
 */
function orgsuite_uninstall(): void
{
	foreach (ORGSUITE_HOOKS as $hook => $handler) {
		Hook::unregister($hook, ORGSUITE_HOOK_FILE, $handler);
	}

	DI::logger()->info('OrgSuite: uninstalled; organization data has been kept');
}

/**
 * Sets the core registration policy to "approval required" when gated registration is on.
 */
function orgsuite_enforce_approval_policy(): void
{
	if (\Friendica\Module\Register::getPolicy() === \Friendica\Module\Register::APPROVE) {
		return;
	}

	DI::config()->set('config', 'register_policy', \Friendica\Module\Register::APPROVE);

	DI::logger()->info('OrgSuite: registration policy set to approval-required because gated registration is enabled');
}

/*
 * --------------------------------------------------------- administrator settings panel
 */

/**
 * Summary panel on the addon's page in Administration -> Addons.
 *
 * The editable settings live in the officer console, which officers can reach without site
 * administration rights. This panel exists so a site administrator can see at a glance
 * whether the addon is actually in effect.
 *
 * @param string $admin_form appended to by reference
 */
function orgsuite_addon_admin(&$admin_form): void
{
	$schemaReady = OrgSchema::ready();

	$rows = [
		'Organization profile' => OrgConfig::PROFILE_LABELS[OrgConfig::getProfile()] ?? OrgConfig::getProfile(),
		'Organization name'    => OrgConfig::getOrgName() !== '' ? OrgConfig::getOrgName() : '(not set)',
		'Database tables'      => $schemaReady ? 'created' : 'missing — run bin/console dbstructure update',
		'Gated registration'   => Gate::isEnabled() ? 'on' : 'off',
		'Sign-up field source' => Gate::themeRendersField()
			? 'the active theme renders it'
			: 'injected above the form by this addon',
	];

	$enabled = [];
	$off     = [];

	foreach (OrgConfig::getFeatureStates() as $state) {
		$state['enabled'] ? $enabled[] = $state['label'] : $off[] = $state['label'];
	}

	$admin_form .= '<h3>Organization Suite</h3>';

	$admin_form .= '<table class="table">';

	foreach ($rows as $label => $value) {
		$admin_form .= '<tr><th>' . Text::h($label) . '</th><td>' . Text::h((string) $value) . '</td></tr>';
	}

	$admin_form .= '<tr><th>Features on</th><td>' . ($enabled !== [] ? Text::h(implode(', ', $enabled)) : 'none') . '</td></tr>';
	$admin_form .= '<tr><th>Features off</th><td>' . ($off !== [] ? Text::h(implode(', ', $off)) : 'none') . '</td></tr>';
	$admin_form .= '</table>';

	$admin_form .= '<p>Change features and organization details in the '
		. '<a href="orgsuite/console?tab=settings">officer console</a>. '
		. 'Members land on <a href="orgsuite">the organization overview</a>.</p>';

	if (!$schemaReady) {
		$admin_form .= '<p><strong>The addon is enabled but its tables are missing.</strong> '
			. 'Run <code>bin/console dbstructure update</code>, then reload this page. '
			. 'Until then organization features stay switched off rather than throwing errors.</p>';
	}
}

/*
 * ------------------------------------------------------------------------- hook handlers
 *
 * Friendica\Core\Hook::callSingle() invokes the registered function with a single argument
 * passed by reference. These wrappers keep the addon's registration table readable and hand
 * the real work to Friendica\Addon\OrgSuite\Hooks.
 */

function orgsuite_route_collection(&$collector): void
{
	if ($collector instanceof \FastRoute\RouteCollector) {
		Hooks::routeCollection($collector);
	}
}

function orgsuite_nav_info(&$nav_info): void
{
	if (is_array($nav_info)) {
		Hooks::navInfo($nav_info);
	}
}

function orgsuite_head(&$html): void
{
	if (is_string($html)) {
		Hooks::head($html);
	}
}

function orgsuite_page_content_top(&$html): void
{
	if (is_string($html)) {
		Hooks::pageContentTop($html);
	}
}

function orgsuite_register_post(&$data): void
{
	if (is_array($data)) {
		Hooks::registerPost($data);
	}
}

function orgsuite_authenticate(&$data): void
{
	// The `authenticate` hook passes an array: username, password, authenticated, user_record.
	if (is_array($data)) {
		Hooks::authenticate($data);
	}
}

function orgsuite_register_account(&$uid): void
{
	// The `register_account` hook passes the new user id as a scalar (see the bridge in
	// HookEventBridge::onAccountRegisterEvent), NOT an array. Guarding on is_array() here
	// silently discarded every registration, leaving the credential unlinked.
	Hooks::accountRegister($uid);
}

function orgsuite_moderation_users_tabs(&$data): void
{
	if (is_array($data)) {
		Hooks::moderationUsersTabs($data);
	}
}

function orgsuite_dbstructure_definition(&$definition): void
{
	if (is_array($definition)) {
		Hooks::dbStructureDefinition($definition);
	}
}

function orgsuite_init_1(&$data): void
{
	Hooks::init($data);
}

/*
 * ----------------------------------------------------------------------------- utilities
 */

/**
 * Seeds the standard folder set for the current profile, so a new instance has a usable
 * document library before anybody configures it. Safe to call more than once: folders whose
 * slug already exists are left alone.
 *
 * @return int number of folders created
 */
function orgsuite_seed_folders(int $createdBy = 0): int
{
	if (!OrgSchema::ready()) {
		return 0;
	}

	$profile = OrgConfig::getProfile();

	$sets = [
		OrgConfig::PROFILE_HOA => [
			['Governing documents', 'Bylaws, CC&Rs, articles of incorporation and amendments.', VaultRepository::VIS_MEMBERS],
			['Board minutes', 'Approved minutes of board and annual meetings.', VaultRepository::VIS_BOARD],
			['Financials', 'Budgets, financial statements and the annual audit.', VaultRepository::VIS_BOARD],
			['Architectural requests', 'Forms and guidance for exterior changes.', VaultRepository::VIS_MEMBERS],
			['Forms and policies', 'Rules, policies, and forms residents need.', VaultRepository::VIS_MEMBERS],
		],
		OrgConfig::PROFILE_CLUB => [
			['Constitution and bylaws', 'Governing documents for the club.', VaultRepository::VIS_MEMBERS],
			['Committee minutes', 'Minutes of committee and board meetings.', VaultRepository::VIS_BOARD],
			['Treasurer reports', 'Budgets, statements and reports.', VaultRepository::VIS_BOARD],
			['Forms and policies', 'Membership forms, codes of conduct and policies.', VaultRepository::VIS_MEMBERS],
		],
		OrgConfig::PROFILE_NONPROFIT => [
			['Governing documents', 'Articles, bylaws and charitable registration.', VaultRepository::VIS_MEMBERS],
			['Board minutes', 'Board and annual general meeting minutes.', VaultRepository::VIS_BOARD],
			['Financial statements', 'Budget, statements and filings.', VaultRepository::VIS_BOARD],
			['Public accountability', 'Documents published for the public and for funders.', VaultRepository::VIS_PUBLIC],
		],
		OrgConfig::PROFILE_COMMUNITY => [
			['Community documents', 'Guides, plans and reference material.', VaultRepository::VIS_MEMBERS],
			['Meeting notes', 'Notes from community meetings.', VaultRepository::VIS_MEMBERS],
		],
	];

	$order   = 0;
	$created = 0;

	foreach ($sets[$profile] ?? $sets[OrgConfig::PROFILE_COMMUNITY] as $folder) {
		[$name, $description, $visibility] = $folder;

		if (VaultRepository::createFolder($name, $description, $visibility, $order++ * 10, $createdBy) > 0) {
			$created++;
		}
	}

	return $created;
}
