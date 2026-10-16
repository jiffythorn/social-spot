<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The site's identity: the icon members see in a browser tab, on their phone's home
 * screen, and under the "install" button.
 *
 * Friendica already knows how to publish an icon — `system.touch_icon` feeds both the
 * `apple-touch-icon` link and the icons in `/manifest`, and `system.shortcut_icon` feeds
 * the tab icon. This class owns those two values instead of inventing a parallel
 * mechanism, which means the addon and core can never disagree about what the site's
 * icon is.
 *
 * One thing core cannot express: `purpose: "maskable"`. Its manifest module builds the
 * icon list from `system.touch_icon` alone and its own @TODO says an administrator has no
 * way to supply the rest - so Android's maskable variant is published through this
 * addon's own manifest (@see self::MANIFEST_URL), which the page's manifest link is
 * pointed at, while core keeps owning the `apple-touch-icon` and tab entries.
 *
 * Two sources can supply the artwork:
 *
 *  - an officer uploads a logo from Organization settings; its bytes are kept by
 *    Friendica's storage backend, never written into the document tree;
 *  - otherwise the generated set in theme/orgtool/images/ is served, which is what a
 *    freshly provisioned instance looks like before anybody configures anything.
 *
 * Either way the route is `/orgsuite/icon`, so an upload takes effect everywhere
 * immediately — there is no file to replace and no browser cache keyed to a filename
 * that would otherwise have to change.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\DI;
use Friendica\Util\Images;

final class Branding
{
	/** Config keys this class owns, inside the `orgsuite` category. */
	public const CONFIG_ICON_REF  = 'icon_ref';
	public const CONFIG_ICON_MIME = 'icon_mime';

	/**
	 * Relative URL for `system.touch_icon`: drives the manifest and the iOS home-screen
	 * icon. No size parameter, so the manifest's icon entries point at one URL that
	 * serves the full-size artwork — see self::serve().
	 */
	public const ICON_URL = 'orgsuite/icon';

	/** Relative URL for `system.shortcut_icon` (the browser tab). */
	public const FAVICON_URL = 'orgsuite/favicon';

	/**
	 * Relative URL for the maskable (Android adaptive) variant of the icon.
	 *
	 * A separate asset rather than the same file: an `any` icon is designed to fill
	 * its square, and Android crops a `maskable` one to the launcher's shape, so one
	 * picture cannot serve both purposes without either overflowing the crop or
	 * sitting uselessly small in the browser tab.
	 */
	public const MASKABLE_URL = 'orgsuite/icon-maskable';

	/**
	 * Relative URL for this addon's manifest.
	 *
	 * Core's manifest module builds its icon list from `system.touch_icon` and offers
	 * no way to add a `purpose: "maskable"` entry - its own `@TODO` says as much - so
	 * the manifest Android actually reads is served here instead.
	 */
	public const MANIFEST_URL = 'orgsuite/manifest';

	/**
	 * Background of the maskable variant, in RGB.
	 *
	 * The launcher masks this icon to a circle, squircle or teardrop, so every pixel
	 * outside the artwork is this colour by necessity: a transparent corner would show
	 * up as whatever the phone paints behind its icons. Mirrors `TILE` in
	 * tools/dev/make-icons.py, which paints the generated set with the same colour -
	 * the two are asserted against each other by tools/dev/e2e-orgsuite.py.
	 */
	public const MASKABLE_BACKGROUND = [15, 118, 90];

	/**
	 * The safe zone: a centred circle whose diameter is 80% of the icon's width.
	 * Anything outside it may be cropped by the launcher's mask.
	 *
	 * @see https://web.dev/articles/maskable-icon
	 */
	public const MASKABLE_SAFE_ZONE = 0.80;

	/** How much of the safe zone the artwork fills; the remainder is deliberate margin. */
	public const MASKABLE_SAFE_FIT = 0.90;

	/** Requested sizes are clamped to these; anything else falls back to the default. */
	public const SIZES = [32, 48, 180, 192, 512];

	/** Default when a caller asks for no particular size. */
	public const DEFAULT_SIZE = 512;

	/** Largest upload an officer may hand us, in bytes. */
	public const MAX_UPLOAD_BYTES = 4 * 1024 * 1024;

