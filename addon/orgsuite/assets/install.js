/*
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The add-to-home-screen control (rendered by Hooks::installWidget()).
 *
 * One button, three behaviours, because no single API reaches every phone:
 *
 *   Chromium  - captures `beforeinstallprompt` so a tap really installs the site with
 *               our icon, instead of opening a menu. The event is not guaranteed and
 *               each one may only be used once, so a spent event is discarded and a
 *               later tap falls back to instructions rather than calling prompt() on
 *               something already consumed.
 *   iOS       - Safari exposes no programmatic prompt at all. WebKit's documented path
 *               is Share -> Add to Home Screen, so the button opens those steps.
 *   anything  - generic steps, so the button is never a dead end.
 *
 * The browser's own mini install bar is suppressed while we have a button, so a member
 * is never asked the same question twice at once.
 *
 * Written for an ES5 floor on purpose: this runs on whatever phone a volunteer happens
 * to own, not on a browser we chose.
 */
(function () {
	'use strict';

	var wrap = document.getElementById('orgsuite-install');

	if (!wrap) {
		return;
	}

	// A member who declined this should not be nagged on every page afterwards.
	// Storage access can throw (private mode, disabled cookies), in which case the
	// control simply behaves as if it had never been dismissed.
	try {
		if (window.localStorage.getItem('orgsuite-install-dismissed') === '1') {
			removeWrap();
			return;
		}
	} catch (ignore) {
		// no storage available; show the control
	}

	var button = document.getElementById('orgsuite-install-button');
	var panel = document.getElementById('orgsuite-install-help');
	var dismiss = document.getElementById('orgsuite-install-dismiss');
	var steps = document.querySelector('[data-install-steps]');
	var deferred = null;

	function removeWrap() {
		if (wrap && wrap.parentNode) {
			wrap.parentNode.removeChild(wrap);
		}
	}

	function test(pattern) {
		return pattern.test(navigator.userAgent || '');
	}

	function isIOS() {
		// iPadOS reports itself as Macintosh, so the touch-point test is what actually
		// distinguishes a tablet Safari from desktop Safari.
		return test(/iPad|iPhone|iPod/) || (test(/Macintosh/) && navigator.maxTouchPoints > 1);
	}

	function isAndroid() {
		return test(/Android/);
	}

	function isStandalone() {
		try {
			if (window.navigator.standalone === true) {
				return true;
			}
		} catch (ignore) {
			// older WebKit without navigator.standalone
		}

		try {
			return window.matchMedia('(display-mode: standalone)').matches;
		} catch (ignore) {
			return false;
		}
	}

	// Already installed: the control has nothing left to offer, and keeping it visible
	// would invite a second install attempt that the browser will just refuse.
	if (isStandalone()) {
		removeWrap();
		return;
	}

	function showSteps() {
		if (!steps) {
			return;
		}

		var items;

		if (isIOS()) {
			items = [
				'Tap the <strong>Share</strong> button (the square with an arrow).',
				'Scroll down and tap <strong>Add to Home Screen</strong>.',
				'Tap <strong>Add</strong> - the icon appears on your home screen.'
			];
		} else if (isAndroid()) {
			items = [
				'Tap the <strong>menu</strong> (three dots, top right).',
				'Tap <strong>Install app</strong> or <strong>Add to Home screen</strong>.',
				'Tap <strong>Install</strong> - the icon appears on your home screen.'
			];
		} else {
			items = [
				'Open your browser&rsquo;s menu.',
				'Look for <strong>Install app</strong> or <strong>Add to Home screen</strong>.',
				'Confirm, and the icon appears on your home screen.'
			];
		}

		var html = '';

		for (var i = 0; i < items.length; i++) {
			html += '<li>' + items[i] + '</li>';
		}

		steps.innerHTML = html;

		if (panel) {
			panel.hidden = false;
		}
	}

	if (button) {
		button.addEventListener('click', function (event) {
			event.preventDefault();

			if (!deferred) {
				// No native prompt available (iOS, or Chromium has not decided this site
				// is installable). Show the steps rather than silently doing nothing.
				showSteps();
				return;
			}

			var promptEvent = deferred;
			deferred = null;

			try {
				promptEvent.prompt();
			} catch (ignore) {
				showSteps();
				return;
			}

			if (promptEvent.userChoice && typeof promptEvent.userChoice.then === 'function') {
				promptEvent.userChoice.then(function (choice) {
					if (!panel) {
						return;
					}

					if (choice && choice.outcome === 'accepted') {
						panel.hidden = true;
					} else {
						// Dismissed the native sheet: the manual steps are still useful.
						showSteps();
					}
				}).then(null, function () {
					// Choice unavailable; the steps remain a safe fallback.
				});
			}
		});
	}

	window.addEventListener('beforeinstallprompt', function (event) {
		// Keeping the browser's own bar from appearing alongside ours; ours is the
		// better-located prompt and it stays usable if this is prevented.
		event.preventDefault();
		deferred = event;
	});

	window.addEventListener('appinstalled', function () {
		deferred = null;
		removeWrap();
	});

	if (dismiss) {
		dismiss.addEventListener('click', function (event) {
			event.preventDefault();

			try {
				window.localStorage.setItem('orgsuite-install-dismissed', '1');
			} catch (ignore) {
				// Storage unavailable: hiding it for this page load is still a courtesy.
			}

			removeWrap();
		});
	}
}());
