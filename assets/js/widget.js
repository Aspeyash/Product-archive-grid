/**
 * Product Archive Grid — frontend controller.
 *
 * Responsibilities:
 *   - AJAX add-to-cart for simple products (variable products redirect)
 *   - Load More pagination via REST
 *   - Mini-cart fragment sync after add
 *   - Provides a tiny event bus + helpers consumed by sibling modules
 *     (modal.js, buy-now.js, wishlist.js).
 *
 * Public API (window.PAG):
 *   PAG.api.request(endpoint, opts) -> Promise<json>
 *   PAG.bus.on(name, cb) / PAG.bus.emit(name, data)
 *   PAG.toast(message, level)
 *
 * @package ProductArchiveGrid
 */
(function ($) {
	'use strict';

	if (typeof window.PAG_DATA === 'undefined') {
		return;
	}

	var data = window.PAG_DATA;

	// ---------------------------------------------------------------------
	// Tiny event bus
	// ---------------------------------------------------------------------
	var bus = (function () {
		var handlers = {};
		return {
			on: function (name, cb) {
				(handlers[name] = handlers[name] || []).push(cb);
			},
			emit: function (name, payload) {
				(handlers[name] || []).forEach(function (cb) {
					try { cb(payload); } catch (e) { /* swallow */ }
				});
			}
		};
	}());

	// ---------------------------------------------------------------------
	// Toast helper (no styles assumed; just a light notification element)
	// ---------------------------------------------------------------------
	function toast(message, level) {
		level = level || 'info';
		var $t = $('<div class="pag-toast pag-toast--' + level + '" role="status">').text(message);
		$('body').append($t);
		setTimeout(function () { $t.addClass('is-visible'); }, 10);
		setTimeout(function () {
			$t.removeClass('is-visible');
			setTimeout(function () { $t.remove(); }, 300);
		}, 2800);
	}

	// ---------------------------------------------------------------------
	// REST helper. All requests are nonced + JSON.
	// ---------------------------------------------------------------------
	function request(endpoint, opts) {
		opts = opts || {};
		var method = (opts.method || 'POST').toUpperCase();
		var url    = data.rest_url.replace(/\/?$/, '/') + endpoint.replace(/^\//, '');

		var fetchOpts = {
			method: method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': data.nonce,
				'Accept': 'application/json'
			}
		};

		if (method === 'GET' && opts.body) {
			var qs = Object.keys(opts.body).map(function (k) {
				return encodeURIComponent(k) + '=' + encodeURIComponent(opts.body[k]);
			}).join('&');
			url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
		} else if (opts.body) {
			fetchOpts.headers['Content-Type'] = 'application/json';
			fetchOpts.body = JSON.stringify(opts.body);
		}

		return fetch(url, fetchOpts).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok) {
					var err = new Error(json && json.message ? json.message : 'Request failed');
					err.code = json && json.code;
					err.status = res.status;
					err.data = json && json.data;
					throw err;
				}
				return json;
			});
		});
	}

	// ---------------------------------------------------------------------
	// Replace WC fragments coming back from the server.
	// ---------------------------------------------------------------------
	function applyFragments(fragments) {
		if (!fragments) return;
		Object.keys(fragments).forEach(function (selector) {
			var $existing = $(selector);
			if ($existing.length) {
				$existing.replaceWith(fragments[selector]);
			}
		});
		$(document.body).trigger('wc_fragments_refreshed');
	}

	// ---------------------------------------------------------------------
	// AJAX add-to-cart for simple products.
	// ---------------------------------------------------------------------
	$(document).on('click', '.pag-card__atc.is-ajax', function (e) {
		e.preventDefault();
		var $btn = $(this);
		if ($btn.hasClass('is-loading') || $btn.hasClass('is-disabled')) {
			return;
		}

		var productId = parseInt($btn.attr('data-product-id'), 10);
		if (!productId) {
			return;
		}

		$btn.addClass('is-loading');

		request('add-to-cart', {
			method: 'POST',
			body: { product_id: productId, quantity: 1 }
		}).then(function (resp) {
			$btn.removeClass('is-loading').addClass('is-added');
			setTimeout(function () { $btn.removeClass('is-added'); }, 1500);

			if (resp.fragments) {
				applyFragments(resp.fragments);
			}

			$(document.body).trigger('added_to_cart', [
				resp.fragments,
				resp.cart_hash,
				$btn
			]);

			bus.emit('cart:added', resp);
		}).catch(function (err) {
			$btn.removeClass('is-loading');

			// Variable redirect signal.
			if (err.code === 'pag_variable_redirect' && err.data && err.data.redirect_url) {
				window.location.href = err.data.redirect_url;
				return;
			}

			toast(err.message || data.i18n.error, 'error');
		});
	});

	// ---------------------------------------------------------------------
	// Variable products: ensure the link goes to the product page even though
	// the underlying <a> would already work — this is just a safety belt for
	// themes that hijack <a> clicks.
	// ---------------------------------------------------------------------
	$(document).on('click', '.pag-card__atc.is-variable', function (e) {
		var url = $(this).attr('data-product-url');
		if (url) {
			e.preventDefault();
			window.location.href = url;
		}
	});

	// ---------------------------------------------------------------------
	// Load More
	// ---------------------------------------------------------------------
	$(document).on('click', '.pag-load-more', function () {
		var $btn  = $(this);
		var $wrap = $btn.closest('.pag-grid-wrapper');
		var $grid = $wrap.find('.pag-grid');
		if (!$grid.length || $btn.hasClass('is-loading') || $btn.hasClass('is-done')) {
			return;
		}

		var settingsAttr = $wrap.attr('data-pag-settings') || '{}';
		var page = parseInt($btn.attr('data-next-page'), 10) || 2;

		$btn.addClass('is-loading').text(data.i18n.loading);

		request('load-more', {
			method: 'GET',
			body: { page: page, settings: settingsAttr }
		}).then(function (resp) {
			$btn.removeClass('is-loading');

			if (resp.html) {
				$grid.append(resp.html);
			}

			if (resp.has_more) {
				$btn.attr('data-next-page', resp.next_page);
				$btn.text($btn.data('original-label') || $btn.text());
			} else {
				$btn.addClass('is-done').text(data.i18n.no_more);
			}

			bus.emit('grid:loaded-more', resp);
		}).catch(function (err) {
			$btn.removeClass('is-loading').text($btn.data('original-label') || data.i18n.load_more);
			toast(err.message || data.i18n.error, 'error');
		});
	});

	// Snapshot original Load More label for restoring after loading state.
	$(function () {
		$('.pag-load-more').each(function () {
			$(this).data('original-label', $.trim($(this).text()));
		});
	});

	// ---------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------
	window.PAG = window.PAG || {};
	window.PAG.api = { request: request, applyFragments: applyFragments };
	window.PAG.bus = bus;
	window.PAG.toast = toast;

}(jQuery));

/* Minimal toast styles injected once. */
(function () {
	if (document.getElementById('pag-toast-styles')) return;
	var s = document.createElement('style');
	s.id = 'pag-toast-styles';
	s.textContent = '.pag-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(20px);padding:10px 18px;background:#111;color:#fff;border-radius:999px;font-size:14px;z-index:99999;opacity:0;pointer-events:none;transition:opacity .25s ease, transform .25s ease;max-width:80vw;text-align:center}.pag-toast.is-visible{opacity:1;transform:translateX(-50%) translateY(0);pointer-events:auto}.pag-toast--error{background:#dc2626}.pag-toast--success{background:#16a34a}';
	document.head.appendChild(s);
}());