	/**
	 * The size to serve for a requested one.
	 *
	 * Unknown values collapse to the default rather than erroring: this URL is written
	 * into core's configuration, so a hand-edited value must not be able to 500 every
	 * page load.
	 */
	public static function normalizeSize(int $size): int
	{
		return in_array($size, self::SIZES, true) ? $size : self::DEFAULT_SIZE;
	}

	/**
	 * Whether an officer has uploaded their own artwork.
	 */
	public static function hasCustomIcon(): bool
	{
		return trim((string) OrgConfig::get(self::CONFIG_ICON_REF, '')) !== '';
	}

	/**
	 * @return array{data: string, mime: string}|null null when nothing can be produced
	 */
	public static function serve(int $size): ?array
	{
		$size = self::normalizeSize($size);

		$reference = trim((string) OrgConfig::get(self::CONFIG_ICON_REF, ''));

		if ($reference !== '') {
			$original = VaultRepository::retrieve($reference);

			if ($original !== null && $original !== '') {
				return [
					'data' => self::fitToSize($original, $size),
					'mime' => 'image/png',
				];
			}

			// The stored bytes are gone (storage reset, a reference lost on migration).
			// Falling through to the generated set keeps the site recognizable instead of
			// serving a broken image on every page load.
			DI::logger()->notice('OrgSuite: stored icon unreadable, serving the generated set', [
				'reference' => $reference,
			]);
		}

		$generated = self::generatedIcon($size);

		return $generated !== null ? ['data' => $generated, 'mime' => 'image/png'] : null;
	}

	/**
	 * The generated artwork for a size, read straight from the theme directory.
	 *
	 * These are committed files produced by tools/dev/make-icons.py, served as ordinary
	 * static files — this path only exists so the dynamic route can fall back to them.
	 */
	private static function generatedIcon(int $size): ?string
	{
		$size = self::normalizeSize($size);
		$path  = DI::basePath() . '/view/theme/orgtool/images/icon-' . $size . '.png';

		if (!is_file($path)) {
			return null;
		}

		$data = @file_get_contents($path);

		return $data !== false && $data !== '' ? $data : null;
	}

	/**
	 * Produces a square PNG of exactly `$size`, preserving the artwork's proportions.
	 *
	 * Padding onto a square rather than stretching matters twice over: a non-square
	 * bitmap is discarded by browsers when they build the home-screen icon, and a
	 * stretched logo is the one thing an officer will notice immediately.
	 *
	 * When GD is unavailable the original bytes are returned untouched — an unrefined
	 * icon still beats no icon.
	 */
	private static function fitToSize(string $data, int $size): string
	{
		if (!extension_loaded('gd')) {
			return $data;
		}

		$source = @imagecreatefromstring($data);

		if ($source === false) {
			return $data;
		}

		$width  = imagesx($source);
		$height = imagesy($source);

		if ($width < 1 || $height < 1) {
			imagedestroy($source);

			return $data;
		}

		$canvas = imagecreatetruecolor($size, $size);

		if ($canvas === false) {
			imagedestroy($source);

			return $data;
		}

		// Transparent padding, so a logo with its own background keeps it and one
		// without does not get an accidental white box behind it.
		imagesavealpha($canvas, true);
		imagealphablending($canvas, false);
		$transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
		imagefill($canvas, 0, 0, $transparent);

		$scale  = min($size / $width, $size / $height);
		$newW   = max(1, (int) round($width * $scale));
		$newH   = max(1, (int) round($height * $scale));
		$scaled = imagecreatetruecolor($newW, $newH);

		if ($scaled !== false) {
			imagesavealpha($scaled, true);
			imagealphablending($scaled, false);
			imagecopyresampled($scaled, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);
			imagecopy($canvas, $scaled, (int) (($size - $newW) / 2), (int) (($size - $newH) / 2), 0, 0, $newW, $newH);
			imagedestroy($scaled);
		}

		imagedestroy($source);

		ob_start();
		imagepng($canvas, null, 9);
		$png = (string) ob_get_clean();
		imagedestroy($canvas);

		return $png !== '' ? $png : $data;
	}

