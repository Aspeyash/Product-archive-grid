/**
 * Quick View modal hydration + variation swatch logic.
 *
 * - Click .pag-card__quick-view → fetch /quick-view → render
 * - Variation swatches detect type from server payload (color | image | label)
 * - Selecting all axes resolves a variation_id → enable Add to Cart and Buy Now
 * - Add to Cart in modal: AJAX add (variation_id-aware) → close modal
 * - Buy Now in modal: emits 'buy-now:click' on the bus (handled by buy-now.js)
 *
 * @package ProductArchiveGrid
 */
(function ($) {
	'use strict';

	if (!window.PAG || !window.PAG.api) return;

	var api = window.PAG.api;
	var bus = window.PAG.bus;
	var i18n = (window.PAG_DATA && window.PAG_DATA.i18n) || {};

	var $modal, $panel, $loading, $content, $error;
	var state = {
		product: null,
		selectedAttrs: {},      // { attribute_pa_color: 'red', ... }
		selectedVariationId: 0,
		quantity: 1,
		lastFocused: null
	};

	function ensureRefs() {
		$modal   = $('#pag-modal');
		if (!$modal.length) return false;
		$panel   = $modal.find('.pag-modal__panel');
		$loading = $modal.find('.pag-modal__loading');
		$content = $modal.find('.pag-modal__content');
		$error   = $modal.find('.pag-modal__error');
		return true;
	}

	// ---------------------------------------------------------------------
	// Open / close
	// ---------------------------------------------------------------------
	function open(productId) {
		if (!ensureRefs()) return;

		state.lastFocused = document.activeElement;
		state.product = null;
		state.selectedAttrs = {};
		state.selectedVariationId = 0;
		state.quantity = 1;

		$modal.attr('aria-hidden', 'false').prop('hidden', false);
		$('body').addClass('pag-modal-open');

		$content.prop('hidden', true);
		$error.prop('hidden', true).text('');
		$loading.show();

		// Focus management
		setTimeout(function () { $panel.focus(); }, 30);

		api.request('quick-view', {
			method: 'GET',
			body: { product_id: productId }
		}).then(function (data) {
			state.product = data;
			renderProduct(data);
			$loading.hide();
			$content.prop('hidden', false);
		}).catch(function (err) {
			$loading.hide();
			$error.prop('hidden', false).text(err.message || i18n.error || 'Error');
		});
	}

	function close() {
		if (!ensureRefs()) return;
		$modal.attr('aria-hidden', 'true').prop('hidden', true);
		$('body').removeClass('pag-modal-open');
		if (state.lastFocused && state.lastFocused.focus) {
			state.lastFocused.focus();
		}
	}

	// ---------------------------------------------------------------------
	// Render
	// ---------------------------------------------------------------------
	function renderProduct(p) {
		$content.find('.pag-modal__title').text(p.name || '');
		$content.find('.pag-modal__price').html(p.price_html || '');
		$content.find('.pag-modal__permalink').attr('href', p.permalink || '#');

		// Description
		$content.find('.pag-modal__desc').html(p.short_description || '');

		// SKU
		var $sku = $content.find('.pag-modal__sku');
		if (p.sku) {
			$sku.prop('hidden', false);
			$sku.find('.pag-modal__sku-value').text(p.sku);
		} else {
			$sku.prop('hidden', true);
		}

		// Rating
		var $rating = $content.find('.pag-modal__rating');
		if (p.rating > 0 && p.review_count > 0) {
			$rating.prop('hidden', false);
			$rating.find('.pag-modal__stars').text(starsString(p.rating));
			$rating.find('.pag-modal__rating-value').text(' ' + Number(p.rating).toFixed(1));
			$rating.find('.pag-modal__review-count').text(' (' + p.review_count + ')');
		} else {
			$rating.prop('hidden', true);
		}

		// Gallery
		renderGallery(p.gallery || []);

		// Variations
		var $vars = $content.find('.pag-modal__variations');
		if (p.is_variable && p.attributes && p.attributes.length) {
			$vars.empty().prop('hidden', false);
			p.attributes.forEach(function (axis) { renderAxis($vars, axis); });
		} else {
			$vars.prop('hidden', true).empty();
		}

		// Quantity reset
		$content.find('.pag-modal__qty-input').val(1);
		state.quantity = 1;

		// Buttons
		updateButtons();
	}

	function starsString(rating) {
		var rounded = Math.round(rating);
		var s = '';
		for (var i = 1; i <= 5; i++) s += (i <= rounded ? '★' : '☆');
		return s;
	}

	function renderGallery(items) {
		var $hero   = $content.find('.pag-modal__hero-img');
		var $thumbs = $content.find('.pag-modal__thumbs').empty();

		if (!items.length) {
			$hero.attr('src', '').attr('alt', '');
			return;
		}

		$hero.attr('src', items[0].large || items[0].thumb).attr('alt', state.product ? state.product.name : '');

		items.forEach(function (img, idx) {
			var $btn = $('<button type="button" />').toggleClass('is-active', idx === 0);
			$('<img />').attr('src', img.thumb || img.large).attr('alt', img.alt || '').appendTo($btn);
			$btn.on('click', function () {
				$thumbs.find('button').removeClass('is-active');
				$btn.addClass('is-active');
				$hero.attr('src', img.large || img.thumb);
			});
			$thumbs.append($btn);
		});
	}

	function renderAxis($wrap, axis) {
		var $row = $('<div class="pag-attr" />').attr('data-attr-key', axis.key);

		$('<div class="pag-attr__label" />')
			.text(axis.label)
			.append('<span class="pag-attr__selected"></span>')
			.appendTo($row);

		var $opts = $('<div class="pag-attr__options" role="radiogroup" />').appendTo($row);

		(axis.options || []).forEach(function (opt) {
			var $btn = $('<button type="button" class="pag-swatch" role="radio" aria-checked="false" />')
				.attr('data-value', opt.slug)
				.attr('title', opt.label);

			if (opt.type === 'color') {
				$btn.addClass('pag-swatch--color');
				$btn.append($('<span class="pag-swatch__chip" />').css('background-color', opt.value || '#cccccc'));
			} else if (opt.type === 'image' && opt.value) {
				$btn.addClass('pag-swatch--image');
				$btn.append($('<img />').attr('src', opt.value).attr('alt', opt.label));
			} else {
				$btn.text(opt.label);
			}

			$btn.on('click', function () {
				$row.find('.pag-swatch').removeClass('is-selected').attr('aria-checked', 'false');
				$btn.addClass('is-selected').attr('aria-checked', 'true');
				$row.find('.pag-attr__selected').text('— ' + opt.label);
				state.selectedAttrs[axis.key] = opt.slug;
				resolveVariation();
				updateAxisAvailability($wrap);
				updateButtons();
			});

			$opts.append($btn);
		});

		$wrap.append($row);
	}

	function resolveVariation() {
		if (!state.product || !state.product.variations) {
			state.selectedVariationId = 0;
			return;
		}

		var match = state.product.variations.find(function (v) {
			var ok = true;
			Object.keys(v.attributes).forEach(function (k) {
				var got = state.selectedAttrs[k];
				var want = v.attributes[k];
				// WooCommerce uses '' to mean "any".
				if (want === '' || want === null) return;
				if (!got || got !== want) ok = false;
			});
			// Ensure all axes are selected
			(state.product.attributes || []).forEach(function (axis) {
				if (!state.selectedAttrs[axis.key]) ok = false;
			});
			return ok;
		});

		state.selectedVariationId = match ? match.id : 0;

		if (match) {
			$content.find('.pag-modal__price').html(match.price_html || state.product.price_html || '');
			if (match.image) {
				$content.find('.pag-modal__hero-img').attr('src', match.image);
			}
		}
	}

	function updateAxisAvailability($wrap) {
		// Mark options that would have no compatible variation given current selections.
		if (!state.product || !state.product.variations) return;
		$wrap.find('.pag-attr').each(function () {
			var $row = $(this);
			var key  = $row.attr('data-attr-key');
			$row.find('.pag-swatch').each(function () {
				var $btn = $(this);
				var slug = $btn.attr('data-value');
				var trial = Object.assign({}, state.selectedAttrs, {});
				trial[key] = slug;
				var compatible = state.product.variations.some(function (v) {
					var ok = true;
					Object.keys(trial).forEach(function (k2) {
						var got = trial[k2], want = v.attributes[k2];
						if (want === '' || want === null) return;
						if (got !== want) ok = false;
					});
					return ok && v.is_in_stock;
				});
				$btn.toggleClass('is-disabled', !compatible);
				$btn.prop('disabled', !compatible);
			});
		});
	}

	function updateButtons() {
		var $atc = $content.find('.pag-modal__add-to-cart');
		var $buy = $content.find('.pag-modal__buy-now');
		var p    = state.product;
		if (!p) return;

		var enable = false;
		if (!p.purchasable || !p.in_stock) {
			enable = false;
		} else if (p.is_variable) {
			enable = !!state.selectedVariationId;
		} else {
			enable = true;
		}

		$atc.prop('disabled', !enable);
		$buy.prop('disabled', !enable);
	}

	// ---------------------------------------------------------------------
	// Quantity
	// ---------------------------------------------------------------------
	$(document).on('click', '.pag-modal__qty-btn', function () {
		if (!ensureRefs()) return;
		var delta = parseInt($(this).attr('data-pag-qty'), 10) || 0;
		var $input = $content.find('.pag-modal__qty-input');
		var v = Math.max(1, (parseInt($input.val(), 10) || 1) + delta);
		$input.val(v);
		state.quantity = v;
	});
	$(document).on('change input', '.pag-modal__qty-input', function () {
		state.quantity = Math.max(1, parseInt($(this).val(), 10) || 1);
		$(this).val(state.quantity);
	});

	// ---------------------------------------------------------------------
	// Add to Cart from inside modal
	// ---------------------------------------------------------------------
	$(document).on('click', '.pag-modal__add-to-cart', function () {
		var p = state.product;
		if (!p || $(this).prop('disabled')) return;
		var $btn = $(this);
		$btn.addClass('is-loading');

		// We POST to /add-to-cart but for variations we still want the variation to be picked.
		// The default add-to-cart endpoint rejects variable products without selection;
		// we route variable adds through the cart 'add' helper of WC. Easiest: redirect
		// for variable when selection invalid; otherwise call REST.
		var payload = {
			product_id: p.is_variable ? state.selectedVariationId : p.id,
			quantity: state.quantity
		};

		api.request('add-to-cart', { method: 'POST', body: payload }).then(function (resp) {
			$btn.removeClass('is-loading');
			if (resp.fragments) api.applyFragments(resp.fragments);
			$(document.body).trigger('added_to_cart', [ resp.fragments, resp.cart_hash, $btn ]);
			window.PAG.toast(resp.message || (i18n.added || 'Added'), 'success');
			close();
		}).catch(function (err) {
			$btn.removeClass('is-loading');
			window.PAG.toast(err.message || i18n.error || 'Error', 'error');
		});
	});

	// ---------------------------------------------------------------------
	// Buy Now — delegate to bus; buy-now.js handles the actual call
	// ---------------------------------------------------------------------
	$(document).on('click', '.pag-modal__buy-now', function () {
		if ($(this).prop('disabled')) return;
		var p = state.product;
		if (!p) return;

		var payload = {
			product_id: p.id,
			variation_id: p.is_variable ? state.selectedVariationId : 0,
			attributes: p.is_variable ? state.selectedAttrs : {},
			quantity: state.quantity,
			button: this
		};
		bus.emit('buy-now:click', payload);
	});

	// ---------------------------------------------------------------------
	// Trigger from the grid + close handlers
	// ---------------------------------------------------------------------
	$(document).on('click', '.pag-card__quick-view', function (e) {
		var pid = parseInt($(this).attr('data-product-id'), 10);
		if (!pid) return;
		// If we're a button (Quick View module loaded), prevent default link.
		if ($(this).is('button')) {
			e.preventDefault();
			open(pid);
		} else if (ensureRefs()) {
			// Module is loaded but link version → use modal.
			e.preventDefault();
			open(pid);
		}
	});

	$(document).on('click', '[data-pag-close]', function () {
		close();
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && $modal && $modal.is(':visible')) {
			close();
		}
	});

}(jQuery));
