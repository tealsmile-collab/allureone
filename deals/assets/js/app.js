/**
 * Allure Thai Spa Deals — SPA frontend
 */
(function ($) {
  'use strict';

  const state = {
    csrf: window.ALLURE.csrf,
    page: 1,
    filters: {},
    dealFilters: { min_price: '', max_price: '', city_id: '' },
    dealsMode: false,
    cities: [],
    branches: [],
    cart: null,
    cartBarDismissed: false,
    cartBarDismissCount: 0,
    hero: null,
    cartDrawer: null,
    mobileFilters: null,
    productModal: null,
    checkoutModal: null,
  };

  /** Coupon fields = 20; other text inputs without maxlength = 30. Keeps existing maxlength. */
  function applyDefaultMaxLengths(root) {
    const scope = root && root.querySelectorAll ? root : document;
    const couponIds = new Set(['couponCode', 'qvCoupon']);
    scope.querySelectorAll('input').forEach((el) => {
      if (!(el instanceof HTMLInputElement)) return;
      if (el.hasAttribute('maxlength')) return;

      const type = (el.getAttribute('type') || 'text').toLowerCase();
      const skipTypes = new Set([
        'hidden', 'checkbox', 'radio', 'file', 'submit', 'button', 'reset', 'image',
        'range', 'color', 'date', 'datetime-local', 'time', 'month', 'week', 'number',
      ]);
      if (skipTypes.has(type)) return;

      if (couponIds.has(el.id) || el.name === 'code' || /coupon/i.test(el.id + el.name)) {
        el.setAttribute('maxlength', '20');
        return;
      }
      el.setAttribute('maxlength', '30');
    });
  }

  toastr.options = { positionClass: 'toast-bottom-right', timeOut: 2500 };

  const CART_BAR_DISMISS_KEY = 'allure_cart_bar_dismiss';

  function readCartBarDismiss() {
    try {
      const raw = sessionStorage.getItem(CART_BAR_DISMISS_KEY);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!data || typeof data.count !== 'number') return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  function clearCartBarDismiss() {
    try { sessionStorage.removeItem(CART_BAR_DISMISS_KEY); } catch (e) { /* ignore */ }
    state.cartBarDismissed = false;
    state.cartBarDismissCount = 0;
  }

  function rememberCartBarDismiss(itemCount) {
    const count = Math.max(0, Number(itemCount) || 0);
    try {
      sessionStorage.setItem(CART_BAR_DISMISS_KEY, JSON.stringify({ count: count }));
    } catch (e) { /* ignore */ }
    state.cartBarDismissed = true;
    state.cartBarDismissCount = count;
  }

  /** Keep bar hidden after close until cart item_count increases (new item / qty+). */
  function syncCartBarDismiss(itemCount) {
    const count = Math.max(0, Number(itemCount) || 0);
    if (count <= 0) {
      clearCartBarDismiss();
      return;
    }
    const saved = readCartBarDismiss();
    if (!saved) {
      state.cartBarDismissed = false;
      state.cartBarDismissCount = 0;
      return;
    }
    if (count > Number(saved.count || 0)) {
      clearCartBarDismiss();
      return;
    }
    state.cartBarDismissed = true;
    state.cartBarDismissCount = Number(saved.count || 0);
  }

  function api(action, data = null, type = 'GET') {
    const opts = {
      url: window.ALLURE.ajaxUrl + (window.ALLURE.ajaxUrl.includes('?') ? '&' : '?') + 'action=' + encodeURIComponent(action),
      type,
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': state.csrf },
    };
    if (data !== null) {
      opts.contentType = 'application/json';
      opts.data = JSON.stringify({ ...data, csrf_token: state.csrf });
    }
    return $.ajax(opts).then(
      (res) => {
        if (!res || res.success === false) {
          return $.Deferred().reject(res || { message: 'Request failed' }).promise();
        }
        return res.data;
      },
      (jqXHR) => {
        let msg = 'Request failed';
        try {
          const res = typeof jqXHR.responseJSON === 'object' && jqXHR.responseJSON
            ? jqXHR.responseJSON
            : JSON.parse(jqXHR.responseText || '{}');
          if (res && res.message) msg = res.message;
        } catch (e) {
          msg = jqXHR.statusText || msg;
        }
        return $.Deferred().reject({ message: msg, status: jqXHR.status }).promise();
      }
    );
  }

  function money(n) {
    return '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function countdownHtml(endsAt) {
    if (!endsAt) return '';
    return `<span class="countdown" data-ends="${endsAt}">--:--:--</span>`;
  }

  function productCard(p) {
    const strike = p.auto_strike_price && p.original_price > p.offer_price
      ? `<span class="original">${money(p.original_price)}</span>` : '';
    const deal = p.is_today_deal
      ? `<span class="deal-badge">${p.badge_text || 'Limited Time Deal'}</span>${countdownHtml(p.deal_ends_at)}` : '';
    return `<article class="product-card" data-id="${p.id}">
      <div class="product-media">
        <img src="${p.image}" alt="${escapeHtml(p.name)}" loading="lazy">
        ${deal}
      </div>
      <div class="product-body">
        <h3>${escapeHtml(p.name)}</h3>
        <p class="desc">${escapeHtml(p.short_description || '')}</p>
        <div class="price-row">
          <span class="offer">${money(p.offer_price)}</span>
          ${strike}
          ${p.discount_percent > 0 ? `<span class="discount">${p.discount_percent}% off</span>` : ''}
        </div>
        <div class="price-tax-note">Incl. GST</div>
        ${p.save_amount > 0 ? `<div class="save-amt">Save ${money(p.save_amount)}</div>` : ''}
        <div class="card-actions">
          <button class="btn btn-sm btn-cart btn-add" data-id="${p.id}">Add to Cart</button>
          <button class="btn btn-sm btn-quick btn-qv" data-slug="${p.slug}">Quick View</button>
        </div>
      </div>
    </article>`;
  }

  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, (m) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
  }

  function renderProducts(target, data) {
    const $el = $(target);
    if (!data.items || !data.items.length) {
      $el.html('<div class="text-muted py-5 text-center">No deals found. Try adjusting filters.</div>');
      return;
    }
    // Homepage deals strip
    let items = data.items;
    $el.html(items.map(productCard).join(''));
  }

  function renderPagination(data) {
    const $p = $('#pagination').empty();
    if (data.pages <= 1) return;
    for (let i = 1; i <= data.pages; i++) {
      $p.append(`<button class="page-btn ${i === data.page ? 'active' : ''}" data-page="${i}">${i}</button>`);
    }
  }

  function loadProducts(extra = {}) {
    state.filters = { ...state.filters, ...extra };
    const params = {
      page: state.page,
      q: state.filters.q || '',
      category_id: state.filters.category_id || '',
      min_price: state.filters.min_price || '',
      max_price: state.filters.max_price || '',
      min_discount: state.filters.min_discount || '',
      duration: state.filters.duration || '',
      sort: state.filters.sort || 'popular',
    };
    $('#productGrid').html('<div class="skeleton-card"></div><div class="skeleton-card"></div><div class="skeleton-card"></div>');
    return api('products&' + $.param(params).replace(/^/, '')).catch(() =>
      $.getJSON(window.ALLURE.ajaxUrl, { action: 'products', ...params }).then(r => r.data)
    ).then((data) => {
      renderProducts('#productGrid', data);
      renderPagination(data);
    }).catch((err) => toastr.error(err.message || 'Failed to load products'));
  }

  // Fix api for GET with query params
  function getProducts(params) {
    return $.ajax({
      url: window.ALLURE.ajaxUrl,
      data: { action: 'products', ...params },
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then((res) => {
      if (!res.success) throw res;
      return res.data;
    });
  }

  function loadDeals() {
    return getProducts({
      today_deal: 1,
      sort: state.filters.sort || 'discount',
      page: 1,
      per_page: 48,
      q: state.filters.q || '',
      category_id: state.filters.category_id || '',
      duration: state.filters.duration || '',
      min_price: state.dealFilters.min_price || state.filters.min_price || '',
      max_price: state.dealFilters.max_price || state.filters.max_price || '',
      city_id: state.dealFilters.city_id || '',
    }).then((data) => {
      renderProducts('#dealsGrid', data);
    }).catch((err) => {
      console.error('Deals load failed', err);
      $('#dealsGrid').html('<div class="text-muted py-4 text-center">Unable to load today\'s deals. Please refresh.</div>');
    });
  }

  function loadCatalog() {
    // Catalog section removed — shop-by / search refresh Today's Deals grid
    return loadDeals();
  }

  function scrollToDeals() {
    const el = document.getElementById('deals');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function renderHero(slides) {
    const $wrap = $('#heroSlides').empty();
    const $hero = $('#heroSwiper');
    const $section = $('#heroSection');
    if (state.hero) {
      state.hero.destroy(true, true);
      state.hero = null;
    }

    if (!slides.length) {
      $wrap.html(`<div class="swiper-slide">
        <div class="hero-slide-fallback">
          <a href="#deals" class="hero-cta">Shop Today's Deals</a>
        </div>
      </div>`);
      $section.addClass('hero-static');
      $hero.find('.swiper-pagination, .swiper-button-prev, .swiper-button-next').hide();
      return;
    }

    slides.forEach((s) => {
      const href = s.cta_link || '#deals';
      const desktop = s.desktop_image || s.mobile_image || '';
      const mobile = s.mobile_image || s.desktop_image || '';
      const alt = escapeHtml(s.heading || 'Allure offer');
      $wrap.append(`<div class="swiper-slide">
        <a class="hero-slide-link" href="${escapeHtml(href)}" aria-label="${alt}">
          <img class="hero-slide-img d-none d-md-block" src="${escapeHtml(desktop)}" alt="${alt}" loading="eager">
          <img class="hero-slide-img d-md-none" src="${escapeHtml(mobile)}" alt="${alt}" loading="eager">
        </a>
      </div>`);
    });

    // Single active slide: static image, no carousel UI
    if (slides.length === 1) {
      $section.addClass('hero-static');
      $hero.find('.swiper-pagination, .swiper-button-prev, .swiper-button-next').hide();
      return;
    }

    $section.removeClass('hero-static');
    $hero.find('.swiper-pagination, .swiper-button-prev, .swiper-button-next').show();
    state.hero = new Swiper('#heroSwiper', {
      loop: true,
      autoHeight: true,
      autoplay: { delay: 5000, disableOnInteraction: false },
      pagination: { el: '.swiper-pagination', clickable: true },
      navigation: { nextEl: '.swiper-button-next', prevEl: '.swiper-button-prev' },
    });
  }

  function updateCartUI(cart) {
    state.cart = cart;
    const count = cart.item_count || 0;
    syncCartBarDismiss(count);
    const showBar = count > 0 && !state.cartBarDismissed;
    $('#cartCount').text(count);
    $('#mobileCartTotal').text(money(cart.grand_total));
    $('#mobileCartItems').text(count + ' items');
    $('#mobileCartBar').toggleClass('visible', showBar);
    $('body').toggleClass('has-mobile-cart', showBar);

    let html = '';
    if (!cart.items.length) {
      html = '<p class="text-muted">Your cart is empty.</p>';
    } else {
      cart.items.forEach((item) => {
        html += `<div class="cart-item">
          <img src="${item.image}" alt="">
          <div>
            <strong>${escapeHtml(item.name)}</strong>
            <div>${money(item.unit_price)}</div>
            <div class="qty-ctrl">
              <button class="btn-qty" data-id="${item.id}" data-qty="${item.quantity - 1}">-</button>
              <span>${item.quantity}</span>
              <button class="btn-qty" data-id="${item.id}" data-qty="${item.quantity + 1}">+</button>
            </div>
          </div>
          <div>
            <div>${money(item.line_total)}</div>
            <button class="btn btn-link text-danger btn-remove p-0" data-id="${item.id}">Remove</button>
          </div>
        </div>`;
      });
      html += `<div class="cart-totals">
        <div class="row-line"><span>Subtotal (incl. GST)</span><span>${money(cart.subtotal)}</span></div>
        <div class="row-line"><span>Coupon</span><span>-${money(cart.coupon_discount)}</span></div>
        <div class="row-line"><span>GST included (${cart.gst_percent}%)</span><span>${money(cart.gst_amount)}</span></div>
        <div class="row-line grand"><span>Total (incl. GST)</span><span>${money(cart.grand_total)}</span></div>
        <div class="input-group mt-3">
          <input type="text" class="form-control" id="couponCode" placeholder="Coupon code" maxlength="20" value="${cart.coupon_code || ''}">
          <button class="btn btn-outline-dark" id="btnApplyCoupon" type="button">Apply</button>
        </div>
        ${cart.coupon_code ? '<button class="btn btn-link btn-sm px-0" id="btnRemoveCoupon">Remove coupon</button>' : ''}
        <button class="btn btn-primary w-100 mt-3" id="btnCheckout">Proceed to Checkout</button>
      </div>`;
    }
    $('#cartBody').html(html);
    applyDefaultMaxLengths(document.getElementById('cartBody'));
  }

  function openProduct(slug) {
    $.getJSON(window.ALLURE.ajaxUrl, { action: 'product', slug }).then((res) => {
      if (!res.success) return toastr.error(res.message);
      const p = res.data;
      const benefits = (p.benefits || '').split(';').filter(Boolean).map(b => `<li>${escapeHtml(b.trim())}</li>`).join('');
      const related = (p.related || []).map(r => `
        <button class="btn btn-light text-start btn-qv" data-slug="${r.slug}">
          <strong>${escapeHtml(r.name)}</strong><br><small>${money(r.offer_price)}</small>
        </button>`).join('');

      $('#productModalBody').html(`
      <button type="button" class="qv-close" data-bs-dismiss="modal" aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
      <div class="qv-grid">
        <div class="qv-gallery"><img src="${p.image}" alt="${escapeHtml(p.name)}"></div>
        <div class="qv-info">
          <h2>${escapeHtml(p.name)}</h2>
          ${p.duration ? `<div class="mb-2 text-muted">${p.duration} min</div>` : ''}
          <div class="price-row mb-2">
            <span class="offer">${money(p.offer_price)}</span>
            <span class="original">${money(p.original_price)}</span>
            <span class="discount">${p.discount_percent}% off</span>
          </div>
          <div class="price-tax-note mb-2">All prices include GST</div>
          <p>${escapeHtml(p.long_description || p.short_description || '')}</p>
          ${benefits ? `<h6>Benefits</h6><ul>${benefits}</ul>` : ''}
          <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label">City</label><select id="qvCity" class="form-select form-select-sm"></select></div>
            <div class="col-6"><label class="form-label">Branch</label><select id="qvBranch" class="form-select form-select-sm"></select></div>
            <div class="col-6"><label class="form-label">Qty</label><input type="number" id="qvQty" class="form-control form-control-sm" min="1" value="1"></div>
            <div class="col-6"><label class="form-label">Coupon</label><input type="text" id="qvCoupon" class="form-control form-control-sm" placeholder="Optional" maxlength="20"></div>
          </div>
          <div class="d-grid gap-2 d-sm-flex">
            <button class="btn btn-cart flex-fill" id="qvAdd" data-id="${p.id}">Add to Cart</button>
            <button class="btn btn-outline-dark flex-fill" id="qvBuy" data-id="${p.id}">Buy Now</button>
          </div>
          <h6 class="mt-4">Related Services</h6>
          <div class="related-row">${related || '<span class="text-muted">No related items</span>'}</div>
        </div>
      </div>`);

      fillCityBranch('#qvCity', '#qvBranch');
      state.productModal && state.productModal.show();
      applyDefaultMaxLengths(document.getElementById('productModalBody'));
    });
  }

  function fillCityBranch(citySel, branchSel, selectedCity, selectedBranch) {
    const $c = $(citySel).empty().append('<option value="">Select city</option>');
    state.cities.forEach((c) => $c.append(`<option value="${c.id}">${escapeHtml(c.name)}</option>`));
    if (selectedCity) $c.val(String(selectedCity));
    const renderBranches = () => {
      const cityId = $(citySel).val();
      const list = state.branches.filter((b) => !cityId || String(b.city_id) === String(cityId));
      const $b = $(branchSel).empty().append('<option value="">Select branch</option>');
      list.forEach((b) => $b.append(`<option value="${b.id}">${escapeHtml(b.name)}</option>`));
      if (selectedBranch) $b.val(String(selectedBranch));
    };
    $(citySel).off('change.fill').on('change.fill', renderBranches);
    renderBranches();
  }

  function addToCart(productId, qty = 1, cityId = null, branchId = null, buyNow = false) {
    return api('cart_add', {
      product_id: productId,
      quantity: qty,
      city_id: cityId || null,
      branch_id: branchId || null,
    }, 'POST').then((cart) => {
      updateCartUI(cart);
      toastr.success('Added to cart');
      if (buyNow) {
        state.productModal && state.productModal.hide();
        openCheckout();
      } else {
        state.cartDrawer && state.cartDrawer.show();
      }
    }).catch((err) => toastr.error(err.message || 'Could not add to cart'));
  }

  function openCheckout() {
    if (!state.cart || !state.cart.items.length) return toastr.warning('Cart is empty');
    fillCityBranch('#checkoutCity', '#checkoutBranch', state.cart.city_id, state.cart.branch_id);
    $('#checkoutSummary').html(`
      <div class="cart-totals mb-0">
        <div class="row-line"><span>Subtotal (incl. GST)</span><span>${money(state.cart.subtotal)}</span></div>
        <div class="row-line"><span>Discount</span><span>-${money(state.cart.coupon_discount)}</span></div>
        <div class="row-line"><span>GST included (${state.cart.gst_percent}%)</span><span>${money(state.cart.gst_amount)}</span></div>
        <div class="row-line grand"><span>Pay (incl. GST)</span><span>${money(state.cart.grand_total)}</span></div>
      </div>`);
    state.checkoutModal && state.checkoutModal.show();
  }

  /** Razorpay / Bootstrap often leave overflow:hidden on html/body after dismiss. */
  function restorePageScroll() {
    const html = document.documentElement;
    const body = document.body;
    body.classList.remove('modal-open');
    [html, body].forEach((el) => {
      el.style.removeProperty('overflow');
      el.style.removeProperty('overflow-x');
      el.style.removeProperty('overflow-y');
      el.style.removeProperty('padding-right');
      el.style.removeProperty('position');
      el.style.removeProperty('height');
      el.style.removeProperty('touch-action');
    });
    // Drop orphaned backdrops if no modal/offcanvas is open
    if (!document.querySelector('.modal.show, .offcanvas.show')) {
      document.querySelectorAll('.modal-backdrop, .offcanvas-backdrop').forEach((el) => el.remove());
    }
  }

  function payWithRazorpay(order) {
    // Demo mode when Razorpay keys are placeholders
    if (String(order.razorpay_order_id).startsWith('order_demo_')) {
      return api('payment_verify', {
        order_id: order.order_id,
        razorpay_order_id: order.razorpay_order_id,
        razorpay_payment_id: 'pay_demo_' + Date.now(),
        razorpay_signature: 'demo',
      }, 'POST').then((res) => {
        restorePageScroll();
        Swal.fire('Payment Successful', `Order ${res.order_no} confirmed.`, 'success');
        return api('cart_get').then(updateCartUI);
      });
    }

    const options = {
      key: order.razorpay_key,
      amount: order.amount_paise,
      currency: 'INR',
      name: order.company,
      description: 'Order ' + order.order_no,
      order_id: order.razorpay_order_id,
      prefill: {
        name: order.customer.name,
        email: order.customer.email,
        contact: order.customer.contact,
      },
      theme: { color: '#978671' },
      modal: {
        ondismiss: function () {
          // Closing Razorpay without paying leaves body scroll locked
          restorePageScroll();
          toastr.info('Payment cancelled. You can try again from checkout.');
        },
      },
      handler: function (response) {
        api('payment_verify', {
          order_id: order.order_id,
          razorpay_order_id: response.razorpay_order_id,
          razorpay_payment_id: response.razorpay_payment_id,
          razorpay_signature: response.razorpay_signature,
        }, 'POST').then((res) => {
          restorePageScroll();
          Swal.fire({
            icon: 'success',
            title: 'Payment Successful',
            html: `Order <b>${res.order_no}</b> confirmed.<br>Invoice: ${res.invoice_no}`,
          });
          api('cart_get').then(updateCartUI);
        }).catch((err) => {
          restorePageScroll();
          Swal.fire('Error', err.message || 'Verification failed', 'error');
        });
      },
    };
    const rzp = new Razorpay(options);
    rzp.on('payment.failed', function () {
      restorePageScroll();
    });
    rzp.open();
  }

  function tickCountdowns() {
    $('[data-ends]').each(function () {
      const ends = new Date($(this).data('ends')).getTime();
      const diff = ends - Date.now();
      if (diff <= 0) { $(this).text('Expired'); return; }
      const h = Math.floor(diff / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000) / 1000);
      $(this).text(`${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`);
    });
  }

  function initUiComponents() {
    const BS = window.bootstrap;
    if (BS && typeof BS.Offcanvas === 'function') {
      const cartEl = document.getElementById('cartDrawer');
      if (cartEl) state.cartDrawer = BS.Offcanvas.getOrCreateInstance(cartEl);
      const mobileFilterEl = document.getElementById('mobileFiltersSheet');
      if (mobileFilterEl) state.mobileFilters = BS.Offcanvas.getOrCreateInstance(mobileFilterEl);
    } else {
      // Fallback drawer without Bootstrap Offcanvas
      state.cartDrawer = {
        show() { $('#cartDrawer').addClass('show').css({ visibility: 'visible', transform: 'none' }); $('body').append('<div class="offcanvas-backdrop fade show" id="cartBackdrop"></div>'); },
        hide() { $('#cartDrawer').removeClass('show'); $('#cartBackdrop').remove(); },
      };
      state.mobileFilters = {
        show() { $('#mobileFiltersSheet').addClass('show').css({ visibility: 'visible', transform: 'none' }); },
        hide() { $('#mobileFiltersSheet').removeClass('show'); },
      };
    }
    if (BS && typeof BS.Modal === 'function') {
      const productEl = document.getElementById('productModal');
      const checkoutEl = document.getElementById('checkoutModal');
      if (productEl) state.productModal = BS.Modal.getOrCreateInstance(productEl);
      if (checkoutEl) state.checkoutModal = BS.Modal.getOrCreateInstance(checkoutEl);
    }
  }

  function syncCategoryChips(activeId) {
    const id = activeId == null ? '' : String(activeId);
    $('.category-chip').removeClass('active');
    const $match = $(`.category-chip[data-id="${id}"]`);
    if ($match.length) $match.addClass('active');
    else $('.category-chip[data-id=""]').addClass('active');
    if ($('#mobileCategory').length) $('#mobileCategory').val(id);
  }

  function setCategoryMode(on) {
    if (on) state.dealsMode = false;
    if (on) {
      $('#btnShopCategory').addClass('active');
    }
  }

  function setDealsMode(on) {
    state.dealsMode = !!on;
    $('#btnDealsFilters').prop('hidden', !on);
    if (!on) {
      $('#btnDealsFilters').prop('hidden', true);
    }
  }

  function openMobileFilters() {
    $('#mobileMinPrice').val(state.filters.min_price || state.dealFilters.min_price || '');
    $('#mobileMaxPrice').val(state.filters.max_price || state.dealFilters.max_price || '');
    $('#mobileDuration').val(state.filters.duration || '');
    $('#mobileCategory').val(state.filters.category_id || '');
    $('#mobileFilterCategoryGroup').prop('hidden', true);
    state.mobileFilters && state.mobileFilters.show();
  }

  function renderCategoryBars(categories) {
    const $mobileCat = $('#mobileCategory').empty().append('<option value="">All</option>');
    (categories || []).forEach((c) => {
      $mobileCat.append(`<option value="${c.id}">${escapeHtml(c.name)}</option>`);
    });
  }

  function populateDealLocations(cities) {
    const $loc = $('#dealsLocation').empty().append('<option value="">All locations</option>');
    (cities || []).forEach((c) => {
      $loc.append(`<option value="${c.id}">${escapeHtml(c.name)}</option>`);
    });
  }

  function initApp() {
    initUiComponents();

    return $.getJSON(window.ALLURE.ajaxUrl, { action: 'bootstrap' }).then((res) => {
      if (!res.success) throw res;
      const d = res.data;
      state.csrf = d.csrf;
      window.ALLURE.csrf = d.csrf;
      state.cities = d.cities || [];
      state.branches = d.branches || [];
      updateCartUI(d.cart);
      renderHero(d.sliders || []);

      renderCategoryBars(d.categories || []);
      populateDealLocations(d.cities || []);
      state.cities.forEach((c) => {
        if ($('#filterCity').length) {
          $('#filterCity').append(`<option value="${c.id}">${escapeHtml(c.name)}</option>`);
        }
      });
      const base = String(window.ALLURE.baseUrl || '').replace(/\/$/, '');
      const hiddenSlugs = new Set(['refund-policy', 'no-refund-policy', 'gift-voucher-policy']);
      const policies = (d.policies || []).filter((p) => !hiddenSlugs.has(String(p.slug || '')));
      const fallback = [
        { slug: 'privacy-policy', title: 'Privacy Policy' },
        { slug: 'terms-conditions', title: 'Terms & Conditions' },
        { slug: 'cancellation-policy', title: 'Cancellation Policy and Refund Policy' },
        { slug: 'digital-product-policy', title: 'Digital Product Policy' },
        { slug: 'payment-policy', title: 'Payment Policy' },
      ];
      const list = policies.length ? policies : fallback;
      $('#footerPolicies').html(list.map((p) =>
        `<li><a href="${escapeHtml(base + '/policy/' + p.slug + '/')}">${escapeHtml(p.title)}</a></li>`
      ).join(''));

      // Render deals from bootstrap payload
      if (d.deals && Array.isArray(d.deals.items)) {
        renderProducts('#dealsGrid', d.deals);
      } else {
        loadDeals();
      }
    }).catch((err) => {
      console.error('App init failed', err);
      $('#dealsGrid').html('<div class="alert alert-warning">Unable to connect to API. Please refresh.</div>');
      loadDeals();
    });
  }

  function uiShow(comp) { if (comp && typeof comp.show === 'function') comp.show(); }
  function uiHide(comp) { if (comp && typeof comp.hide === 'function') comp.hide(); }

  function uiShow(comp) { if (comp && typeof comp.show === 'function') comp.show(); }
  function uiHide(comp) { if (comp && typeof comp.hide === 'function') comp.hide(); }

  // Events
  $('#applyDealsFilters').on('click', function () {
    state.dealFilters.min_price = $('#dealsMinPrice').val() || '';
    state.dealFilters.max_price = $('#dealsMaxPrice').val() || '';
    state.dealFilters.city_id = $('#dealsLocation').val() || '';
    loadDeals();
  });

  $('#resetDealsFilters').on('click', function () {
    $('#dealsMinPrice, #dealsMaxPrice').val('');
    $('#dealsLocation').val('');
    state.dealFilters = { min_price: '', max_price: '', city_id: '' };
    loadDeals();
  });

  $('#dealsLocation').on('change', function () {
    state.dealFilters.city_id = $(this).val() || '';
    loadDeals();
  });

  $(document).on('click', '.category-chip', function () {
    const id = $(this).data('id') || '';
    state.filters.category_id = id;
    state.filters.today_deal = '';
    setCategoryMode(true);
    syncCategoryChips(id);
    // Close mobile panel so chips don't cover product images
    if (window.matchMedia('(max-width: 767px)').matches) {
      $('#mobileCategoryPanel').prop('hidden', true);
      $('#btnShopCategory').attr('aria-expanded', 'false');
    }
    state.page = 1;
    loadDeals();
    scrollToDeals();
  });

  $('#catalogCategorySelect').on('change', function () {
    const id = $(this).val() || '';
    state.filters.category_id = id;
    state.filters.today_deal = '1';
    setCategoryMode(true);
    syncCategoryChips(id);
    state.page = 1;
    loadDeals();
    scrollToDeals();
  });

  $('#btnShopCategory').on('click', function () {
    const $panel = $('#mobileCategoryPanel');
    const open = $panel.prop('hidden');
    $panel.prop('hidden', !open);
    $(this).attr('aria-expanded', open ? 'true' : 'false');
    $(this).toggleClass('active', open);
    if (open) {
      state.filters.today_deal = '1';
      setCategoryMode(true);
      state.page = 1;
      loadDeals();
      scrollToDeals();
    }
  });

  function openFiltersFromUi() {
    openMobileFilters();
  }

  $('#btnMobileFilters, #btnDealsFilters, #btnCatalogDealsFilters').on('click', openFiltersFromUi);

  $('#mobileApplyFilters').on('click', function () {
    state.filters.min_price = $('#mobileMinPrice').val();
    state.filters.max_price = $('#mobileMaxPrice').val();
    state.filters.duration = $('#mobileDuration').val();
    state.filters.category_id = $('#mobileCategory').val() || '';
    state.dealFilters.min_price = state.filters.min_price || '';
    state.dealFilters.max_price = state.filters.max_price || '';
    $('#dealsMinPrice').val(state.dealFilters.min_price || '');
    $('#dealsMaxPrice').val(state.dealFilters.max_price || '');
    syncCategoryChips(state.filters.category_id || '');
    state.filters.today_deal = '1';
    state.page = 1;
    loadDeals();
    state.mobileFilters && state.mobileFilters.hide();
    scrollToDeals();
  });

  $('#mobileResetFilters').on('click', function () {
    $('#mobileMinPrice, #mobileMaxPrice, #mobileDuration, #mobileCategory').val('');
    state.filters.min_price = '';
    state.filters.max_price = '';
    state.filters.duration = '';
    state.filters.category_id = '';
    state.dealFilters.min_price = '';
    state.dealFilters.max_price = '';
    $('#dealsMinPrice, #dealsMaxPrice').val('');
    syncCategoryChips('');
    state.filters.today_deal = '1';
    state.page = 1;
    loadDeals();
    state.mobileFilters && state.mobileFilters.hide();
  });

  $('#sortSelect').on('change', function () {
    state.filters.sort = $(this).val();
    state.page = 1;
    loadDeals();
  });

  $(document).on('click', '.page-btn', function () {
    state.page = Number($(this).data('page'));
    loadDeals();
  });

  $('#btnCart, #mobileCartBtn').on('click', () => state.cartDrawer && state.cartDrawer.show());

  $('#mobileCartClose').on('click', function (e) {
    e.stopPropagation();
    rememberCartBarDismiss(state.cart ? (state.cart.item_count || 0) : 0);
    $('#mobileCartBar').removeClass('visible');
    $('body').removeClass('has-mobile-cart');
  });

  $(document).on('click', '.btn-add', function () {
    addToCart($(this).data('id'));
  });

  $(document).on('click', '.btn-qv', function () {
    openProduct($(this).data('slug'));
  });

  $(document).on('click', '#qvAdd, #qvBuy', function () {
    addToCart(
      $(this).data('id'),
      Number($('#qvQty').val() || 1),
      $('#qvCity').val() || null,
      $('#qvBranch').val() || null,
      this.id === 'qvBuy'
    );
  });

  $(document).on('click', '.btn-qty', function () {
    api('cart_update', { item_id: $(this).data('id'), quantity: $(this).data('qty') }, 'POST')
      .then(updateCartUI)
      .catch((e) => toastr.error(e.message || 'Update failed'));
  });

  $(document).on('click', '.btn-remove', function () {
    api('cart_remove', { item_id: $(this).data('id') }, 'POST')
      .then(updateCartUI)
      .catch((e) => toastr.error(e.message || 'Remove failed'));
  });

  $(document).on('click', '#btnApplyCoupon', function () {
    api('coupon_apply', { code: $('#couponCode').val() }, 'POST')
      .then((cart) => { updateCartUI(cart); toastr.success('Coupon applied'); })
      .catch((e) => toastr.error(e.message || 'Invalid coupon'));
  });

  $(document).on('click', '#btnRemoveCoupon', function () {
    api('coupon_remove', {}, 'POST').then(updateCartUI);
  });

  $(document).on('click', '#btnCheckout', openCheckout);

  // Mobile: digits only
  $(document).on('input', '#checkoutMobile, input[name="mobile"]', function () {
    this.value = this.value.replace(/\D+/g, '').slice(0, 15);
  });
  $(document).on('keypress', '#checkoutMobile, input[name="mobile"]', function (e) {
    const ch = e.which || e.keyCode;
    // Allow control keys
    if (ch === 8 || ch === 9 || ch === 13) return;
    if (ch < 48 || ch > 57) e.preventDefault();
  });
  $(document).on('paste', '#checkoutMobile, input[name="mobile"]', function (e) {
    e.preventDefault();
    const text = (e.originalEvent.clipboardData || window.clipboardData).getData('text') || '';
    const digits = text.replace(/\D+/g, '').slice(0, 15);
    const el = this;
    const start = el.selectionStart || 0;
    const end = el.selectionEnd || 0;
    el.value = (el.value.slice(0, start) + digits + el.value.slice(end)).replace(/\D+/g, '').slice(0, 15);
  });

  $('#checkoutForm').on('submit', function (e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(this).entries());
    const name = String(data.name || '').trim();
    const mobile = String(data.mobile || '').replace(/\D+/g, '');
    const email = String(data.email || '').trim();
    const notes = String(data.notes || '').trim();

    if (!name) return toastr.error('Name is required');
    if (name.length > 80) return toastr.error('Name must be max 80 characters');
    if (!mobile) return toastr.error('Mobile is required');
    if (!/^\d{10,15}$/.test(mobile)) return toastr.error('Mobile must be 10–15 digits only');
    if (email && email.length > 100) return toastr.error('Email must be max 100 characters');
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return toastr.error('Enter a valid email');
    if (notes.length > 200) return toastr.error('Notes must be max 200 characters');
    if (!data.branch_id) return toastr.error('Please select a branch');

    data.name = name;
    data.mobile = mobile;
    data.email = email;
    data.notes = notes;

    api('checkout_create', data, 'POST').then((order) => {
      const checkoutEl = document.getElementById('checkoutModal');
      const openPay = () => {
        restorePageScroll();
        payWithRazorpay(order);
      };
      state.cartDrawer && state.cartDrawer.hide();
      if (state.checkoutModal && checkoutEl && checkoutEl.classList.contains('show')) {
        // Wait for Bootstrap hide so modal-open / overflow lock is cleared before Razorpay
        $(checkoutEl).one('hidden.bs.modal', openPay);
        state.checkoutModal.hide();
      } else {
        state.checkoutModal && state.checkoutModal.hide();
        openPay();
      }
    }).catch((err) => toastr.error(err.message || 'Checkout failed'));
  });

  $(document).on('click', '.policy-link', function (e) {
    e.preventDefault();
    const slug = $(this).data('slug');
    $.getJSON(window.ALLURE.ajaxUrl, { action: 'policy', slug }).then((res) => {
      if (!res.success) return;
      $('#policyBody').html(`<div class="modal-header"><h5 class="modal-title">${escapeHtml(res.data.title)}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">${res.data.content}</div>`);
      const policyEl = document.getElementById('policyModal');
      if (window.bootstrap && policyEl) {
        window.bootstrap.Modal.getOrCreateInstance(policyEl).show();
      }
    });
  });

  // Back to top — show when page is scrolled
  const $backTop = $('#btnBackToTop');
  function updateBackToTop() {
    $backTop.prop('hidden', window.scrollY <= 280);
  }
  $(window).on('scroll resize', updateBackToTop);
  updateBackToTop();
  $backTop.on('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  setInterval(tickCountdowns, 1000);
  applyDefaultMaxLengths(document);
  initApp();
})(jQuery);