	/**
	 * The maskable artwork for a size: full-bleed background, logo inside the safe zone.
	 *
	 * This is the variant Android asks for by name (`purpose: "maskable"`), and the
	 * rules are stricter than for the ordinary icon: the background must run to every
	 * edge and the logo must fit inside the central safe-zone circle, because the
	 * phone decides the crop shape after the bytes are downloaded.
	 *
	 * @return array{data: string, mime: string}|null null when nothing can be produced
	 */
	public static function serveMaskable(int $size): ?array
	{
		$size = self::normalizeSize($size);

		$reference = trim((string) OrgConfig::get(self::CONFIG_ICON_REF, ''));

		if ($reference !== '') {
			$original = VaultRepository::retrieve($reference);

			if ($original !== null && $original !== '') {
				return [
					'data' => self::maskableFrom($original, $size),
					'mime' => 'image/png',
				];
			}

			DI::logger()->notice('OrgSuite: stored icon unreadable, serving the generated maskable set', [
				'reference' => $reference,
			]);
		}

		$generated = self::generatedMaskable($size);

		return $generated !== null ? ['data' => $generated, 'mime' => 'image/png'] : null;
	}

	/**
	 * The generated maskable file for a size, read from the theme directory.
	 */
	private static function generatedMaskable(int $size): ?string
	{
		$size = self::normalizeSize($size);
		$path  = DI::basePath() . '/view/theme/orgtool/images/icon-maskable-' . $size . '.png';

		if (!is_file($path)) {
			return null;
		}

		$data = @file_get_contents($path);

		return $data !== false && $data !== '' ? $data : null;
	}

	/**
	 * Composites uploaded artwork onto the brand background, inside the safe zone.
	 *
	 * The uploaded logo is fitted by its bounding *circle*, not its box: the mask is
	 * round, so corner to corner is the measurement that has to fit inside the safe
	 * zone. Scaling by the same fraction of that zone as the generated set keeps an
	 * officer's logo the same apparent size as the built-in mark.
	 *
	 * When GD is unavailable there is no compositing at all, and the padded ordinary
	 * icon is served instead - a logo with transparent corners still beats no logo,
	 * and Friendica's installer requires GD, so this is a belt-and-braces path.
	 */
	private static function maskableFrom(string $data, int $size): string
	{
		if (!extension_loaded('gd')) {
			return self::fitToSize($data, $size);
		}

		$source = @imagecreatefromstring($data);

		if ($source === false) {
			return self::fitToSize($data, $size);
		}

		$width  = imagesx($source);
		$height = imagesy($source);

		if ($width < 1 || $height < 1) {
			imagedestroy($source);

			return self::fitToSize($data, $size);
		}

		$canvas = imagecreatetruecolor($size, $size);

		if ($canvas === false) {
			imagedestroy($source);

			return self::fitToSize($data, $size);
		}

		// Opaque to every edge: this is what makes the asset maskable at all.
		$background = imagecolorallocate(
			$canvas,
			self::MASKABLE_BACKGROUND[0],
			self::MASKABLE_BACKGROUND[1],
			self::MASKABLE_BACKGROUND[2]
		);
		imagefill($canvas, 0, 0, $background);
		imagesavealpha($canvas, false);

		$diagonal = sqrt(($width * $width) + ($height * $height));
		$allowed  = (self::MASKABLE_SAFE_ZONE * $size / 2) * self::MASKABLE_SAFE_FIT;
		$scale    = $diagonal > 0 ? (2 * $allowed) / $diagonal : 1.0;

		$newW   = max(1, (int) round($width * $scale));
		$newH   = max(1, (int) round($height * $scale));
		$scaled = imagecreatetruecolor($newW, $newH);

		if ($scaled !== false) {
			imagesavealpha($scaled, true);
			imagealphablending($scaled, false);
			imagecopyresampled($scaled, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);
			// Blended rather than pasted, so a logo that arrives with transparency keeps
			// it over the brand colour instead of developing a black box.
			imagealphablending($canvas, true);
			imagecopy($canvas, $scaled, (int) (($size - $newW) / 2), (int) (($size - $newH) / 2), 0, 0, $newW, $newH);
			imagedestroy($scaled);
		}

		imagedestroy($source);

		ob_start();
		imagepng($canvas, null, 9);
		$png = (string) ob_get_clean();
		imagedestroy($canvas);

		return $png !== '' ? $png : self::fitToSize($data, $size);
	}

