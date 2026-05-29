/**
 * Wishlist client controller.
 *
 * - Toggles `.is-active` on `.pag-card__wishlist` and persists to the server.
 * - Maintains a localStorage mirror so guest wishlists survive WC session
 *   expiry within the same browser. On every page load we POST the local IDs
 *   to /wishlist/sync to upsert them back into the WC session.
 *
 * @package ProductArchiveGrid
 */
(function ($) {
	'use strict';

	if (!window.PAG || !window.PAG.api) return;

	var api  = window.PAG.api;
	var data = window.PAG_DATA || {};
	var i18n = data.i18n || {};

	var STORAGE_KEY = 'pag_wishlist_ids';

	function readLocal() {
		try {
			var raw = localStorage.getItem(STORAGE_KEY);
			var arr = raw ? JSON.parse(raw) : [];
			return Array.isArray(arr) ? arr.map(function (n) { return parseInt(n, 10); }).filter(Boolean) : [];
		} catch (e) {
			return [];
		}
	}

	function writeLocal(ids) {
		try {
			localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
		} catch (e) { /* private mode */ }
	}

	function applyServerIds(ids) {
		if (!Array.isArray(ids)) return;
		writeLocal(ids);
		// Mark all rendered cards on the page.
		$('.pag-card__wishlist').each(function () {
			var $btn = $(this);
			var pid  = parseInt($btn.attr('data-product-id'), 10);
			var on   = ids.indexOf(pid) !== -1;
			$btn.toggleClass('is-active', on)
				.attr('aria-pressed', on ? 'true' : 'false')
				.attr('aria-label', on ? (i18n.in_wishlist || 'In wishlist') : (i18n.add_wishlist || 'Add to wishlist'));
		});
	}

	// On page load: sync local IDs back up to server (guest case) and pull
	// the server's current truth back down to refresh the buttons.
	$(function () {
		var localIds = readLocal();
		if (localIds.length && !data.is_user) {
			api.request('wishlist/sync', { method: 'POST', body: { ids: localIds } })
				.then(function (resp) { applyServerIds(resp.ids || []); })
				.catch(function () { /* swallow */ });
		} else {
			api.request('wishlist', { method: 'GET' })
				.then(function (resp) { applyServerIds(resp.ids || []); })
				.catch(function () { /* swallow */ });
		}
	});

	// Toggle handler.
	$(document).on('click', '.pag-card__wishlist', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var pid  = parseInt($btn.attr('data-product-id'), 10);
		if (!pid) return;

		// Optimistic UI: flip immediately, rollback on error.
		var wasActive = $btn.hasClass('is-active');
		$btn.toggleClass('is-active', !wasActive)
			.attr('aria-pressed', !wasActive ? 'true' : 'false');

		api.request('wishlist/toggle', {
			method: 'POST',
			body: { product_id: pid }
		}).then(function (resp) {
			$btn.toggleClass('is-active', !!resp.in_list)
				.attr('aria-pressed', resp.in_list ? 'true' : 'false')
				.attr('aria-label', resp.in_list ? (i18n.in_wishlist || 'In wishlist') : (i18n.add_wishlist || 'Add to wishlist'));

			// Update localStorage mirror.
			var ids = readLocal();
			var idx = ids.indexOf(pid);
			if (resp.in_list && idx === -1) ids.push(pid);
			if (!resp.in_list && idx !== -1) ids.splice(idx, 1);
			writeLocal(ids);

			window.PAG.bus.emit('wishlist:changed', { product_id: pid, in_list: resp.in_list });
		}).catch(function (err) {
			// Roll back optimistic state.
			$btn.toggleClass('is-active', wasActive)
				.attr('aria-pressed', wasActive ? 'true' : 'false');
			window.PAG.toast(err.message || i18n.error || 'Error', 'error');
		});
	});

	// Re-apply state to newly appended cards (Load More).
	if (window.PAG.bus) {
		window.PAG.bus.on('grid:loaded-more', function () {
			var ids = readLocal();
			applyServerIds(ids);
		});
	}

}(jQuery));
