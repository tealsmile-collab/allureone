(function ($) {
  if (window.toastr) {
    toastr.options = {
      closeButton: true,
      progressBar: true,
      positionClass: 'toast-top-right',
      timeOut: 4000,
      extendedTimeOut: 2000,
      newestOnTop: true,
    };
  }

  if (window.DASHBOARD_SALES && document.getElementById('salesChart')) {
    const rows = window.DASHBOARD_SALES || [];
    new Chart(document.getElementById('salesChart'), {
      type: 'line',
      data: {
        labels: rows.map((r) => r.d),
        datasets: [{
          label: 'Revenue',
          data: rows.map((r) => Number(r.total)),
          borderColor: '#978671',
          backgroundColor: 'rgba(178,189,163,.35)',
          fill: true,
          tension: .35,
        }],
      },
      options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } },
    });
  }

  window.adminPost = function (action, data) {
    return $.ajax({
      url: window.ADMIN.ajax,
      method: 'POST',
      contentType: 'application/json',
      headers: { 'X-CSRF-TOKEN': window.ADMIN.csrf || '' },
      data: JSON.stringify({ action, csrf_token: window.ADMIN.csrf, ...data }),
      dataType: 'json',
    }).then((res) => {
      if (!res.success) return $.Deferred().reject(res).promise();
      return res;
    }, (jqXHR) => {
      let msg = 'Request failed';
      try {
        const res = jqXHR.responseJSON || JSON.parse(jqXHR.responseText || '{}');
        if (res && res.message) msg = res.message;
      } catch (e) { /* ignore */ }
      return $.Deferred().reject({ message: msg }).promise();
    });
  };

  $(document).on('click', '[data-delete]', function () {
    const id = $(this).data('delete');
    const type = $(this).data('type');
    Swal.fire({
      title: 'Delete?',
      text: 'This will soft-delete the record.',
      icon: 'warning',
      showCancelButton: true,
    }).then((r) => {
      if (!r.isConfirmed) return;
      adminPost('delete', { type, id }).then(() => location.reload())
        .catch((e) => Swal.fire('Error', e.message || 'Failed', 'error'));
    });
  });

  // Today's Deals: confirm + soft delete (mark inactive)
  $(document).on('click', '[data-delete-deal]', function () {
    const id = $(this).data('delete-deal');
    const productId = $(this).data('product-id');
    Swal.fire({
      title: 'Deactivate this deal?',
      text: 'This will mark the deal as inactive (soft delete). You can edit and reactivate later.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, deactivate',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#978671',
    }).then((r) => {
      if (!r.isConfirmed) return;
      adminPost('deactivate_deal', { id, product_id: productId })
        .then(() => location.reload())
        .catch((e) => Swal.fire('Error', e.message || 'Failed', 'error'));
    });
  });

  // Deal form: original price + linked discount % / deal price
  (function initDealPriceCalc() {
    const $form = $('#dealForm');
    if (!$form.length) return;

    let lock = false;
    const $product = $('#dealProduct');
    const $original = $('#dealOriginal');
    const $originalDisplay = $('#dealOriginalDisplay');
    const $discount = $('#dealDiscount');
    const $price = $('#dealPrice');

    function moneyInr(n) {
      return '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function syncOriginalFromProduct() {
      const val = $product.val();
      if (!val) {
        $original.val('0');
        $originalDisplay.val('');
        return 0;
      }
      const original = parseFloat($product.find(':selected').data('original')) || 0;
      $original.val(original.toFixed(2));
      $originalDisplay.val(original > 0 ? moneyInr(original) : '');
      return original;
    }

    function calcDealFromDiscount() {
      if (lock) return;
      lock = true;
      const original = parseFloat($original.val()) || syncOriginalFromProduct();
      let pct = parseFloat($discount.val());
      if (isNaN(pct)) pct = 0;
      pct = Math.min(100, Math.max(0, pct));
      const deal = Math.round((original * (1 - pct / 100)) * 100) / 100;
      $price.val(deal.toFixed(2));
      lock = false;
    }

    function calcDiscountFromDeal() {
      if (lock) return;
      lock = true;
      const original = parseFloat($original.val()) || syncOriginalFromProduct();
      let deal = parseFloat($price.val());
      if (isNaN(deal)) deal = 0;
      if (original <= 0) {
        $discount.val('0');
        lock = false;
        return;
      }
      deal = Math.min(original, Math.max(0, deal));
      const pct = Math.round(((original - deal) / original) * 10000) / 100;
      $discount.val(pct.toFixed(2));
      lock = false;
    }

    $product.on('change', function () {
      const original = syncOriginalFromProduct();
      if (!original) return;
      if ($discount.val() !== '') {
        calcDealFromDiscount();
      } else if ($price.val() !== '') {
        calcDiscountFromDeal();
      }
    });

    $discount.on('input change', calcDealFromDiscount);
    $price.on('input change', calcDiscountFromDeal);

    // Only sync on load when a product is already selected (edit mode)
    if ($product.val()) {
      syncOriginalFromProduct();
      if ($price.val() === '' && $discount.val() !== '') {
        calcDealFromDiscount();
      } else if ($discount.val() === '' && $price.val() !== '') {
        calcDiscountFromDeal();
      }
    }
  })();
// Deal saved toast + clear add/edit form state via redirect (?saved=)
  (function showDealSavedToast() {
    if (!window.toastr) return;
    const params = new URLSearchParams(window.location.search);
    const saved = params.get('saved');
    if (!saved) return;

    const msg = 'Deal saved successfully.';
    toastr.success(msg, 'Success');

    // Clean URL so refresh doesn't re-show toast; form is already blank (no ?edit=)
    params.delete('saved');
    const qs = params.toString();
    const cleanUrl = window.location.pathname + (qs ? '?' + qs : '');
    window.history.replaceState({}, '', cleanUrl);
  })();

  /** Coupon code = 20; other text inputs without maxlength = 30. */
  (function applyDefaultMaxLengths() {
    document.querySelectorAll('input').forEach((el) => {
      if (!(el instanceof HTMLInputElement) || el.hasAttribute('maxlength')) return;
      const type = (el.getAttribute('type') || 'text').toLowerCase();
      const skip = new Set([
        'hidden', 'checkbox', 'radio', 'file', 'submit', 'button', 'reset', 'image',
        'range', 'color', 'date', 'datetime-local', 'time', 'month', 'week', 'number',
      ]);
      if (skip.has(type)) return;
      if (el.name === 'code' || /coupon/i.test(el.id + el.name)) {
        el.setAttribute('maxlength', '20');
        return;
      }
      el.setAttribute('maxlength', '30');
    });
  })();
})(jQuery);