	/**
	 * Accepts an upload only when it really is a supported image.
	 *
	 * @param string $data raw bytes as posted
	 *
	 * @return string|null the bytes to store, or null with the reason in $error
	 */
	public static function validateUpload(string $data, ?string &$error = null): ?string
	{
		$error = null;

		if ($data === '') {
			$error = 'No file was received.';

			return null;
		}

		if (strlen($data) > self::MAX_UPLOAD_BYTES) {
			$error = 'That file is too large. Keep the logo under 4 MB.';

			return null;
		}

		// Trust what the bytes are, never the extension or the browser's claim: a file
		// named .png can be anything, and this image ends up on every page of the site.
		$mime = Images::getMimeTypeByData($data);

		if ($mime === '' || !Images::isSupportedMimeType($mime)) {
			$error = 'That file is not a supported image. Upload a PNG or a JPEG.';

			return null;
		}

		// Prove GD can actually decode it. A truncated or malformed image would otherwise
		// be stored, then fail later on every single page render.
		if (extension_loaded('gd')) {
			$image = @imagecreatefromstring($data);

			if ($image === false) {
				$error = 'That image could not be read. It may be damaged — try another file.';

				return null;
			}

			imagedestroy($image);
		}

		return $data;
	}

	/**
	 * Stores uploaded artwork and points core's icon configuration at it.
	 *
	 * @return bool whether it was stored
	 */
	public static function saveUpload(string $data, int $actorUid, ?string &$error = null): bool
	{
		$data = self::validateUpload($data, $error);

		if ($data === null) {
			return false;
		}

		$reference = VaultRepository::store($data);

		if ($reference === '') {
			$error = 'The image could not be stored. Check that storage is configured.';

			return false;
		}

		$previous = trim((string) OrgConfig::get(self::CONFIG_ICON_REF, ''));

		OrgConfig::set(self::CONFIG_ICON_REF, $reference);
		OrgConfig::set(self::CONFIG_ICON_MIME, 'image/png');

		if ($previous !== '') {
			VaultRepository::remove($previous);
		}

		self::configureCoreIcons();

		AuditLog::record('settings.icon.upload', OrgConfig::CATEGORY, 0, ['bytes' => strlen($data)], $actorUid);

		return true;
	}

	/**
	 * Returns the site to the generated icon set.
	 */
	public static function remove(int $actorUid): void
	{
		$reference = trim((string) OrgConfig::get(self::CONFIG_ICON_REF, ''));

		if ($reference !== '') {
			VaultRepository::remove($reference);
		}

		OrgConfig::delete(self::CONFIG_ICON_REF);
		OrgConfig::delete(self::CONFIG_ICON_MIME);

		self::configureCoreIcons();

		AuditLog::record('settings.icon.remove', OrgConfig::CATEGORY, 0, null, $actorUid);
	}

	/**
	 * Points core's icon configuration at this addon's route.
	 *
	 * Core reads these two values when it renders the tab icon, the iOS home-screen icon
	 * and the manifest's icons, so setting them here is what makes *this* site's mark
	 * appear everywhere rather than Friendica's own logo. It is idempotent and only ever
	 * writes these two keys, so a site administrator who has set something else is left
	 * alone until an officer actually uploads or removes artwork.
	 */
	public static function configureCoreIcons(): void
	{
		$icon = self::ICON_URL;
		$favicon = self::FAVICON_URL;

		if (DI::config()->get('system', 'touch_icon') !== $icon) {
			DI::config()->set('system', 'touch_icon', $icon);
		}

		if (DI::config()->get('system', 'shortcut_icon') !== $favicon) {
			DI::config()->set('system', 'shortcut_icon', $favicon);
		}
	}

	/**
	 * Whether the site's icon is currently wired up.
	 *
	 * Used by the console so an officer can see the answer rather than guess.
	 */
	public static function coreIconsConfigured(): bool
	{
		return DI::config()->get('system', 'touch_icon') === self::ICON_URL;
	}
}
