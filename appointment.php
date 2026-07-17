<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
if (!can_access_appointments($user)) {
    http_response_code(403);
    exit('Forbidden');
}

$roleId = (int) ($user['role_id'] ?? 0);
$canSelectBranch = in_array($roleId, [ROLE_SUPERADMIN, ROLE_ADMIN], true);
$appointmentBranches = [];
$selectedBranchId = $canSelectBranch ? 0 : (int) ($user['branch_id'] ?? 0);
if ($canSelectBranch) {
    try {
        $branchStmt = db()->query(
            'SELECT id, business_name, locality
             FROM allureone_branch
             WHERE isActive = 1
             ORDER BY business_name ASC, locality ASC'
        );
        while ($branch = $branchStmt->fetch(PDO::FETCH_ASSOC)) {
            $branchId = (int) ($branch['id'] ?? 0);
            if ($branchId <= 0) {
                continue;
            }
            $name = trim((string) ($branch['business_name'] ?? ''));
            $locality = trim((string) ($branch['locality'] ?? ''));
            $appointmentBranches[] = [
                'id' => $branchId,
                'label' => $name !== '' && $locality !== ''
                    ? $name . ' — ' . $locality
                    : ($name !== '' ? $name : ($locality !== '' ? $locality : 'Branch ' . $branchId)),
            ];
        }
    } catch (Throwable $e) {
        error_log('Appointment branch list failed: ' . $e->getMessage());
        $appointmentBranches = [];
        $selectedBranchId = 0;
    }
}

$todayIst = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$pageTitle = 'Appointments';
$activeNav = 'appointments';
require __DIR__ . '/includes/layout_start.php';
?>

