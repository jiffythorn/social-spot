<?php
/**
 * Copyright (C) 2026, Social Spot
 * SPDX-FileCopyrightText: 2026, Social Spot
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Name: orgtool
 * Description: Organization skin for HOAs, clubs, non-profits and communities. Inherits Frio and replaces the federated-network framing with plain-language navigation for non-technical members.
 * Version: 1.0.0
 * Author: Social Spot <https://example.invalid>
 * Maintainer: Social Spot <https://example.invalid>
 */

use Friendica\AppHelper;
use Friendica\Core\Renderer;
use Friendica\DI;

/**
 * Theme initialization.
 *
 * Called on every page load for as long as this theme is the active one. Two things happen
 * here that matter:
 *
 *  1. `extends` is declared so Frio's templates and Smarty include paths stay available. This
 *     is the supported parent-theme mechanism (a metadata relationship), not PHP inheritance.
 *  2. The stylesheet order is pinned. Frio's stylesheet is registered first, then this addon's
 *     component stylesheet, then this theme's skin. Because Renderer::$theme['stylesheet'] is
 *     what Page::initHead() registers, pointing it at Frio's stylesheet keeps Frio's
 *     configurable colours while letting the skin layer win.
 */
function orgtool_init(AppHelper $appHelper): void
{
	$appHelper->setThemeInfoValue('extends', 'frio');

	Renderer::setActiveTemplateEngine('smarty3');

	// Frio remains the visual base, including the colour scheme the site owner configured.
	Renderer::$theme['stylesheet'] = 'view/theme/frio/style.php';

	// Component layer from the Organization Suite addon. Registering the same path that the
	// addon's own `head` hook registers is intentional: the stylesheet list is keyed by path,
	// so the earlier registration keeps its position and there is no duplicate request.
	DI::page()->registerStylesheet('addon/orgsuite/assets/orgsuite.css');

	// Skin layer last, so an organization can restyle the components.
	DI::page()->registerStylesheet('view/theme/orgtool/style.css');
}

/**
 * Called when an administrator selects this theme.
 *
 * No hooks are registered here on purpose. Everything this theme changes about behaviour is
 * already driven by the Organization Suite addon (navigation, announcements, the sign-up
 * field), which keeps the theme purely presentational and therefore trivial to re-fork.
 */
function orgtool_install(): void
{
	DI::logger()->info('installed theme orgtool');
}

/**
 * Removes this theme's registered files when it is removed.
 */
function orgtool_uninstall(): void
{
	DI::logger()->info('uninstalled theme orgtool');
}
