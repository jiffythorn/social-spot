<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Serves the site's icon: `/orgsuite/icon` (512 by default), or
 * `/orgsuite/icon?size=180` for a specific dimension.
 *
 * Core references this URL from `system.touch_icon` and `system.shortcut_icon`, so it
 * has to keep answering even before the addon's tables exist — this module therefore
 * depends on nothing but the Branding class and the theme directory.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite\Module;

use Friendica\Addon\OrgSuite\Branding;
use Friendica\Addon\OrgSuite\Text;
use Friendica\BaseModule;
use Friendica\Capabilities\ICanCreateResponses;
use Friendica\DI;

class Icon extends BaseModule
{
	protected function get(array $request = [])
	{
		// Three URLs, one module: `/orgsuite/icon` serves the full-size artwork for the
		// manifest and the iOS home-screen icon, `/orgsuite/favicon` the small tab icon,
		// and `/orgsuite/icon-maskable` the Android adaptive variant. Deriving the size
		// from the route (rather than a query string in core's config) keeps
		// `system.shortcut_icon` a plain path, which is also what makes it editable from
		// the console without anything having to parse an embedded query.
		//
		// The route arrives as the query string, and this module's own `?size=` reads as
		// part of it, so anything after a `?` is the caller asking for a dimension rather
		// than naming a route.
		$route = explode('?', trim((string) DI::args()->getQueryString(), '/'), 2)[0];
		$maskable = $route === Branding::MASKABLE_URL;
		$size  = $route === 'orgsuite/favicon' ? 48 : Branding::DEFAULT_SIZE;

		$requested = Text::postValue($_GET, 'size', '');

		if ($requested !== '' && ctype_digit($requested)) {
			$size = Branding::normalizeSize((int) $requested);
		}

		$icon = $maskable ? Branding::serveMaskable($size) : Branding::serve($size);

		if ($icon === null) {
			$this->httpError(404, 'Icon not found');
		}

		// One day rather than a week: an officer may replace the logo at any moment, and
		// a home-screen icon that stays stale for a week is exactly the kind of small
		// wrongness that makes people email about it.
		$this->response->setHeader('public, max-age=86400', 'Cache-Control');
		$this->response->setHeader('no', 'X-Robots-Tag');

		return $this->httpExit($icon['data'], ICanCreateResponses::TYPE_BLANK, $icon['mime']);
	}
}
