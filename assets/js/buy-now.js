/**
 * Buy Now click handler + abandon beacon.
 *
 * Listens for 'buy-now:click' on the PAG bus (emitted by modal.js when the
 * Buy Now button in the Quick View modal is clicked). Posts to
 * /buy-now and redirects to checkout on success.
 *
 * On `pagehide` and `visibilitychange (hidden)` we ping /buy-now/abandon via
 * sendBeacon so the server can refresh the snapshot's TTL.
 *
 * @package ProductArchiveGrid
 */
(function ($) {
	'use strict';

	if (!window.PAG || !window.PAG.api) return;

	var api  = window.PAG.api;
	var bus  = window.PAG.bus;
	var data = window.PAG_DATA || {};
	var i18n = data.i18n || {};

	var hasActiveSnapshot = false;

	bus.on('buy-now:click', function (payload) {
		var $btn = payload.button ? $(payload.button) : null;
		if ($btn) $btn.addClass('is-loading');

		api.request('buy-now', {
			method: 'POST',
			body: {
				product_id:   payload.product_id,
				variation_id: payload.variation_id || 0,
				attributes:   payload.attributes || {},
				quantity:     payload.quantity || 1
			}
		}).then(function (resp) {
			hasActiveSnapshot = true;
			if (resp.checkout_url) {
				window.location.href = resp.checkout_url;
			}
		}).catch(function (err) {
			if ($btn) $btn.removeClass('is-loading');
			window.PAG.toast(err.message || i18n.error || 'Error', 'error');
		});
	});

	// ---------------------------------------------------------------------
	// Abandon beacon — fires when user closes/hides the page mid-checkout.
	// We don't restore/clear cart here (cart already has both originals + buy
	// now product); the request just refreshes the snapshot's TTL on the
	// server. We piggyback on sendBeacon for reliability.
	// ---------------------------------------------------------------------
	function pingAbandon() {
		if (!hasActiveSnapshot) return;
		try {
			var url = data.rest_url.replace(/\/?$/, '/') + 'buy-now/abandon';
			var blob = new Blob(
				[ JSON.stringify({ _wpnonce: data.nonce }) ],
				{ type: 'application/json' }
			);
			if (navigator.sendBeacon) {
				navigator.sendBeacon(url, blob);
			} else {
				// Fallback (best effort).
				api.request('buy-now/abandon', { method: 'POST', body: {} }).catch(function () {});
			}
		} catch (e) { /* swallow */ }
	}

	window.addEventListener('pagehide', pingAbandon);
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') pingAbandon();
	});

	// If we land on the order-received (thankyou) page, the server already
	// restored the cart; mark snapshot as inactive so we don't ping abandon
	// from the thankyou page.
	if (/order-received|thank-you/i.test(window.location.pathname)) {
		hasActiveSnapshot = false;
	}

}(jQuery));
