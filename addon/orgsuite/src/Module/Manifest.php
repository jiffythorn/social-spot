<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The web app manifest the page advertises: `/orgsuite/manifest`.
 *
 * Core ships its own `/manifest`, and its icon list is built from `system.touch_icon`
 * alone - its own @TODO concedes there is no mechanism for an administrator to supply
 * the rest of the images a valid manifest wants. The consequence is concrete: the
 * manifest carries no `purpose: "maskable"` entry, Android falls back to the ordinary
 * icon and crops it to whatever shape the phone's launcher uses, and the mark loses its
 * roof.
 *
 * So the manifest is served here instead, mirroring core's fields - name, start URL,
 * display mode, shortcuts, colours - so the two documents agree on everything except the
 * icons, where this one lists both the `any` assets Android and desktop browsers use
 * normally and the separately designed maskable ones. Separate entries rather than a
 * combined `any maskable` purpose: a maskable asset carries padding, which makes it sit
 * uselessly small wherever it is used unmasked.
 *
 * The page's `<link rel="manifest">` is pointed at this module by
 * Hooks::pointManifestAtOurs(); core's own manifest keeps answering for anything that
 * still asks for it.
 *
 * @see https://web.dev/articles/maskable-icon
 * @see \Friendica\Module\Manifest for the core equivalent this mirrors
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite\Module;

use Friendica\Addon\OrgSuite\Branding;
use Friendica\BaseModule;
use Friendica\Core;
use Friendica\DI;

class Manifest extends BaseModule
{
	protected function get(array $request = [])
	{
		$config = DI::config();
		$theme  = $config->get('system', 'theme');
		$base   = (string) DI::baseUrl();

		$manifest = [
			'name'        => $config->get('config', 'sitename', 'Friendica'),
			'start_url'   => $base,
			'display'     => 'standalone',
			'description' => $config->get('config', 'info', DI::l10n()->t('A Decentralized Social Network')),
			'short_name'  => DI::baseUrl()->getHost(),
			'lang'        => $config->get('system', 'language'),
			'dir'         => 'auto',
			'categories'  => ['social network', 'internet'],
			'shortcuts'   => [
				[
					'name' => 'Latest posts',
					'url'  => '/network'
				],
				[
					'name' => 'Messages',
					'url'  => '/message'
				],
				[
					'name' => 'Notifications',
					'url'  => '/notifications/system'
				],
				[
					'name' => 'Contacts',
					'url'  => '/contact'
				],
				[
					'name' => 'Calendar',
					'url'  => '/calendar'
				]
			]
		];

		// Sizes are spelled out per URL rather than declared twice against one file:
		// the route honours `?size=`, so every entry is the dimension it claims to be,
		// which is exactly what an install-time validator measures.
		$manifest['icons'] = [
			[
				'src'     => $base . '/' . Branding::ICON_URL . '?size=192',
				'sizes'   => '192x192',
				'type'    => 'image/png',
				'purpose' => 'any',
			],
			[
				'src'     => $base . '/' . Branding::ICON_URL . '?size=512',
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'any',
			],
			[
				'src'     => $base . '/' . Branding::MASKABLE_URL . '?size=192',
				'sizes'   => '192x192',
				'type'    => 'image/png',
				'purpose' => 'maskable',
			],
			[
				'src'     => $base . '/' . Branding::MASKABLE_URL . '?size=512',
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'maskable',
			],
		];

		if ($background_color = Core\Theme::getBackgroundColor($theme)) {
			$manifest['background_color'] = $background_color;
		}

		if ($theme_color = Core\Theme::getThemeColor($theme)) {
			$manifest['theme_color'] = $theme_color;
		}

		$this->jsonExit($manifest, 'application/manifest+json');
	}
}