<style>
.appt {
  max-width: 520px;
  margin: 0 auto;
  padding: 0 0.25rem 5rem;
}
.appt__title {
  font-size: 1.45rem;
  font-weight: 700;
  margin: 0 0 0.85rem;
  letter-spacing: 0.01em;
}
.appt__bar {
  display: flex;
  gap: 0.65rem;
  align-items: stretch;
  margin-bottom: 0.9rem;
}
.appt__branch-wrap {
  margin-bottom: 0.75rem;
}
.appt__branch-label {
  display: block;
  margin: 0 0 0.35rem;
  color: #12263a;
  font-size: 0.95rem;
  font-weight: 700;
}
.appt__branch {
  width: 100%;
  min-height: 48px;
  padding: 0.6rem 0.75rem;
  border: 2px solid #c9d2dc;
  border-radius: 12px;
  background: #fff;
  font-size: 1rem;
}
.appt__date {
  flex: 1;
  min-height: 52px;
  font-size: 1.1rem;
  padding: 0.65rem 0.75rem;
  border: 2px solid #c9d2dc;
  border-radius: 12px;
  background: #fff;
}
.appt__book-btn {
  min-height: 52px;
  min-width: 52%;
  font-size: 1.15rem;
  font-weight: 700;
  border: 0;
  border-radius: 12px;
  background: #1f7a4d;
  color: #fff;
  padding: 0.7rem 1rem;
  cursor: pointer;
}
.appt__book-btn:active { transform: scale(0.98); }
.appt__status {
  min-height: 1.25rem;
  margin: 0 0 0.75rem;
  font-size: 0.98rem;
}
.appt__status.is-error { color: #b42318; font-weight: 600; }
.appt__status.is-ok { color: #1f7a4d; font-weight: 600; }
.appt__list { display: flex; flex-direction: column; gap: 0.75rem; }
.appt-card {
  border: 2px solid #d7dee7;
  border-radius: 14px;
  background: #fff;
  padding: 0.95rem 1rem;
  text-align: left;
  width: 100%;
  cursor: pointer;
  box-shadow: 0 1px 0 rgba(16,24,40,0.04);
}
.appt-card:disabled {
  opacity: 0.72;
  cursor: default;
}
.appt-card__time {
  font-size: 1.2rem;
  font-weight: 800;
  margin: 0 0 0.35rem;
  color: #12263a;
}
.appt-card__name {
  font-size: 1.12rem;
  font-weight: 700;
  margin: 0 0 0.2rem;
}
.appt-card__meta {
  font-size: 0.98rem;
  color: #425466;
  margin: 0.15rem 0;
  line-height: 1.35;
}
.appt-card__edit {
  display: inline-block;
  margin-top: 0.55rem;
  font-size: 0.95rem;
  font-weight: 700;
  color: #175cd3;
}
.appt__empty {
  padding: 1.5rem 1rem;
  text-align: center;
  color: #667085;
  font-size: 1.05rem;
  border: 2px dashed #d0d5dd;
  border-radius: 14px;
  background: #f8fafc;
}
.appt-modal {
  position: fixed;
  inset: 0;
  z-index: 80;
  background: rgba(15, 23, 42, 0.45);
  display: none;
  align-items: flex-end;
  justify-content: center;
  padding: 0;
}
.appt-modal.is-open { display: flex; }
.appt-modal--client { z-index: 100; }
.appt-sheet {
  width: 100%;
  max-width: 560px;
  max-height: 94vh;
  overflow: auto;
  background: #fff;
  border-radius: 18px 18px 0 0;
  padding: 1rem 1rem 1.25rem;
  box-shadow: 0 -8px 30px rgba(0,0,0,0.18);
}
.appt-sheet__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}
.appt-sheet__head h2 {
  margin: 0;
  font-size: 1.3rem;
}
.appt-sheet__close {
  border: 0;
  background: #eef2f6;
  width: 44px;
  height: 44px;
  border-radius: 999px;
  font-size: 1.4rem;
  cursor: pointer;
}
.appt-step-label {
  font-size: 1.05rem;
  font-weight: 700;
  margin: 0 0 0.55rem;
  color: #12263a;
}
.appt-step-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.65rem;
  margin-bottom: 0.55rem;
}
.appt-step-head .appt-step-label { margin: 0; }
.appt-mobile-label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.55rem;
}
.appt-mobile-label .appt-step-label { margin: 0; }
.appt-country-picker {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  color: #425466;
}
.appt-country-picker select {
  max-width: 175px;
  min-height: 34px;
  padding: 0.25rem 0.4rem;
  border: 1px solid #c9d2dc;
  border-radius: 8px;
  background: #fff;
  color: #12263a;
  font-size: 0.88rem;
}
.appt-add-client-btn {
  flex: 0 0 auto;
  min-height: 40px;
  border: 0;
  border-radius: 9px;
  padding: 0.45rem 0.75rem;
  background: #175cd3;
  color: #fff;
  font-size: 0.92rem;
  font-weight: 700;
  cursor: pointer;
}
.appt-add-client-btn[hidden] { display: none; }
.appt-help {
  margin: 0 0 0.75rem;
  color: #667085;
  font-size: 0.95rem;
}
.appt-field {
  width: 100%;
  min-height: 52px;
  font-size: 1.1rem;
  padding: 0.7rem 0.8rem;
  border: 2px solid #c9d2dc;
  border-radius: 12px;
  margin-bottom: 0.65rem;
  box-sizing: border-box;
}
.appt-autocomplete {
  position: relative;
}
.appt-results {
  max-height: 260px;
  overflow-y: auto;
  border: 2px solid #d0d5dd;
  border-radius: 12px;
  background: #fff;
  margin: -0.35rem 0 0.75rem;
}
.appt-result {
  display: block;
  width: 100%;
  min-height: 52px;
  padding: 0.7rem 0.8rem;
  border: 0;
  border-bottom: 1px solid #e4e7ec;
  background: #fff;
  text-align: left;
  font-size: 1rem;
  cursor: pointer;
}
.appt-result:last-child { border-bottom: 0; }
.appt-result:active { background: #ecfdf3; }
.appt-result__sub {
  display: block;
  margin-top: 0.15rem;
  color: #667085;
  font-size: 0.9rem;
}
.appt-choice {
  display: block;
  width: 100%;
  text-align: left;
  min-height: 56px;
  border: 2px solid #d0d5dd;
  border-radius: 12px;
  background: #fff;
  padding: 0.8rem 0.9rem;
  margin-bottom: 0.55rem;
  font-size: 1.05rem;
  cursor: pointer;
}
.appt-choice.is-selected {
  border-color: #1f7a4d;
  background: #ecfdf3;
  font-weight: 700;
}
.appt-choice__sub {
  display: block;
  color: #667085;
  font-size: 0.92rem;
  font-weight: 500;
  margin-top: 0.15rem;
}
.appt-actions {
  display: flex;
  gap: 0.6rem;
  margin-top: 0.85rem;
  position: sticky;
  bottom: 0;
  background: #fff;
  padding-top: 0.65rem;
}
.appt-actions .btn {
  flex: 1;
  min-height: 52px;
  font-size: 1.08rem;
  font-weight: 700;
  border-radius: 12px;
}
.appt-summary {
  background: #f8fafc;
  border: 2px solid #e4e7ec;
  border-radius: 12px;
  padding: 0.85rem 0.95rem;
  margin-bottom: 0.75rem;
  font-size: 1.02rem;
  line-height: 1.45;
}
.appt-summary strong { font-weight: 800; }
.appt-selected {
  border: 2px solid #dbe5ef;
  border-radius: 12px;
  background: #f8fafc;
  margin-bottom: 0.9rem;
  overflow: hidden;
}
.appt-selected__row {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.55rem 0.7rem;
  border-bottom: 1px solid #e4e7ec;
}
.appt-selected__row:last-child { border-bottom: 0; }
.appt-selected__text {
  flex: 1;
  min-width: 0;
  font-size: 0.92rem;
  line-height: 1.3;
}
.appt-selected__text strong {
  display: block;
  color: #12263a;
  font-size: 0.82rem;
}
.appt-selected__value {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.appt-selected__edit {
  flex: 0 0 auto;
  border: 1px solid #98a2b3;
  border-radius: 8px;
  background: #fff;
  color: #175cd3;
  min-height: 36px;
  padding: 0.35rem 0.65rem;
  font-weight: 700;
  cursor: pointer;
}
.appt-slots {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.45rem;
  margin-bottom: 0.5rem;
}
#wizDurationChoices {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.appt-slot {
  min-height: 48px;
  border: 2px solid #d0d5dd;
  border-radius: 10px;
  background: #fff;
  font-size: 0.98rem;
  font-weight: 700;
  cursor: pointer;
}
.appt-slot.is-selected {
  border-color: #1f7a4d;
  background: #ecfdf3;
}
@keyframes apptAttentionBlink {
  0%, 100% { border-color: #c9d2dc; box-shadow: none; }
  25%, 75% { border-color: #f59e0b; box-shadow: 0 0 0 5px rgba(245, 158, 11, 0.28); }
  50% { border-color: #1f7a4d; box-shadow: 0 0 0 5px rgba(31, 122, 77, 0.22); }
}
.appt-attention {
  animation: apptAttentionBlink 1s ease-in-out;
}
@media (min-width: 700px) {
  .appt-modal { align-items: center; padding: 1rem; }
  .appt-sheet { border-radius: 18px; max-height: 90vh; }
}
</style>

<div class="appt" id="apptApp"
     data-csrf="<?= e(csrf_token()) ?>"
     data-today="<?= e($todayIst) ?>"
     data-branch-id="<?= (int) $selectedBranchId ?>">
  <h1 class="appt__title">Appointments</h1>
  <?php if ($canSelectBranch): ?>
    <div class="appt__branch-wrap">
      <label class="appt__branch-label" for="apptBranch">Branch</label>
      <select class="appt__branch" id="apptBranch"<?= $appointmentBranches === [] ? ' disabled' : '' ?>>
        <?php if ($appointmentBranches === []): ?>
          <option value="">No active branches found</option>
        <?php else: ?>
          <option value="" selected>Select branch</option>
          <?php foreach ($appointmentBranches as $branch): ?>
            <option value="<?= (int) $branch['id'] ?>">
              <?= e((string) $branch['label']) ?>
            </option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>
    </div>
  <?php endif; ?>
  <div class="appt__bar">
    <input class="appt__date" type="date" id="apptDate" value="<?= e($todayIst) ?>" aria-label="Date">
    <button type="button" class="appt__book-btn" id="apptNewBtn"<?= $canSelectBranch ? ' disabled' : '' ?>>+ Book</button>
  </div>
  <p class="appt__status" id="apptStatus" aria-live="polite"></p>
  <div class="appt__list" id="apptList">
    <div class="appt__empty">Loading…</div>
  </div>
</div>

<div class="appt-modal" id="apptModal" aria-hidden="true">
  <div class="appt-sheet" role="dialog" aria-modal="true" aria-labelledby="apptSheetTitle">
    <div class="appt-sheet__head">
      <h2 id="apptSheetTitle">Book appointment</h2>
      <button type="button" class="appt-sheet__close" id="apptCloseBtn" aria-label="Close">×</button>
    </div>
    <div id="apptWizard"></div>
  </div>
</div>

<div class="appt-modal appt-modal--client" id="addClientModal" aria-hidden="true">
  <div class="appt-sheet" role="dialog" aria-modal="true" aria-labelledby="addClientTitle">
    <div class="appt-sheet__head">
      <h2 id="addClientTitle">Add client</h2>
      <button type="button" class="appt-sheet__close" id="addClientCloseBtn" aria-label="Close">×</button>
    </div>
    <label class="appt-step-label" for="addClientName">Client name</label>
    <input class="appt-field" id="addClientName" type="text" maxlength="100" autocomplete="name" placeholder="Enter client name">
    <div class="appt-mobile-label">
      <label class="appt-step-label" for="addClientMobile">Mobile</label>
      <span class="appt-country-picker">
        <span aria-hidden="true">🌐</span>
        <select id="addClientCountry" aria-label="Country">
          <option value="1" data-dial-code="91" data-lengths="8,9,10,11,12,13" selected>India (+91)</option>
        </select>
      </span>
    </div>
    <input class="appt-field" id="addClientMobile" type="tel" inputmode="numeric" maxlength="13" autocomplete="tel-national" placeholder="Enter mobile number">
    <label class="appt-step-label" for="addClientGender">Gender</label>
    <select class="appt-field" id="addClientGender">
      <option value="male" selected>Male</option>
      <option value="female">Female</option>
      <option value="other">Other</option>
    </select>
    <label class="appt-step-label" for="addClientSource">Source</label>
    <select class="appt-field" id="addClientSource">
      <option value="">Loading sources…</option>
    </select>
    <p class="appt-help" id="addClientMessage" aria-live="polite"></p>
    <div class="appt-actions">
      <button type="button" class="btn btn--ghost" id="addClientCancelBtn">Cancel</button>
      <button type="button" class="btn btn--primary" id="addClientSaveBtn">Save client</button>
    </div>
  </div>
</div>

<script>
(function () {
  var root = document.getElementById('apptApp');
  if (!root) return;
  var csrf = root.getAttribute('data-csrf') || '';
  var today = root.getAttribute('data-today') || '';
  var branchId = parseInt(root.getAttribute('data-branch-id') || '0', 10) || 0;
  var branchEl = document.getElementById('apptBranch');
  var newButton = document.getElementById('apptNewBtn');
  var dateEl = document.getElementById('apptDate');
  var listEl = document.getElementById('apptList');
  var statusEl = document.getElementById('apptStatus');
  var modal = document.getElementById('apptModal');
  var addClientModal = document.getElementById('addClientModal');
  var wizard = document.getElementById('apptWizard');
  var sheetTitle = document.getElementById('apptSheetTitle');
  var countryCache = null;
  var state = {
    step: 1,
    bookingId: 0,
    date: today,
    customer: null,
    clientSearch: '',
    staff: null,
    duration: null,
    service: null,
    room: null,
    startTime: null,
    staffList: [],
    serviceList: [],
    roomList: [],
    saving: false,
    attentionStep: 0
  };

  function setStatus(msg, type) {
    statusEl.textContent = msg || '';
    statusEl.className = 'appt__status' + (type ? (' is-' + type) : '');
  }

  function api(action, payload) {
    var body = Object.assign({ action: action, _csrf: csrf, branch_id: branchId }, payload || {});
    return fetch('appointment_api.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf
      },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  function minsLabel(mins) {
    mins = Math.max(0, parseInt(mins, 10) || 0);
    var h = Math.floor(mins / 60) % 24;
    var m = mins % 60;
    var suffix = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + String(m).padStart(2, '0') + ' ' + suffix;
  }

  function money(n) {
    n = Math.round(Number(n) || 0);
    return '₹' + n.toLocaleString('en-IN');
  }

  function loadList() {
    if (branchEl && branchId <= 0) {
      setStatus('');
      listEl.innerHTML = '<div class="appt__empty">Select a branch to view appointments.</div>';
      return;
    }
    var date = dateEl.value || today;
    setStatus('Loading appointments…');
    listEl.innerHTML = '<div class="appt__empty">Loading…</div>';
    api('list', { date: date }).then(function (res) {
      if (!res || !res.ok) {
        setStatus((res && res.error) || 'Could not load appointments.', 'error');
        listEl.innerHTML = '<div class="appt__empty">Could not load appointments.</div>';
        return;
      }
      setStatus('');
      var rows = res.appointments || [];
      if (!rows.length) {
        listEl.innerHTML = '<div class="appt__empty">No appointments for this date.<br>Tap <strong>+ Book</strong> to create one.</div>';
        return;
      }
      listEl.innerHTML = '';
      rows.forEach(function (row) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'appt-card';
        btn.disabled = !row.is_editable;
        btn.innerHTML =
          '<p class="appt-card__time">' + escapeHtml(row.start_label) + ' – ' + escapeHtml(row.end_label) + '</p>' +
          '<p class="appt-card__name">' + escapeHtml(row.customer_name) + '</p>' +
          '<p class="appt-card__meta">' + escapeHtml(row.service_name || '') + '</p>' +
          '<p class="appt-card__meta">Staff: ' + escapeHtml(row.staff_name || '—') +
            ' · Room: ' + escapeHtml(row.room_name || '—') + '</p>' +
          (row.is_editable ? '<span class="appt-card__edit">Tap to edit</span>' : '<span class="appt-card__edit">Not editable</span>');
        if (row.is_editable) {
          btn.addEventListener('click', function () { openEdit(row); });
        }
        listEl.appendChild(btn);
      });
    }).catch(function () {
      setStatus('Could not load appointments.', 'error');
      listEl.innerHTML = '<div class="appt__empty">Could not load appointments.</div>';
    });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function openModal(title) {
    sheetTitle.textContent = title;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    state.saving = false;
  }

  function resetCreateState() {
    state.step = 1;
    state.bookingId = 0;
    state.date = dateEl.value || today;
    state.customer = null;
    state.clientSearch = '';
    state.staff = null;
    state.duration = null;
    state.service = null;
    state.room = null;
    state.startTime = null;
    state.serviceList = [];
  }

  function openCreate() {
    resetCreateState();
    openModal('Book appointment');
    renderWizard();
    var clientSearch = document.getElementById('wizClientSearch');
    if (clientSearch) clientSearch.focus();
  }

  function allowedDuration(minutes) {
    var allowed = [30, 60, 90, 120];
    var value = parseInt(minutes, 10) || 0;
    if (allowed.indexOf(value) !== -1) return value;
    var nearest = 60;
    var bestDiff = Infinity;
    allowed.forEach(function (option) {
      var diff = Math.abs(option - value);
      if (diff < bestDiff) {
        bestDiff = diff;
        nearest = option;
      }
    });
    return nearest;
  }

  function openEdit(row) {
    state.step = 7;
    state.bookingId = row.id || 0;
    state.date = row.booking_date || dateEl.value || today;
    state.customer = {
      id: row.customer_id,
      name: row.customer_name,
      mobile_masked: row.customer_mobile_masked || ''
    };
    state.clientSearch = row.customer_name || '';
    state.staff = { id: row.staff_id, name: row.staff_name };
    var minutes = allowedDuration(
      row.minutes || ((row.end_time || 0) > (row.start_time || 0)
        ? (row.end_time - row.start_time)
        : 60)
    );
    state.service = {
      id: row.service_id,
      name: row.service_name,
      price: row.price,
      minutes: minutes
    };
    state.duration = minutes;
    state.room = { id: row.room_id, name: row.room_name };
    state.startTime = row.start_time;
    openModal('Edit appointment');
    renderWizard();
  }

  function currentIstDateAndMinutes() {
    try {
      var parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Kolkata',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23'
      }).formatToParts(new Date());
      var values = {};
      parts.forEach(function (part) {
        if (part.type !== 'literal') values[part.type] = part.value;
      });
      return {
        date: values.year + '-' + values.month + '-' + values.day,
        minutes: (parseInt(values.hour, 10) || 0) * 60 + (parseInt(values.minute, 10) || 0)
      };
    } catch (e) {
      var now = new Date();
      return {
        date: today,
        minutes: now.getHours() * 60 + now.getMinutes()
      };
    }
  }

  function buildSlots() {
    var slots = [];
    var nowIst = currentIstDateAndMinutes();
    for (var m = 10 * 60; m <= 23 * 60 + 45; m += 15) {
      if (state.date === nowIst.date && m <= nowIst.minutes) continue;
      slots.push(m);
    }
    // Keep the currently selected edit time visible/selected even if it is :15/:45
    // or already in the past for today.
    if (state.startTime != null && slots.indexOf(state.startTime) === -1) {
      slots.push(state.startTime);
      slots.sort(function (a, b) { return a - b; });
    }
    return slots;
  }

  function selectedDetailsHtml() {
    var rows = [];
    if (state.customer) {
      rows.push([1, 'Client', state.customer.name +
        (state.customer.mobile_masked ? ' (' + state.customer.mobile_masked + ')' : '')]);
    }
    if (state.staff) rows.push([2, 'Staff', state.staff.name]);
    if (state.duration) rows.push([3, 'Service time', state.duration + ' mins']);
    if (state.service) rows.push([4, 'Service', state.service.name]);
    if (state.room) rows.push([5, 'Room', state.room.name]);
    if (state.startTime != null) {
      var end = state.startTime + (state.service ? state.service.minutes : (state.duration || 0));
      rows.push([6, 'Date & time', state.date + ' · ' + minsLabel(state.startTime) + ' – ' + minsLabel(end)]);
    }
    if (!rows.length) return '';
    var html = '<div class="appt-selected">';
    rows.forEach(function (row) {
      html += '<div class="appt-selected__row">' +
        '<div class="appt-selected__text"><strong>' + escapeHtml(row[1]) + '</strong>' +
        '<span class="appt-selected__value">' + escapeHtml(row[2]) + '</span></div>' +
        '<button type="button" class="appt-selected__edit" data-edit-step="' + row[0] + '">Edit</button>' +
        '</div>';
    });
    return html + '</div>';
  }

  function goToStep(step) {
    state.step = step;
    state.attentionStep = step;
    renderWizard();
  }

  function highlightCurrentControl() {
    if (state.attentionStep !== state.step) return;
    state.attentionStep = 0;
    var selectors = {
      1: '#wizClientSearch',
      2: '#wizStaffSelect',
      3: '#wizDurationChoices',
      4: '#wizServiceSearch',
      5: '#wizRoomSelect',
      6: '#wizSlots',
      7: '#wizSave'
    };
    var control = document.querySelector(selectors[state.step] || '');
    if (!control) return;
    control.classList.remove('appt-attention');
    void control.offsetWidth;
    control.classList.add('appt-attention');
    window.setTimeout(function () {
      control.classList.remove('appt-attention');
    }, 1050);
  }

  function renderWizard() {
    var html = selectedDetailsHtml();
    if (state.step === 1) {
      html += '<div class="appt-step-head"><p class="appt-step-label">1. Choose client</p>' +
        '<button type="button" class="appt-add-client-btn" id="wizAddClient" ' +
        (state.clientSearch.trim().length >= 3 ? '' : 'hidden') + '>+ Add client</button></div>';
      html += '<p class="appt-help">Type at least 3 letters of the name or digits of the mobile number.</p>';
      html += '<div class="appt-autocomplete">';
      html += '<input class="appt-field" id="wizClientSearch" type="search" autocomplete="off" maxlength="100" placeholder="Client name or mobile" value="' + escapeHtml(state.clientSearch) + '">';
      html += '<div id="wizClientResults"></div></div>';
      html += '<div class="appt-actions">';
      html += '<button type="button" class="btn btn--primary" id="wizNext" ' + (state.customer ? '' : 'disabled') + '>Next</button>';
      html += '</div>';
    } else if (state.step === 2) {
      html += '<p class="appt-step-label">2. Choose staff</p>';
      html += '<select class="appt-field" id="wizStaffSelect"><option value="">Loading staff…</option></select>';
      html += '<div class="appt-actions"><button type="button" class="btn btn--ghost" id="wizBack">Back</button>';
      html += '<button type="button" class="btn btn--primary" id="wizNext" ' + (state.staff ? '' : 'disabled') + '>Next</button></div>';
    } else if (state.step === 3) {
      html += '<p class="appt-step-label">3. Choose service time</p>';
      html += '<p class="appt-help">How long is the service?</p>';
      html += '<div class="appt-slots" id="wizDurationChoices">';
      [
        [30, '30 mins'],
        [60, '60 mins (1 Hour)'],
        [90, '90 mins (1.5 Hours)'],
        [120, '120 mins (2 Hours)']
      ].forEach(function (duration) {
        html += '<button type="button" class="appt-slot' + (state.duration === duration[0] ? ' is-selected' : '') +
          '" data-duration="' + duration[0] + '">' + duration[1] + '</button>';
      });
      html += '</div>';
      html += '<div class="appt-actions"><button type="button" class="btn btn--ghost" id="wizBack">Back</button>';
      html += '<button type="button" class="btn btn--primary" id="wizNext" ' + (state.duration ? '' : 'disabled') + '>Next</button></div>';
    } else if (state.step === 4) {
      html += '<p class="appt-step-label">4. Choose service</p>';
      html += '<p class="appt-help">Search services for ' + escapeHtml(state.staff ? state.staff.name : 'staff') + '.</p>';
      html += '<div class="appt-autocomplete">';
      html += '<input class="appt-field" id="wizServiceSearch" type="search" autocomplete="off" placeholder="Type service name" value="' + escapeHtml(state.service ? state.service.name : '') + '">';
      html += '<div id="wizServiceResults"></div></div>';
      html += '<div class="appt-actions"><button type="button" class="btn btn--ghost" id="wizBack">Back</button>';
      html += '<button type="button" class="btn btn--primary" id="wizNext" ' + (state.service ? '' : 'disabled') + '>Next</button></div>';
    } else if (state.step === 5) {
      html += '<p class="appt-step-label">5. Choose room</p>';
      html += '<select class="appt-field" id="wizRoomSelect"><option value="">Loading rooms…</option></select>';
      html += '<div class="appt-actions"><button type="button" class="btn btn--ghost" id="wizBack">Back</button>';
      html += '<button type="button" class="btn btn--primary" id="wizNext" ' + (state.room ? '' : 'disabled') + '>Next</button></div>';
    } else if (state.step === 6) {
      html += '<p class="appt-step-label">6. Start time</p>';
      html += '<p class="appt-help">Service length: ' + (state.service ? state.service.minutes : 0) + ' mins</p>';
      html += '<input class="appt-field" type="date" id="wizDate" min="' + escapeHtml(today) + '" value="' + escapeHtml(state.date) + '">';
      html += '<div class="appt-slots" id="wizSlots"></div>';
      if (state.startTime != null && state.service) {
        html += '<div class="appt-summary">Ends at <strong>' + minsLabel(state.startTime + state.service.minutes) + '</strong></div>';
      }
      html += '<div class="appt-actions"><button type="button" class="btn btn--ghost" id="wizBack">Back</button>';
      html += '<button type="button" class="btn btn--primary" id="wizNext" ' + (state.startTime != null ? '' : 'disabled') + '>Next</button></div>';
    } else {
      var end = (state.startTime || 0) + (state.service ? state.service.minutes : 0);
      html += '<p class="appt-step-label">7. Confirm</p>';
      html += '<div class="appt-summary">';
      html += '<div><strong>Customer:</strong> ' + escapeHtml(state.customer.name) + '</div>';
      html += '<div><strong>Date:</strong> ' + escapeHtml(state.date) + '</div>';
      html += '<div><strong>Time:</strong> ' + minsLabel(state.startTime) + ' – ' + minsLabel(end) + '</div>';
      html += '<div><strong>Staff:</strong> ' + escapeHtml(state.staff.name) + '</div>';
      html += '<div><strong>Duration:</strong> ' + (state.duration || (state.service && state.service.minutes) || 0) + ' mins</div>';
      html += '<div><strong>Service:</strong> ' + escapeHtml(state.service.name) + '</div>';
      html += '<div><strong>Room:</strong> ' + escapeHtml(state.room.name) + '</div>';
      html += '<div><strong>Price:</strong> ' + money(state.service.price) + '</div>';
      html += '</div>';
      html += '<p class="appt-help" id="wizSaveMsg"></p>';
      html += '<div class="appt-actions"><button type="button" class="btn btn--ghost" id="wizBack">Back</button>';
      html += '<button type="button" class="btn btn--primary" id="wizSave">' + (state.bookingId ? 'Save changes' : 'Book now') + '</button></div>';
    }
    wizard.innerHTML = html;
    bindWizard();
    highlightCurrentControl();
  }

  function bindWizard() {
    var back = document.getElementById('wizBack');
    var next = document.getElementById('wizNext');
    document.querySelectorAll('[data-edit-step]').forEach(function (button) {
      button.addEventListener('click', function () {
        goToStep(parseInt(button.getAttribute('data-edit-step'), 10) || 1);
      });
    });
    if (back) back.addEventListener('click', function () {
      state.step = Math.max(1, state.step - 1);
      renderWizard();
    });
    if (state.step === 1) {
      bindClientAutocomplete();
      if (next) next.addEventListener('click', function () {
        if (!state.customer) return;
        goToStep(2);
      });
    } else if (state.step === 2) {
      if (state.staffList.length) renderStaffSelect();
      else loadStaff();
      if (next) next.addEventListener('click', function () {
        if (!state.staff) return;
        goToStep(3);
      });
    } else if (state.step === 3) {
      document.querySelectorAll('[data-duration]').forEach(function (button) {
        button.addEventListener('click', function () {
          state.duration = parseInt(button.getAttribute('data-duration'), 10) || null;
          state.service = null;
          renderWizard();
        });
      });
      if (next) next.addEventListener('click', function () {
        if (!state.duration) return;
        goToStep(4);
      });
    } else if (state.step === 4) {
      if (!state.duration && state.service && state.service.minutes) {
        state.duration = allowedDuration(state.service.minutes);
      }
      var allServices = state.staff && Array.isArray(state.staff.services) ? state.staff.services : [];
      state.serviceList = allServices.filter(function (service) {
        return service.minutes === state.duration;
      }).sort(sortServicesPopularFirst);
      bindServiceAutocomplete();
      if (next) next.addEventListener('click', function () {
        if (!state.service) return;
        state.duration = allowedDuration(state.service.minutes || state.duration);
        state.service.minutes = state.duration;
        goToStep(5);
      });
    } else if (state.step === 5) {
      if (state.roomList.length) renderRoomSelect();
      else loadRooms();
      if (next) next.addEventListener('click', function () {
        if (!state.room) return;
        goToStep(6);
      });
    } else if (state.step === 6) {
      var dateInput = document.getElementById('wizDate');
      if (dateInput) dateInput.addEventListener('change', function () {
        state.date = dateInput.value || state.date;
        state.startTime = null;
        renderWizard();
      });
      renderSlots();
      if (next) next.addEventListener('click', function () {
        if (state.startTime == null) return;
        if (dateInput) state.date = dateInput.value || state.date;
        goToStep(7);
      });
    } else {
      var saveBtn = document.getElementById('wizSave');
      if (saveBtn) saveBtn.addEventListener('click', saveBooking);
    }
  }

  function bindClientAutocomplete() {
    var input = document.getElementById('wizClientSearch');
    var box = document.getElementById('wizClientResults');
    var addButton = document.getElementById('wizAddClient');
    if (!input || !box) return;
    if (addButton) {
      addButton.addEventListener('click', openAddClient);
    }
    var timer = null;
    var requestNo = 0;
    input.addEventListener('input', function () {
      var search = String(input.value || '').trim();
      state.clientSearch = search;
      state.customer = null;
      if (addButton) addButton.hidden = search.length < 3;
      var next = document.getElementById('wizNext');
      if (next) next.disabled = true;
      clearTimeout(timer);
      if (search.length < 3) {
        box.innerHTML = '';
        return;
      }
      box.innerHTML = '<div class="appt__empty">Searching…</div>';
      var thisRequest = ++requestNo;
      timer = setTimeout(function () {
        api('client_search', { search: search }).then(function (res) {
          if (thisRequest !== requestNo) return;
          if (!res || !res.ok) {
            box.innerHTML = '<div class="appt__empty">' + escapeHtml((res && res.error) || 'Could not search clients') + '</div>';
            return;
          }
          renderClientResults(res.clients || [], box);
        }).catch(function () {
          if (thisRequest === requestNo) {
            box.innerHTML = '<div class="appt__empty">Could not search clients.</div>';
          }
        });
      }, 350);
    });
    if (input.value.trim().length >= 3 && !state.customer) {
      input.dispatchEvent(new Event('input'));
    }
  }

  function fillLeadSources(selectedId) {
    var sourceInput = document.getElementById('addClientSource');
    if (!sourceInput) return;
    sourceInput.innerHTML = '<option value="">Loading sources…</option>';
    api('lead_sources', {}).then(function (res) {
      if (!res || !res.ok) {
        sourceInput.innerHTML = '<option value="">Could not load sources</option>';
        return;
      }
      var sources = res.sources || [];
      if (!sources.length) {
        sourceInput.innerHTML = '<option value="">No sources found</option>';
        return;
      }
      sourceInput.innerHTML = '<option value="">Select source</option>';
      sources.forEach(function (source) {
        var option = document.createElement('option');
        option.value = String(source.id);
        option.textContent = source.name;
        if (selectedId && Number(selectedId) === Number(source.id)) {
          option.selected = true;
        } else if (!selectedId && String(source.name).toLowerCase() === 'walk-in') {
          option.selected = true;
        }
        sourceInput.appendChild(option);
      });
    }).catch(function () {
      sourceInput.innerHTML = '<option value="">Could not load sources</option>';
    });
  }

  function setMobileCountryRules() {
    var countryInput = document.getElementById('addClientCountry');
    var mobileInput = document.getElementById('addClientMobile');
    if (!countryInput || !mobileInput) return;
    var option = countryInput.options[countryInput.selectedIndex];
    var lengths = option ? String(option.getAttribute('data-lengths') || '') : '';
    var parsed = lengths.split(',').map(Number).filter(function (length) {
      return length > 0;
    });
    mobileInput.maxLength = parsed.length ? Math.max.apply(null, parsed) : 17;
    mobileInput.placeholder = option
      ? 'Enter mobile number (+' + String(option.getAttribute('data-dial-code') || '') + ')'
      : 'Enter mobile number';
  }

  function fillCountries() {
    var countryInput = document.getElementById('addClientCountry');
    if (!countryInput) return;

    function renderCountries(countries) {
      countryInput.innerHTML = '';
      countries.forEach(function (country) {
        var option = document.createElement('option');
        option.value = String(country.id);
        option.textContent = country.name + ' (+' + country.dial_code + ')';
        option.setAttribute('data-dial-code', String(country.dial_code));
        option.setAttribute('data-lengths', (country.lengths || []).join(','));
        if (Number(country.id) === 1 || String(country.code).toUpperCase() === 'IN') {
          option.selected = true;
        }
        countryInput.appendChild(option);
      });
      setMobileCountryRules();
    }

    if (countryCache) {
      renderCountries(countryCache);
      return;
    }
    countryInput.innerHTML = '<option value="1" data-dial-code="91" data-lengths="8,9,10,11,12,13">Loading… (+91)</option>';
    setMobileCountryRules();
    api('countries', {}).then(function (res) {
      if (!res || !res.ok || !(res.countries || []).length) {
        countryInput.innerHTML = '<option value="1" data-dial-code="91" data-lengths="8,9,10,11,12,13">India (+91)</option>';
        setMobileCountryRules();
        return;
      }
      countryCache = res.countries;
      renderCountries(countryCache);
    }).catch(function () {
      countryInput.innerHTML = '<option value="1" data-dial-code="91" data-lengths="8,9,10,11,12,13">India (+91)</option>';
      setMobileCountryRules();
    });
  }

  function openAddClient() {
    var search = String(state.clientSearch || '').trim();
    var digits = search.replace(/\D+/g, '');
    var nameInput = document.getElementById('addClientName');
    var mobileInput = document.getElementById('addClientMobile');
    var genderInput = document.getElementById('addClientGender');
    var message = document.getElementById('addClientMessage');
    nameInput.value = digits.length === search.length ? '' : search;
    mobileInput.value = digits.length === search.length ? digits : '';
    genderInput.value = 'male';
    message.textContent = '';
    fillLeadSources(null);
    fillCountries();
    addClientModal.classList.add('is-open');
    addClientModal.setAttribute('aria-hidden', 'false');
    if (nameInput.value === '') nameInput.focus();
    else mobileInput.focus();
  }

  function closeAddClient() {
    addClientModal.classList.remove('is-open');
    addClientModal.setAttribute('aria-hidden', 'true');
  }

  function saveNewClient() {
    var nameInput = document.getElementById('addClientName');
    var mobileInput = document.getElementById('addClientMobile');
    var genderInput = document.getElementById('addClientGender');
    var sourceInput = document.getElementById('addClientSource');
    var countryInput = document.getElementById('addClientCountry');
    var message = document.getElementById('addClientMessage');
    var saveButton = document.getElementById('addClientSaveBtn');
    var name = String(nameInput.value || '').trim();
    var mobile = String(mobileInput.value || '').replace(/\D+/g, '');
    var sourceId = parseInt(sourceInput.value || '0', 10) || 0;
    var countryId = parseInt(countryInput.value || '1', 10) || 1;
    if (name.length < 2) {
      message.textContent = 'Enter client name.';
      nameInput.focus();
      return;
    }
    var countryOption = countryInput.options[countryInput.selectedIndex];
    var countryLengths = countryOption
      ? String(countryOption.getAttribute('data-lengths') || '').split(',').map(Number).filter(function (length) {
          return length > 0;
        })
      : [];
    if (mobile.length < 4 || (countryLengths.length && countryLengths.indexOf(mobile.length) === -1)) {
      message.textContent = 'Enter valid mobile number' + (countryLengths.length ? ' (' + countryLengths.join(', ') + ' digits).' : '.');
      mobileInput.focus();
      return;
    }
    if (sourceId <= 0) {
      message.textContent = 'Select source.';
      sourceInput.focus();
      return;
    }
    saveButton.disabled = true;
    saveButton.textContent = 'Saving…';
    message.textContent = '';
    api('client_create', {
      name: name,
      mobile: mobile,
      gender: genderInput.value || 'male',
      source_id: sourceId,
      country_id: countryId
    }).then(function (res) {
      saveButton.disabled = false;
      saveButton.textContent = 'Save client';
      if (!res || !res.ok) {
        message.textContent = (res && res.error) || 'Could not add client.';
        return;
      }
      state.customer = res.client;
      state.clientSearch = res.client.name;
      closeAddClient();
      goToStep(2);
    }).catch(function () {
      saveButton.disabled = false;
      saveButton.textContent = 'Save client';
      message.textContent = 'Could not add client. Please try again.';
    });
  }

  document.getElementById('addClientCountry').addEventListener('change', setMobileCountryRules);

  function renderClientResults(clients, box) {
    if (!clients.length) {
      box.innerHTML = '<div class="appt__empty">No client found.</div>';
      return;
    }
    box.className = 'appt-results';
    box.innerHTML = '';
    clients.forEach(function (client) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'appt-result';
      button.textContent = client.label;
      button.addEventListener('click', function () {
        state.customer = client;
        state.clientSearch = client.name;
        renderWizard();
      });
      box.appendChild(button);
    });
  }

  function loadStaff() {
    api('staff', {}).then(function (res) {
      var select = document.getElementById('wizStaffSelect');
      if (!select) return;
      if (!res || !res.ok) {
        select.innerHTML = '<option value="">Could not load staff</option>';
        return;
      }
      state.staffList = res.staff || [];
      if (state.staff) {
        var loadedStaff = state.staffList.find(function (s) { return s.id === state.staff.id; });
        if (loadedStaff) state.staff = loadedStaff;
      }
      renderStaffSelect();
    });
  }

  function renderStaffSelect() {
    var select = document.getElementById('wizStaffSelect');
    if (!select) return;
    select.innerHTML = '<option value="">Select staff</option>';
    if (!state.staffList.length) {
      select.innerHTML = '<option value="">No staff found</option>';
      return;
    }
    state.staffList.forEach(function (s) {
      var option = document.createElement('option');
      option.value = String(s.id);
      option.textContent = s.name;
      option.selected = !!(state.staff && state.staff.id === s.id);
      select.appendChild(option);
    });
    select.addEventListener('change', function () {
      var id = parseInt(select.value, 10) || 0;
      var previousStaffId = state.staff ? state.staff.id : 0;
      state.staff = state.staffList.find(function (s) { return s.id === id; }) || null;
      if (id !== previousStaffId) {
        state.duration = null;
        state.service = null;
      }
      state.serviceList = state.staff ? (state.staff.services || []) : [];
      renderWizard();
    });
  }

  function servicePopularRank(service) {
    var text = (String(service.name || '') + ' ' + String(service.group || '')).toLowerCase();
    if (text.indexOf('deep tissue') !== -1) return 0;
    if (text.indexOf('swedish') !== -1) return 1;
    if (text.indexOf('balinese') !== -1 || text.indexOf('balinease') !== -1) return 2;
    return 99;
  }

  function sortServicesPopularFirst(a, b) {
    var rankDiff = servicePopularRank(a) - servicePopularRank(b);
    if (rankDiff !== 0) return rankDiff;
    return String(a.name || '').localeCompare(String(b.name || ''));
  }

  function bindServiceAutocomplete() {
    var input = document.getElementById('wizServiceSearch');
    var box = document.getElementById('wizServiceResults');
    if (!input || !box) return;
    if (!state.serviceList.length) {
      input.disabled = true;
      input.placeholder = 'No ' + state.duration + '-minute services for this staff';
      box.innerHTML = '<div class="appt__empty">No ' + state.duration + '-minute services are available for this staff.</div>';
      return;
    }
    function showResults() {
      var search = String(input.value || '').trim().toLowerCase();
      if (state.service && input.value !== state.service.name) {
        state.service = null;
        var next = document.getElementById('wizNext');
        if (next) next.disabled = true;
      }
      var matches = state.serviceList.filter(function (service) {
        return search === '' || service.name.toLowerCase().indexOf(search) !== -1 ||
          String(service.group || '').toLowerCase().indexOf(search) !== -1;
      }).sort(sortServicesPopularFirst).slice(0, 30);
      if (!matches.length) {
        box.className = '';
        box.innerHTML = '<div class="appt__empty">No matching service.</div>';
        return;
      }
      box.className = 'appt-results';
      box.innerHTML = '';
      matches.forEach(function (service) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'appt-result';
        button.innerHTML = escapeHtml(service.name) +
          '<span class="appt-result__sub">' + service.minutes + ' mins · ' + money(service.price) + '</span>';
        button.addEventListener('click', function () {
          state.service = service;
          renderWizard();
        });
        box.appendChild(button);
      });
    }
    input.addEventListener('input', showResults);
    input.addEventListener('focus', showResults);
    if (!state.service) showResults();
  }

  function loadRooms() {
    api('rooms', {}).then(function (res) {
      var select = document.getElementById('wizRoomSelect');
      if (!select) return;
      if (!res || !res.ok) {
        select.innerHTML = '<option value="">Could not load rooms</option>';
        return;
      }
      state.roomList = res.rooms || [];
      renderRoomSelect();
    });
  }

  function renderRoomSelect() {
    var select = document.getElementById('wizRoomSelect');
    if (!select) return;
    select.innerHTML = '<option value="">Select room</option>';
    if (!state.roomList.length) {
      select.innerHTML = '<option value="">No rooms found</option>';
      return;
    }
    state.roomList.forEach(function (r) {
      var option = document.createElement('option');
      option.value = String(r.id);
      option.textContent = r.name;
      option.selected = !!(state.room && state.room.id === r.id);
      select.appendChild(option);
    });
    select.addEventListener('change', function () {
      var id = parseInt(select.value, 10) || 0;
      state.room = state.roomList.find(function (r) { return r.id === id; }) || null;
      renderWizard();
    });
  }

  function renderSlots() {
    var box = document.getElementById('wizSlots');
    if (!box) return;
    box.innerHTML = '';
    var slots = buildSlots();
    if (!slots.length) {
      box.innerHTML = '<div class="appt__empty" style="grid-column:1/-1">No available start times remain today. Choose another date.</div>';
      return;
    }
    slots.forEach(function (m) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'appt-slot' + (state.startTime === m ? ' is-selected' : '');
      b.textContent = minsLabel(m);
      b.addEventListener('click', function () {
        state.startTime = m;
        renderWizard();
      });
      box.appendChild(b);
    });
  }

  function saveBooking() {
    if (state.saving) return;
    var msg = document.getElementById('wizSaveMsg');
    var saveBtn = document.getElementById('wizSave');
    if (!state.customer || !state.staff || !state.service || !state.room || state.startTime == null) {
      if (msg) msg.textContent = 'Please complete all steps.';
      return;
    }
    state.duration = allowedDuration(state.duration || state.service.minutes || 60);
    state.service.minutes = state.duration;
    state.saving = true;
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';
    }
    var end = state.startTime + state.duration;
    api('save', {
      booking_id: state.bookingId || 0,
      user_id: state.customer.id,
      booking_date: state.date,
      staff_id: state.staff.id,
      service_id: state.service.id,
      service_name: state.service.name,
      room_id: state.room.id,
      start_time: state.startTime,
      end_time: end,
      price: state.service.price
    }).then(function (res) {
      state.saving = false;
      if (!res || !res.ok) {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.textContent = state.bookingId ? 'Save changes' : 'Book now';
        }
        if (msg) msg.textContent = (res && res.error) || 'Could not save.';
        alert((res && res.error) || 'Could not save appointment');
        return;
      }
      closeModal();
      setStatus(res.message || 'Saved.', 'ok');
      if (dateEl && state.date) dateEl.value = state.date;
      loadList();
    }).catch(function () {
      state.saving = false;
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.textContent = state.bookingId ? 'Save changes' : 'Book now';
      }
      alert('Could not save appointment');
    });
  }

  newButton.addEventListener('click', openCreate);
  document.getElementById('apptCloseBtn').addEventListener('click', closeModal);
  document.getElementById('addClientCloseBtn').addEventListener('click', closeAddClient);
  document.getElementById('addClientCancelBtn').addEventListener('click', closeAddClient);
  document.getElementById('addClientSaveBtn').addEventListener('click', saveNewClient);
  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });
  addClientModal.addEventListener('click', function (e) {
    if (e.target === addClientModal) closeAddClient();
  });
  dateEl.addEventListener('change', loadList);
  if (branchEl) {
    branchEl.addEventListener('change', function () {
      branchId = parseInt(branchEl.value || '0', 10) || 0;
      root.setAttribute('data-branch-id', String(branchId));
      newButton.disabled = branchId <= 0;
      closeModal();
      closeAddClient();
      state.staffList = [];
      state.serviceList = [];
      state.roomList = [];
      loadList();
    });
  }
  loadList();
})();
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
