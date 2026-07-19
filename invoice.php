<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
if ((int) ($user['role_id'] ?? 0) !== ROLE_SUPERADMIN) {
    http_response_code(403);
    exit('Forbidden');
}

$branches = [];
try {
    $st = db()->query(
        'SELECT id, business_name, locality
         FROM allureone_branch
         WHERE isActive = 1
         ORDER BY business_name ASC, locality ASC'
    );
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $business = trim((string) ($row['business_name'] ?? ''));
        $locality = trim((string) ($row['locality'] ?? ''));
        $branches[] = [
            'id' => $id,
            'label' => $business !== '' && $locality !== ''
                ? $business . ' — ' . $locality
                : ($business !== '' ? $business : ($locality !== '' ? $locality : 'Branch ' . $id)),
        ];
    }
} catch (Throwable $e) {
    error_log('Invoice branch list failed: ' . $e->getMessage());
}

$todayIst = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$pageTitle = 'Invoice';
$activeNav = 'invoice';
require __DIR__ . '/includes/layout_start.php';
?>

<style>
.inv { max-width: 580px; margin: 0 auto; padding: 0 0.25rem 5rem; }
.inv h1 { margin: 0 0 0.85rem; font-size: 1.45rem; }
.inv-card {
  margin-bottom: 0.8rem; padding: 0.9rem; border: 2px solid #d7dee7;
  border-radius: 14px; background: #fff;
}
.inv-card[hidden] { display: none; }
.inv-title { margin: 0 0 0.55rem; color: #12263a; font-size: 1.05rem; font-weight: 800; }
.inv-help { margin: 0.25rem 0 0.65rem; color: #667085; font-size: 0.92rem; }
.inv-field {
  width: 100%; min-height: 50px; box-sizing: border-box; margin-bottom: 0.6rem;
  padding: 0.65rem 0.75rem; border: 2px solid #c9d2dc; border-radius: 11px;
  background: #fff; font-size: 1.05rem;
}
.inv-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem; }
.inv-actions { display: flex; gap: 0.55rem; justify-content: flex-end; }
.inv-btn {
  min-height: 46px; padding: 0.6rem 0.85rem; border: 0; border-radius: 10px;
  background: #175cd3; color: #fff; font-size: 1rem; font-weight: 750; cursor: pointer;
}
.inv-btn--green { background: #1f7a4d; }
.inv-btn--ghost { background: #eef2f6; color: #12263a; }
.inv-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.inv-duration { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
.inv-duration button {
  min-height: 48px; border: 2px solid #d0d5dd; border-radius: 10px;
  background: #fff; font-weight: 700;
}
.inv-duration button.is-selected { border-color: #1f7a4d; background: #ecfdf3; }
.inv-results { max-height: 245px; overflow: auto; border: 2px solid #d0d5dd; border-radius: 11px; }
.inv-result {
  display: block; width: 100%; min-height: 48px; padding: 0.6rem 0.7rem;
  border: 0; border-bottom: 1px solid #e4e7ec; background: #fff; text-align: left;
}
.inv-selected {
  display: flex; align-items: center; justify-content: space-between; gap: 0.6rem;
  padding: 0.65rem; border-radius: 10px; background: #f0fdf4;
}
.inv-selected strong { display: block; }
.inv-compact { padding: 0.45rem 0.75rem; }
.inv-compact-row {
  display: flex; align-items: center; justify-content: space-between; gap: 0.65rem;
  padding: 0.5rem 0; border-bottom: 1px solid #e4e7ec;
}
.inv-compact-row:last-child { border-bottom: 0; }
.inv-compact-text { min-width: 0; }
.inv-compact-text strong {
  display: block; color: #475467; font-size: 0.78rem; text-transform: uppercase;
}
.inv-compact-value {
  display: block; overflow: hidden; color: #12263a; font-size: 0.95rem;
  font-weight: 700; text-overflow: ellipsis; white-space: nowrap;
}
.inv-edit {
  flex: 0 0 auto; padding: 0.3rem 0.55rem; border: 0; border-radius: 7px;
  background: #eaf2ff; color: #175cd3; font-size: 0.82rem; font-weight: 750;
}
.inv-price { font-size: 1.3rem; font-weight: 850; color: #1f7a4d; }
.inv-summary { padding: 0.75rem; border-radius: 10px; background: #f8fafc; }
.inv-summary div { display: flex; justify-content: space-between; gap: 1rem; margin: 0.25rem 0; }
.inv-payment {
  display: flex; justify-content: space-between; gap: 0.6rem; align-items: center;
  padding: 0.6rem; margin-bottom: 0.45rem; border: 1px solid #d0d5dd; border-radius: 9px;
}
.inv-error { min-height: 1.2rem; margin: 0.35rem 0; color: #b42318; font-weight: 650; }
.inv-ok { color: #1f7a4d; }
.inv-json { min-height: 260px; font: 12px/1.4 Consolas, monospace; }
.inv-modal {
  display: none; position: fixed; inset: 0; z-index: 100; align-items: flex-end;
  justify-content: center; background: rgba(15,23,42,.48);
}
.inv-modal.is-open { display: flex; }
.inv-sheet {
  width: 100%; max-width: 560px; max-height: 94vh; overflow: auto; box-sizing: border-box;
  padding: 1rem; border-radius: 18px 18px 0 0; background: #fff;
}
.inv-sheet-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.8rem; }
.inv-sheet-head h2 { margin: 0; font-size: 1.25rem; }
.inv-close { width: 42px; height: 42px; border: 0; border-radius: 50%; font-size: 1.3rem; }
.inv-mobile-label { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
.inv-country { max-width: 185px; min-height: 34px; border: 1px solid #c9d2dc; border-radius: 8px; }
@media (max-width: 420px) {
  .inv-row { grid-template-columns: 1fr; gap: 0; }
}
@media (min-width: 700px) {
  .inv-modal { align-items: center; padding: 1rem; }
  .inv-sheet { border-radius: 18px; }
}
</style>

<main class="inv" id="invoiceApp" data-csrf="<?= e(csrf_token()) ?>" data-today="<?= e($todayIst) ?>">
  <h1>Create Invoice</h1>

  <section class="inv-card">
    <p class="inv-title">1. Branch and date</p>
    <select class="inv-field" id="invBranch">
      <option value="">Select branch</option>
      <?php foreach ($branches as $branch): ?>
        <option value="<?= (int) $branch['id'] ?>"><?= e((string) $branch['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <input class="inv-field" id="invDate" type="date" value="<?= e($todayIst) ?>">
  </section>

  <section class="inv-card inv-compact" id="selectionSummary" hidden>
    <div id="selectionSummaryRows"></div>
  </section>

  <section class="inv-card" id="clientCard" hidden>
    <p class="inv-title">2. Select client</p>
    <p class="inv-help">Type at least 3 letters of the name or mobile digits.</p>
    <input class="inv-field" id="clientSearch" type="search" autocomplete="off" placeholder="Client name or mobile">
    <div class="inv-actions">
      <button class="inv-btn" type="button" id="addClientBtn" hidden>+ Add client</button>
    </div>
    <div id="clientResults"></div>
    <div id="selectedClient"></div>
  </section>

  <section class="inv-card" id="staffCard" hidden>
    <p class="inv-title">3. Select staff</p>
    <select class="inv-field" id="staffSelect"><option value="">Select staff</option></select>
  </section>

  <section class="inv-card" id="durationCard" hidden>
    <p class="inv-title">4. Service time</p>
    <div class="inv-duration" id="durationChoices">
      <button type="button" data-minutes="30">30 mins</button>
      <button type="button" data-minutes="60">60 mins (1 Hour)</button>
      <button type="button" data-minutes="90">90 mins (1.5 Hours)</button>
      <button type="button" data-minutes="120">120 mins (2 Hours)</button>
    </div>
  </section>

  <section class="inv-card" id="serviceCard" hidden>
    <p class="inv-title">5. Select service</p>
    <input class="inv-field" id="serviceSearch" type="search" autocomplete="off" placeholder="Search service">
    <div id="serviceResults"></div>
    <div id="selectedService"></div>
  </section>

  <section class="inv-card" id="roomCard" hidden>
    <p class="inv-title">6. Select room</p>
    <select class="inv-field" id="roomSelect"><option value="">Select room</option></select>
  </section>

  <section class="inv-card" id="priceCard" hidden>
    <p class="inv-title">7. Price and discount</p>
    <div class="inv-summary">
      <div><span>Service price</span><strong class="inv-price" id="servicePrice">₹0</strong></div>
    </div>
    <label for="offerPrice"><strong>Offer price</strong></label>
    <input class="inv-field" id="offerPrice" type="number" min="0" step="0.01">
    <div class="inv-summary">
      <div><span>Discount</span><strong id="discountAmount">₹0</strong></div>
      <div><span>Tax</span><strong id="taxAmount">₹0</strong></div>
      <div><span>Final payable</span><strong id="payableAmount">₹0</strong></div>
    </div>
  </section>

  <section class="inv-card" id="paymentCard" hidden>
    <p class="inv-title">8. Payment modes</p>
    <div id="paymentList"></div>
    <div class="inv-row">
      <select class="inv-field" id="paymentMode"><option value="">Select payment mode</option></select>
      <input class="inv-field" id="paymentAmount" type="number" min="0.01" step="0.01" placeholder="Amount">
    </div>
    <button class="inv-btn" type="button" id="addPayment">Add payment</button>
    <div class="inv-summary" style="margin-top:.65rem">
      <div><span>Paid</span><strong id="paidAmount">₹0</strong></div>
      <div><span>Remaining</span><strong id="remainingAmount">₹0</strong></div>
    </div>
    <p class="inv-error" id="invoiceMessage" aria-live="polite"></p>
    <button class="inv-btn inv-btn--green" type="button" id="checkoutBtn" disabled>Checkout</button>
  </section>

  <section class="inv-card" id="payloadCard" hidden>
    <p class="inv-title">Invoice JSON payload</p>
    <p class="inv-help">Latest invoice prefix and number were fetched immediately before generating this payload.</p>
    <input class="inv-field" id="invoiceNumber" type="text" readonly>
    <textarea class="inv-field inv-json" id="payloadOutput" readonly></textarea>
  </section>
</main>

<div class="inv-modal" id="clientModal" aria-hidden="true">
  <div class="inv-sheet" role="dialog" aria-modal="true" aria-labelledby="addClientTitle">
    <div class="inv-sheet-head">
      <h2 id="addClientTitle">Add client</h2>
      <button class="inv-close" type="button" id="closeClientModal" aria-label="Close">×</button>
    </div>
    <label for="newClientName"><strong>Client name</strong></label>
    <input class="inv-field" id="newClientName" type="text" maxlength="100">
    <div class="inv-mobile-label">
      <label for="newClientMobile"><strong>Mobile</strong></label>
      <span>🌐 <select class="inv-country" id="newClientCountry" aria-label="Country">
        <option value="1" data-lengths="8,9,10,11,12,13">India (+91)</option>
      </select></span>
    </div>
    <input class="inv-field" id="newClientMobile" type="tel" inputmode="numeric">
    <label for="newClientGender"><strong>Gender</strong></label>
    <select class="inv-field" id="newClientGender">
      <option value="male">Male</option><option value="female">Female</option><option value="other">Other</option>
    </select>
    <label for="newClientSource"><strong>Source</strong></label>
    <select class="inv-field" id="newClientSource"><option value="">Loading…</option></select>
    <p class="inv-error" id="newClientMessage"></p>
    <div class="inv-actions">
      <button class="inv-btn inv-btn--ghost" type="button" id="cancelClient">Cancel</button>
      <button class="inv-btn" type="button" id="saveClient">Save client</button>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var root = document.getElementById('invoiceApp');
  var csrf = root.getAttribute('data-csrf') || '';
  var today = root.getAttribute('data-today') || '';
  var el = function (id) { return document.getElementById(id); };
  var state = {
    branchId: 0, date: today, client: null, staff: null, duration: 0,
    service: null, room: null, staffList: [], roomList: [], paymentModes: [],
    payments: [], settings: {}, serviceTax: null, invoiceSetting: null,
    setupLoaded: false
  };

  function api(action, payload) {
    return fetch('invoice_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      credentials: 'same-origin',
      body: JSON.stringify(Object.assign({
        action: action, _csrf: csrf, branch_id: state.branchId
      }, payload || {}))
    }).then(function (response) { return response.json(); });
  }

  function money(value) {
    return '₹' + (Math.round((Number(value) || 0) * 100) / 100).toLocaleString('en-IN', {
      minimumFractionDigits: 0, maximumFractionDigits: 2
    });
  }
  function round2(value) {
    // Standard half-up to 2 decimals (59.525 → 59.53)
    var number = Number(value);
    if (!isFinite(number)) return 0;
    return Number(Math.round(Number(number + 'e+2')) + 'e-2');
  }
  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function show(id, visible) { el(id).hidden = !visible; }
  function message(text, ok) {
    el('invoiceMessage').textContent = text || '';
    el('invoiceMessage').className = 'inv-error' + (ok ? ' inv-ok' : '');
  }
  function summaryRow(step, label, value) {
    return '<div class="inv-compact-row"><div class="inv-compact-text"><strong>' + esc(label) +
      '</strong><span class="inv-compact-value">' + esc(value) +
      '</span></div><button class="inv-edit" type="button" data-edit-step="' + step + '">Edit</button></div>';
  }
  function renderSelectionSummary() {
    var rows = [];
    if (state.branchId > 0) {
      var branchOption = el('invBranch').options[el('invBranch').selectedIndex];
      rows.push(summaryRow(1, 'Branch & date', (branchOption ? branchOption.textContent : '') + ' · ' + state.date));
    }
    if (state.client) rows.push(summaryRow(2, 'Client', state.client.name + (state.client.mobile_masked ? ' (' + state.client.mobile_masked + ')' : '')));
    if (state.staff) rows.push(summaryRow(3, 'Staff', state.staff.name));
    if (state.duration) rows.push(summaryRow(4, 'Service time', state.duration + ' mins'));
    if (state.service) rows.push(summaryRow(5, 'Service', state.service.name + ' · ' + money(state.service.price)));
    if (state.room) rows.push(summaryRow(6, 'Room', state.room.name));
    if (state.room && state.service) {
      var currentTotals = totals();
      rows.push(summaryRow(7, 'Offer / payable', money(currentTotals.offer) + ' / ' + money(currentTotals.payable)));
    }
    if (state.payments.length) {
      rows.push(summaryRow(8, 'Payments', state.payments.map(function (payment) {
        return payment.name + ' ' + money(payment.amount);
      }).join(' + ')));
    }
    el('selectionSummaryRows').innerHTML = rows.join('');
    show('selectionSummary', rows.length > 0);
    document.querySelectorAll('[data-edit-step]').forEach(function (button) {
      button.addEventListener('click', function () {
        openEditStep(parseInt(button.getAttribute('data-edit-step'), 10) || 1);
      });
    });
  }
  function openEditStep(step) {
    var cardId = {
      1: null, 2: 'clientCard', 3: 'staffCard', 4: 'durationCard',
      5: 'serviceCard', 6: 'roomCard', 7: 'priceCard', 8: 'paymentCard'
    }[step];
    if (step === 1) {
      el('invBranch').focus();
      el('invBranch').scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    if (!cardId) return;
    show(cardId, true);
    if (step === 5) renderServices();
    el(cardId).scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function resetAfterBranch() {
    state.client = null; state.staff = null; state.duration = 0; state.service = null;
    state.room = null; state.staffList = []; state.roomList = []; state.paymentModes = [];
    state.payments = []; state.setupLoaded = false; state.settings = {}; state.serviceTax = null;
    ['clientCard','staffCard','durationCard','serviceCard','roomCard','priceCard','paymentCard','payloadCard']
      .forEach(function (id) { show(id, false); });
    el('clientSearch').value = '';
    el('clientResults').innerHTML = '';
    el('selectedClient').innerHTML = '';
    message('');
    renderSelectionSummary();
  }

  function loadBranchData() {
    show('clientCard', true);
    Promise.all([api('staff'), api('rooms'), api('setup')]).then(function (responses) {
      var staffRes = responses[0], roomRes = responses[1], setupRes = responses[2];
      if (!staffRes.ok || !roomRes.ok || !setupRes.ok) {
        message(staffRes.error || roomRes.error || setupRes.error || 'Could not load invoice data.');
        return;
      }
      state.staffList = staffRes.staff || [];
      state.roomList = roomRes.rooms || [];
      state.paymentModes = sortPaymentModes(setupRes.payment_modes || []);
      state.settings = setupRes.settings || {};
      state.serviceTax = setupRes.service_tax || null;
      state.invoiceSetting = setupRes.invoice_setting || null;
      state.setupLoaded = true;
      fillStaff();
      fillRooms();
      fillPaymentModes();
    }).catch(function () { message('Could not load invoice data.'); });
  }

  function sortPaymentModes(modes) {
    var priority = ['upi', 'gpay', 'google pay', 'cash', 'card'];
    return modes.slice().sort(function (a, b) {
      var an = String(a.name || '').toLowerCase();
      var bn = String(b.name || '').toLowerCase();
      function rank(name) {
        for (var i = 0; i < priority.length; i++) {
          if (name.indexOf(priority[i]) !== -1) return i;
        }
        return 99;
      }
      return rank(an) - rank(bn) || an.localeCompare(bn);
    });
  }

  function fillStaff() {
    el('staffSelect').innerHTML = '<option value="">Select staff</option>';
    state.staffList.forEach(function (staff) {
      var option = document.createElement('option');
      option.value = String(staff.id); option.textContent = staff.name;
      el('staffSelect').appendChild(option);
    });
  }
  function fillRooms() {
    el('roomSelect').innerHTML = '<option value="">Select room</option>';
    state.roomList.forEach(function (room) {
      var option = document.createElement('option');
      option.value = String(room.id); option.textContent = room.name;
      el('roomSelect').appendChild(option);
    });
  }
  function fillPaymentModes() {
    el('paymentMode').innerHTML = '<option value="">Select payment mode</option>';
    state.paymentModes.forEach(function (mode) {
      var option = document.createElement('option');
      option.value = String(mode.id); option.textContent = mode.name;
      el('paymentMode').appendChild(option);
    });
  }

  var clientTimer = null;
  el('clientSearch').addEventListener('input', function () {
    var search = el('clientSearch').value.trim();
    state.client = null;
    el('selectedClient').innerHTML = '';
    el('addClientBtn').hidden = search.length < 3;
    show('staffCard', false);
    clearTimeout(clientTimer);
    if (search.length < 3) { el('clientResults').innerHTML = ''; return; }
    el('clientResults').innerHTML = '<p class="inv-help">Searching…</p>';
    clientTimer = setTimeout(function () {
      api('client_search', { search: search }).then(function (res) {
        if (!res || !res.ok) {
          el('clientResults').innerHTML = '<p class="inv-error">' + esc((res && res.error) || 'Could not search clients.') + '</p>';
          return;
        }
        renderClients(res.clients || []);
      }).catch(function () {
        el('clientResults').innerHTML = '<p class="inv-error">Could not search clients.</p>';
      });
    }, 300);
  });

  function renderClients(clients) {
    if (!clients.length) {
      el('clientResults').innerHTML = '<p class="inv-help">No client found. Use Add client.</p>';
      return;
    }
    var html = '<div class="inv-results">';
    clients.forEach(function (client, index) {
      html += '<button class="inv-result" type="button" data-client-index="' + index + '">' + esc(client.label) + '</button>';
    });
    el('clientResults').innerHTML = html + '</div>';
    document.querySelectorAll('[data-client-index]').forEach(function (button) {
      button.addEventListener('click', function () {
        selectClient(clients[parseInt(button.getAttribute('data-client-index'), 10)]);
      });
    });
  }
  function selectClient(client) {
    state.client = client;
    el('clientResults').innerHTML = '';
    el('selectedClient').innerHTML = '<div class="inv-selected"><span><strong>' + esc(client.name) +
      '</strong>' + esc(client.mobile_masked || '') + '</span><button class="inv-btn inv-btn--ghost" type="button" id="changeClient">Change</button></div>';
    el('changeClient').addEventListener('click', function () {
      state.client = null; state.staff = null; state.duration = 0; state.service = null;
      state.room = null; state.payments = [];
      el('selectedClient').innerHTML = '';
      ['staffCard','durationCard','serviceCard','roomCard','priceCard','paymentCard','payloadCard']
        .forEach(function (card) { show(card, false); });
      show('clientCard', true);
      el('clientSearch').focus();
      renderSelectionSummary();
    });
    show('clientCard', false);
    show('staffCard', true);
    renderSelectionSummary();
  }

  el('staffSelect').addEventListener('change', function () {
    var id = parseInt(el('staffSelect').value, 10) || 0;
    state.staff = state.staffList.find(function (staff) { return staff.id === id; }) || null;
    state.duration = 0; state.service = null; state.room = null; state.payments = [];
    show('durationCard', !!state.staff);
    ['serviceCard','roomCard','priceCard','paymentCard','payloadCard'].forEach(function (card) { show(card, false); });
    document.querySelectorAll('[data-minutes]').forEach(function (button) { button.classList.remove('is-selected'); });
    if (state.staff) show('staffCard', false);
    renderSelectionSummary();
  });

  document.querySelectorAll('[data-minutes]').forEach(function (button) {
    button.addEventListener('click', function () {
      state.duration = parseInt(button.getAttribute('data-minutes'), 10) || 0;
      state.service = null; state.room = null; state.payments = [];
      document.querySelectorAll('[data-minutes]').forEach(function (item) {
        item.classList.toggle('is-selected', item === button);
      });
      show('serviceCard', true);
      show('durationCard', false);
      ['roomCard','priceCard','paymentCard','payloadCard'].forEach(function (card) { show(card, false); });
      el('serviceSearch').value = '';
      renderServices();
      renderSelectionSummary();
    });
  });

  el('serviceSearch').addEventListener('input', renderServices);
  function renderServices() {
    if (!state.staff || !state.duration) return;
    var query = el('serviceSearch').value.trim().toLowerCase();
    var matches = (state.staff.services || []).filter(function (service) {
      return Number(service.minutes) === state.duration
        && (!query || String(service.name).toLowerCase().indexOf(query) !== -1);
    });
    matches.sort(function (a, b) {
      var priority = ['deep tissue', 'swedish', 'balinese'];
      function rank(name) {
        name = String(name).toLowerCase();
        for (var i = 0; i < priority.length; i++) if (name.indexOf(priority[i]) !== -1) return i;
        return 99;
      }
      return rank(a.name) - rank(b.name) || a.name.localeCompare(b.name);
    });
    if (!matches.length) {
      el('serviceResults').innerHTML = '<p class="inv-help">No matching ' + state.duration + '-minute service.</p>';
      return;
    }
    var html = '<div class="inv-results">';
    matches.slice(0, 30).forEach(function (service, index) {
      html += '<button class="inv-result" type="button" data-service-index="' + index + '">' +
        esc(service.name) + '<br><small>' + money(service.price) + '</small></button>';
    });
    el('serviceResults').innerHTML = html + '</div>';
    document.querySelectorAll('[data-service-index]').forEach(function (button) {
      button.addEventListener('click', function () {
        selectService(matches[parseInt(button.getAttribute('data-service-index'), 10)]);
      });
    });
  }
  function selectService(service) {
    state.service = service; state.room = null; state.payments = [];
    el('serviceResults').innerHTML = '';
    el('selectedService').innerHTML = '<div class="inv-selected"><span><strong>' + esc(service.name) +
      '</strong>' + money(service.price) + '</span></div>';
    show('serviceCard', false);
    show('roomCard', true);
    fillRooms();
    ['priceCard','paymentCard','payloadCard'].forEach(function (card) { show(card, false); });
    renderSelectionSummary();
  }

  el('roomSelect').addEventListener('change', function () {
    var id = parseInt(el('roomSelect').value, 10) || 0;
    state.room = state.roomList.find(function (room) { return room.id === id; }) || null;
    state.payments = [];
    show('priceCard', !!state.room);
    show('paymentCard', !!state.room);
    show('payloadCard', false);
    if (state.room && state.service) {
      el('servicePrice').textContent = money(state.service.price);
      el('offerPrice').max = String(state.service.price);
      el('offerPrice').value = String(round2(state.service.price));
      updateTotals();
      show('roomCard', false);
    }
    renderSelectionSummary();
  });

  function taxParts() {
    if (!state.settings.apply_gst || !state.serviceTax) return [];
    var groups = Array.isArray(state.serviceTax.tax_groupings) ? state.serviceTax.tax_groupings : [];
    var parts = groups.map(function (group) {
      var tax = group && group.tax ? group.tax : {};
      return { name: tax.name || 'Tax', percentage: Number(tax.percentage) || 0 };
    }).filter(function (part) { return part.percentage > 0; });
    if (!parts.length && Number(state.serviceTax.percentage) > 0) {
      parts.push({ name: state.serviceTax.name || 'Tax', percentage: Number(state.serviceTax.percentage) });
    }
    return parts;
  }
  function totals() {
    var price = round2(state.service ? state.service.price : 0);
    var offer = round2(el('offerPrice').value);
    offer = Math.max(0, Math.min(price, offer));
    var discount = round2(price - offer);
    var parts = taxParts();
    var rate = parts.reduce(function (sum, part) { return sum + part.percentage; }, 0);
    var inclusive = !!state.settings.s_tax_inclusive;
    var taxable = offer;
    var tax = 0;
    var taxRows = [];
    if (rate > 0 && inclusive) {
      // Inclusive: net first, then tax = total - net (e.g. 2500 → 2380.95 + 119.05)
      taxable = round2(offer * 100 / (100 + rate));
      tax = round2(offer - taxable);
    }
    parts.forEach(function (part) {
      // Same formula for every tax line so equal % get equal rounded amounts
      // e.g. 119.05 × 2.5 / 5 = 59.525 → 59.53 for both CGST and SGST
      var amount = (rate > 0 && inclusive)
        ? round2(tax * part.percentage / rate)
        : round2(taxable * part.percentage / 100);
      taxRows.push({
        name: part.name,
        percentage: part.percentage,
        taxable: taxable,
        amount: amount
      });
    });
    if (!(rate > 0 && inclusive)) {
      tax = round2(taxRows.reduce(function (sum, row) { return sum + row.amount; }, 0));
    }
    var payable = inclusive ? offer : round2(offer + tax);
    return {
      price: price,
      offer: offer,
      discount: discount,
      taxable: taxable,
      tax: tax,
      payable: payable,
      taxRows: taxRows
    };
  }
  function updateTotals() {
    if (!state.service) return;
    var value = Number(el('offerPrice').value);
    if (value > Number(state.service.price)) el('offerPrice').value = String(state.service.price);
    if (value < 0) el('offerPrice').value = '0';
    var result = totals();
    el('discountAmount').textContent = money(result.discount);
    el('taxAmount').textContent = money(result.tax);
    el('payableAmount').textContent = money(result.payable);
    state.payments = [];
    renderPayments();
    renderSelectionSummary();
  }
  el('offerPrice').addEventListener('input', updateTotals);

  function paidTotal() {
    return round2(state.payments.reduce(function (sum, payment) { return sum + payment.amount; }, 0));
  }
  function remaining() { return round2(Math.max(0, totals().payable - paidTotal())); }
  function renderPayments() {
    var html = '';
    state.payments.forEach(function (payment, index) {
      html += '<div class="inv-payment"><span><strong>' + esc(payment.name) + '</strong><br>' +
        money(payment.amount) + '</span><button class="inv-btn inv-btn--ghost" type="button" data-remove-payment="' +
        index + '">Remove</button></div>';
    });
    el('paymentList').innerHTML = html;
    document.querySelectorAll('[data-remove-payment]').forEach(function (button) {
      button.addEventListener('click', function () {
        state.payments.splice(parseInt(button.getAttribute('data-remove-payment'), 10), 1);
        renderPayments();
      });
    });
    var left = remaining();
    el('paidAmount').textContent = money(paidTotal());
    el('remainingAmount').textContent = money(left);
    el('paymentAmount').value = left > 0 ? String(left) : '';
    el('paymentAmount').max = String(left);
    el('addPayment').disabled = left <= 0;
    el('checkoutBtn').disabled = left > 0.009 || !state.payments.length;
    renderSelectionSummary();
  }

  el('addPayment').addEventListener('click', function () {
    var modeId = parseInt(el('paymentMode').value, 10) || 0;
    var mode = state.paymentModes.find(function (item) { return item.id === modeId; });
    var amount = round2(el('paymentAmount').value);
    var left = remaining();
    if (!mode) { message('Select a payment mode.'); return; }
    if (amount <= 0 || amount > left + 0.009) {
      message('Payment amount must be greater than zero and not more than ' + money(left) + '.');
      return;
    }
    state.payments.push({ id: mode.id, name: mode.name, amount: amount });
    el('paymentMode').value = '';
    message('');
    renderPayments();
  });

  el('checkoutBtn').addEventListener('click', function () {
    if (remaining() > 0.009) { message('Complete the full payment before checkout.'); return; }
    el('checkoutBtn').disabled = true;
    el('checkoutBtn').textContent = 'Preparing…';
    api('bill_number', { date: state.date, user_id: state.client.id }).then(function (res) {
      el('checkoutBtn').textContent = 'Checkout';
      el('checkoutBtn').disabled = false;
      if (!res.ok) { message(res.error || 'Could not get latest invoice number.'); return; }
      var payload = buildPayload(res);
      el('invoiceNumber').value = res.next_invoice_number || '';
      el('payloadOutput').value = JSON.stringify(payload, null, 2);
      show('payloadCard', true);
      message('Payload generated successfully.', true);
      el('payloadCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }).catch(function () {
      el('checkoutBtn').textContent = 'Checkout';
      el('checkoutBtn').disabled = false;
      message('Could not generate invoice payload.');
    });
  });

  function buildPayload(billData) {
    var result = totals();
    var taxArr = result.taxRows.map(function (tax) {
      var taxName = '<span class="text-uppercase">' + tax.name +
        '</span> (<span style="font-size:small"><span>' + tax.percentage + '%</span></span>)';
      return {
        type: 'service', name: tax.name, percentage: tax.percentage, amount: tax.amount,
        taxable_amount: tax.taxable, tax_name: taxName,
        tax_item: {
          name: tax.name, percent: tax.percentage, taxable_amount: tax.taxable,
          tax_amount: tax.amount, type: 'service'
        }
      };
    });
    var payments = state.payments.map(function (payment) {
      return {
        payment_mode: payment.id, amount: payment.amount, redemption: false,
        note: 'by' + String(payment.name).replace(/\s+/g, '') + payment.amount
      };
    });
    var pModes = state.payments.map(function (payment) {
      return { payment_mode: payment.id, amount: payment.amount };
    });
    var service = {
      employee_id: state.staff.id, service_id: state.service.id, qty: 1,
      name: state.service.name, service_time: String(state.duration),
      discount_type: 'custom', discount_id: '', redeem: 0, p_modes: pModes,
      price: result.price, tax: result.tax, total: result.payable, net: result.taxable,
      paid: result.payable, taxable_amount: result.taxable, is_tax_on_redeem: false,
      is_tax_inclusive: !!state.settings.s_tax_inclusive, discount: result.discount
    };
    return {
      user_id: state.client.id, price: result.price, tax: result.tax, net: result.taxable,
      total: result.payable, selected_date: state.date, paid: result.payable, balance: 0,
      tax_arr: taxArr, payments: payments, package_redemptions: [], tip: 0, wallet: 0,
      roundoff: 0, payment_status: 'is_paid', tips: [], additional_charges: [],
      send_sms: true, send_email: true, desc: 'A1 Invoice', mobile: billData.mobile || '',
      service_names: state.service.name + ' (₹' + String(result.price) + ')',
      services: [service], discount: result.discount, is_product_only: null,
      daily_register_id: null, invoice_otp: null, is_same_state: true,
      is_redemption_invoice: false, applyTax: !!state.settings.apply_gst,
      isCouponAppliedBySelect: false, offer_discount_desc: '',
      taxable_amount: result.taxable, is_tax_on_redeem: false,
      applyTaxSettingChangedFromInvoice: false, inv_prefix: billData.inv_prefix
    };
  }

  el('invBranch').addEventListener('change', function () {
    state.branchId = parseInt(el('invBranch').value, 10) || 0;
    resetAfterBranch();
    if (state.branchId > 0) loadBranchData();
    renderSelectionSummary();
  });
  el('invDate').addEventListener('change', function () {
    state.date = el('invDate').value || today;
    show('payloadCard', false);
    renderSelectionSummary();
  });

  var modal = el('clientModal');
  function closeClientModal() { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); }
  el('addClientBtn').addEventListener('click', function () {
    var search = el('clientSearch').value.trim();
    var digits = search.replace(/\D+/g, '');
    el('newClientName').value = digits.length === search.length ? '' : search;
    el('newClientMobile').value = digits.length === search.length ? digits : '';
    el('newClientGender').value = 'male';
    el('newClientMessage').textContent = '';
    modal.classList.add('is-open'); modal.setAttribute('aria-hidden', 'false');
    Promise.all([api('sources'), api('countries')]).then(function (responses) {
      fillSources(responses[0]); fillCountries(responses[1]);
    });
  });
  el('closeClientModal').addEventListener('click', closeClientModal);
  el('cancelClient').addEventListener('click', closeClientModal);
  modal.addEventListener('click', function (event) { if (event.target === modal) closeClientModal(); });
  function fillSources(res) {
    el('newClientSource').innerHTML = '<option value="">Select source</option>';
    (res.sources || []).forEach(function (source) {
      var option = document.createElement('option');
      option.value = String(source.id); option.textContent = source.name;
      if (String(source.name).toLowerCase() === 'walk-in') option.selected = true;
      el('newClientSource').appendChild(option);
    });
  }
  function fillCountries(res) {
    if (!res.ok || !(res.countries || []).length) return;
    el('newClientCountry').innerHTML = '';
    res.countries.forEach(function (country) {
      var option = document.createElement('option');
      option.value = String(country.id);
      option.textContent = country.name + ' (+' + country.dial_code + ')';
      option.setAttribute('data-lengths', (country.lengths || []).join(','));
      if (Number(country.id) === 1 || country.code === 'IN') option.selected = true;
      el('newClientCountry').appendChild(option);
    });
  }
  el('saveClient').addEventListener('click', function () {
    var name = el('newClientName').value.trim();
    var mobile = el('newClientMobile').value.replace(/\D+/g, '');
    var country = el('newClientCountry');
    var selected = country.options[country.selectedIndex];
    var lengths = selected ? String(selected.getAttribute('data-lengths') || '').split(',').map(Number) : [];
    if (name.length < 2) { el('newClientMessage').textContent = 'Enter client name.'; return; }
    if (mobile.length < 4 || (lengths.length && lengths.indexOf(mobile.length) === -1)) {
      el('newClientMessage').textContent = 'Enter a valid mobile number.'; return;
    }
    el('saveClient').disabled = true;
    api('client_create', {
      name: name, mobile: mobile, gender: el('newClientGender').value,
      country_id: parseInt(country.value, 10) || 1,
      source_id: parseInt(el('newClientSource').value, 10) || 0
    }).then(function (res) {
      el('saveClient').disabled = false;
      if (!res.ok) { el('newClientMessage').textContent = res.error || 'Could not add client.'; return; }
      closeClientModal(); selectClient(res.client);
    }).catch(function () {
      el('saveClient').disabled = false; el('newClientMessage').textContent = 'Could not add client.';
    });
  });
})();
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
