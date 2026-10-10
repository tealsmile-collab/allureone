<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
if (!can_access_expense($user)) {
    http_response_code(403);
    exit('Forbidden');
}

$roleId = (int) ($user['role_id'] ?? 0);
$canSelectBranch = in_array($roleId, [ROLE_SUPERADMIN, ROLE_ADMIN], true);
$canViewExpenses = $canSelectBranch;
$expenseBranches = [];
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
            $expenseBranches[] = [
                'id' => $branchId,
                'label' => $name !== '' && $locality !== ''
                    ? $name . ' — ' . $locality
                    : ($name !== '' ? $name : ($locality !== '' ? $locality : 'Branch ' . $branchId)),
            ];
        }
    } catch (Throwable $e) {
        error_log('Expense branch list failed: ' . $e->getMessage());
        $expenseBranches = [];
    }
}

$todayIst = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$pageTitle = 'Expense';
$activeNav = 'expense';
require __DIR__ . '/includes/layout_start.php';
?>

<style>
.exp {
  max-width: 920px;
  margin: 0 auto;
  padding: 0 0.25rem 3rem;
}
.exp__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin: 0 0 1rem;
  flex-wrap: wrap;
}
.exp__title {
  font-size: 1.35rem;
  font-weight: 700;
  margin: 0;
}
.exp__tabs {
  display: flex;
  gap: 0.35rem;
}
.exp__tab {
  border: 1px solid #cedae8;
  background: #f8fbff;
  color: #12263a;
  border-radius: 999px;
  padding: 0.4rem 0.9rem;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}
.exp__tab.is-active {
  background: #2f5f90;
  border-color: #1f446a;
  color: #fff;
}
.exp__panel[hidden] { display: none !important; }
.exp__branch-wrap { margin-bottom: 1rem; }
.exp__label {
  display: block;
  margin: 0 0 0.35rem;
  font-weight: 600;
  color: #12263a;
  font-size: 0.92rem;
}
.exp__control,
.exp select,
.exp input[type="text"],
.exp input[type="number"],
.exp input[type="date"],
.exp textarea {
  width: 100%;
  min-height: 44px;
  padding: 0.55rem 0.7rem;
  border: 1px solid #c9d2dc;
  border-radius: 8px;
  background: #fff;
  font: inherit;
  box-sizing: border-box;
}
.exp textarea { min-height: 96px; resize: vertical; }
.exp__grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.85rem 1rem;
}
.exp__field { margin-bottom: 0.85rem; }
.exp__field--full { grid-column: 1 / -1; }
.exp__error {
  color: #b42318;
  font-size: 0.82rem;
  margin: 0.3rem 0 0;
  min-height: 1.1em;
}
.exp__balance {
  margin-top: 0.45rem;
  border: 1px solid #d7dee7;
  border-radius: 8px;
  padding: 0.45rem 0.65rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  background: #fafbfc;
  font-size: 0.9rem;
  color: #425466;
}
.exp__totals {
  margin: 0.25rem 0 0.85rem;
  display: grid;
  gap: 0.35rem;
}
.exp__total-row {
  display: flex;
  justify-content: space-between;
  font-weight: 600;
  color: #12263a;
}
.exp__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.6rem;
  margin-top: 0.5rem;
}
.exp__save {
  background: #e67e22;
  border: 1px solid #d35400;
  color: #fff;
  min-width: 7rem;
}
.exp__save:hover { background: #d35400; }
.exp__status {
  min-height: 1.2rem;
  margin: 0 0 0.75rem;
  font-size: 0.95rem;
}
.exp__status.is-error { color: #b42318; font-weight: 600; }
.exp__list-bar {
  display: flex;
  gap: 0.75rem;
  align-items: end;
  flex-wrap: wrap;
  margin-bottom: 0.85rem;
}
.exp__month-field {
  margin: 0;
  min-width: 0;
}
.exp__month-picker {
  display: inline-flex;
  align-items: stretch;
  height: 42px;
  border: 1px solid #c5d3e3;
  border-radius: 10px;
  background: linear-gradient(180deg, #ffffff 0%, #f5f8fc 100%);
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.05);
  overflow: hidden;
}
.exp__month-nav {
  width: 2.35rem;
  border: 0;
  background: transparent;
  color: #244a73;
  font-size: 1.35rem;
  line-height: 1;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}
.exp__month-nav:hover {
  background: #eaf1f8;
}
.exp__month-nav:active {
  background: #dce7f2;
}
.exp__month-display {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  min-width: 11.5rem;
  padding: 0 0.85rem;
  border-left: 1px solid #d7e1ec;
  border-right: 1px solid #d7e1ec;
  color: #12263a;
  font-weight: 600;
  font-size: 0.98rem;
  cursor: pointer;
  user-select: none;
}
.exp__month-display:hover {
  background: #f8fbff;
}
.exp__month-icon {
  display: inline-flex;
  color: #244a73;
  flex: 0 0 auto;
}
.exp__month-label {
  white-space: nowrap;
}
.exp__month-input {
  position: absolute;
  inset: 0;
  opacity: 0;
  width: 100%;
  height: 100%;
  border: 0;
  cursor: pointer;
  font-size: 1rem;
}
.exp__list-bar .btn {
  height: 42px;
  padding-left: 1.1rem;
  padding-right: 1.1rem;
}
.exp__icon-btn {
  border: 1px solid #cedae8;
  background: #fff;
  border-radius: 8px;
  width: 2.1rem;
  height: 2.1rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: #334155;
}
.exp__icon-btn--danger { color: #b91c1c; border-color: #f1c0c0; }
.exp__icon-btn:hover { background: #f1f5f9; }
.exp-toast {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 10050;
  background: #166534;
  color: #fff;
  padding: 0.75rem 1rem;
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(16,24,40,0.18);
  opacity: 0;
  transform: translateY(-8px);
  pointer-events: none;
  transition: opacity 0.2s ease, transform 0.2s ease;
  max-width: min(90vw, 320px);
}
.exp-toast.is-show {
  opacity: 1;
  transform: translateY(0);
}
.exp-toast.is-error { background: #991b1b; }
.exp-modal {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10040;
  padding: 1rem;
}
.exp-modal[hidden] { display: none !important; }
.exp-modal__card {
  background: #fff;
  border-radius: 12px;
  padding: 1.25rem;
  width: min(100%, 380px);
  box-shadow: 0 16px 40px rgba(15,23,42,0.2);
}
.exp-modal__title {
  margin: 0 0 0.5rem;
  font-size: 1.1rem;
}
.exp-modal__text { margin: 0 0 1rem; color: #425466; }
.exp-modal__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
@media (max-width: 700px) {
  .exp__grid { grid-template-columns: 1fr; }
}
</style>

<div class="exp" id="expenseApp"
     data-csrf="<?= e(csrf_token()) ?>"
     data-can-select-branch="<?= $canSelectBranch ? '1' : '0' ?>"
     data-can-view="<?= $canViewExpenses ? '1' : '0' ?>"
     data-branch-id="<?= (int) $selectedBranchId ?>"
     data-today="<?= e($todayIst) ?>">
    <div class="exp__head">
        <h1 class="exp__title">Expense</h1>
        <?php if ($canViewExpenses): ?>
            <div class="exp__tabs" role="tablist" id="expTabs"<?= $canSelectBranch ? ' hidden' : '' ?>>
                <button type="button" class="exp__tab" data-tab="add" id="expTabAdd">Add Expense</button>
                <button type="button" class="exp__tab is-active" data-tab="view" id="expTabView">View Expenses</button>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($canSelectBranch): ?>
        <div class="exp__branch-wrap">
            <label class="exp__label" for="expBranch">Branch</label>
            <select id="expBranch" class="exp__control">
                <option value="">Select branch</option>
                <?php foreach ($expenseBranches as $b): ?>
                    <option value="<?= (int) $b['id'] ?>"><?= e((string) $b['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <p class="exp__status" id="expStatus" aria-live="polite"></p>

    <div class="exp__panel" id="expPanelAdd" data-panel="add"<?= $canSelectBranch ? ' hidden' : '' ?>>
        <form id="expForm" novalidate>
            <input type="hidden" id="expEditId" value="">
            <div class="exp__field">
                <label class="exp__label" for="expDate">Date</label>
                <input type="date" id="expDate" value="<?= e($todayIst) ?>" required>
                <p class="exp__error" data-error-for="date"></p>
            </div>
            <div class="exp__grid">
                <div class="exp__field">
                    <label class="exp__label" for="expType">Expense Type</label>
                    <select id="expType" required>
                        <option value="">Select</option>
                    </select>
                    <p class="exp__error" data-error-for="type"></p>
                </div>
                <div class="exp__field">
                    <label class="exp__label" for="expMode">Payment Methods</label>
                    <select id="expMode" required>
                        <option value="">Select</option>
                    </select>
                    <p class="exp__error" data-error-for="mode"></p>
                </div>
                <div class="exp__field">
                    <label class="exp__label" for="expAccount">Account</label>
                    <select id="expAccount" required>
                        <option value="">Select</option>
                    </select>
                    <div class="exp__balance" id="expBalanceWrap" hidden>
                        <span id="expBalanceLabel">Available balance</span>
                        <strong id="expBalanceValue">₹0.00</strong>
                    </div>
                    <p class="exp__error" data-error-for="account"></p>
                </div>
                <div class="exp__field">
                    <label class="exp__label" for="expGivenTo">Given to</label>
                    <input type="text" id="expGivenTo" placeholder="Given to" autocomplete="off" maxlength="50" required>
                    <p class="exp__error" data-error-for="given_to"></p>
                </div>
                <div class="exp__field">
                    <label class="exp__label" for="expAmount">Amount</label>
                    <input type="number" id="expAmount" placeholder="Amount" min="0" step="0.01" required>
                    <p class="exp__error" data-error-for="amount"></p>
                </div>
                <div class="exp__field">
                    <label class="exp__label" for="expTax">Tax Group</label>
                    <select id="expTax">
                        <option value="">None</option>
                    </select>
                </div>
            </div>
            <div class="exp__totals">
                <div class="exp__total-row"><span>Net Amount</span><span id="expNetDisplay">₹0</span></div>
                <div class="exp__total-row"><span>Tax</span><span id="expTaxDisplay">₹0</span></div>
            </div>
            <div class="exp__field">
                <label class="exp__label" for="expDesc">Description</label>
                <textarea id="expDesc" placeholder="Description" maxlength="100" required></textarea>
                <p class="exp__error" data-error-for="desc"></p>
            </div>
            <div class="exp__actions">
                <button type="button" class="btn btn--ghost" id="expCancelEdit" hidden>Cancel</button>
                <button type="submit" class="btn exp__save" id="expSubmitBtn">Save</button>
            </div>
        </form>
    </div>

    <?php if ($canViewExpenses): ?>
        <div class="exp__panel" id="expPanelView" data-panel="view" hidden>
            <div class="exp__list-bar">
                <div class="exp__field exp__month-field">
                    <span class="exp__label" id="expListMonthLabel">Month</span>
                    <div class="exp__month-picker" role="group" aria-labelledby="expListMonthLabel">
                        <button type="button" class="exp__month-nav" id="expMonthPrev" aria-label="Previous month">‹</button>
                        <label class="exp__month-display" for="expListMonth">
                            <span class="exp__month-icon" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                                    <path d="M3 10h18"></path>
                                    <path d="M8 3v4"></path>
                                    <path d="M16 3v4"></path>
                                </svg>
                            </span>
                            <span class="exp__month-label" id="expMonthText"><?= e(date('F Y', strtotime(substr($todayIst, 0, 7) . '-01'))) ?></span>
                            <input type="month" id="expListMonth" class="exp__month-input" value="<?= e(substr($todayIst, 0, 7)) ?>" aria-label="Select month">
                        </label>
                        <button type="button" class="exp__month-nav" id="expMonthNext" aria-label="Next month">›</button>
                    </div>
                </div>
                <button type="button" class="btn btn--primary" id="expListRefresh">Refresh</button>
            </div>
            <div style="overflow:auto">
                <table class="data" id="expTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Mode</th>
                            <th>Given to</th>
                            <th>Amount</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="expTableBody">
                        <tr><td colspan="7" class="empty">Select a branch to load expenses.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="exp-toast" id="expToast" role="status" aria-live="polite"></div>

<div class="exp-modal" id="expDeleteModal" hidden>
    <div class="exp-modal__card" role="dialog" aria-modal="true" aria-labelledby="expDeleteTitle">
        <h2 class="exp-modal__title" id="expDeleteTitle">Delete expense</h2>
        <p class="exp-modal__text">Do you want to delete expense?</p>
        <div class="exp-modal__actions">
            <button type="button" class="btn btn--ghost" id="expDeleteNo">No</button>
            <button type="button" class="btn btn--danger" id="expDeleteYes">Yes</button>
        </div>
    </div>
</div>

<script>
(function () {
  var root = document.getElementById('expenseApp');
  if (!root) return;

  var csrf = root.getAttribute('data-csrf') || '';
  var canSelectBranch = root.getAttribute('data-can-select-branch') === '1';
  var canView = root.getAttribute('data-can-view') === '1';
  var branchId = parseInt(root.getAttribute('data-branch-id') || '0', 10) || 0;
  var today = root.getAttribute('data-today') || '';

  var meta = { expense_types: [], accounts: [], payment_modes: [], tax_groups: [] };
  var metaLoaded = false;
  var pendingDelete = null;
  var monthExpenses = [];

  var el = {
    branch: document.getElementById('expBranch'),
    status: document.getElementById('expStatus'),
    form: document.getElementById('expForm'),
    date: document.getElementById('expDate'),
    type: document.getElementById('expType'),
    mode: document.getElementById('expMode'),
    account: document.getElementById('expAccount'),
    givenTo: document.getElementById('expGivenTo'),
    amount: document.getElementById('expAmount'),
    tax: document.getElementById('expTax'),
    desc: document.getElementById('expDesc'),
    editId: document.getElementById('expEditId'),
    submitBtn: document.getElementById('expSubmitBtn'),
    cancelEdit: document.getElementById('expCancelEdit'),
    netDisplay: document.getElementById('expNetDisplay'),
    taxDisplay: document.getElementById('expTaxDisplay'),
    balanceWrap: document.getElementById('expBalanceWrap'),
    balanceLabel: document.getElementById('expBalanceLabel'),
    balanceValue: document.getElementById('expBalanceValue'),
    panelAdd: document.getElementById('expPanelAdd'),
    panelView: document.getElementById('expPanelView'),
    tabs: document.getElementById('expTabs'),
    tabAdd: document.getElementById('expTabAdd'),
    tabView: document.getElementById('expTabView'),
    listMonth: document.getElementById('expListMonth'),
    monthText: document.getElementById('expMonthText'),
    monthPrev: document.getElementById('expMonthPrev'),
    monthNext: document.getElementById('expMonthNext'),
    listRefresh: document.getElementById('expListRefresh'),
    tableBody: document.getElementById('expTableBody'),
    toast: document.getElementById('expToast'),
    deleteModal: document.getElementById('expDeleteModal'),
    deleteYes: document.getElementById('expDeleteYes'),
    deleteNo: document.getElementById('expDeleteNo')
  };

  function money(n) {
    var v = Number(n) || 0;
    return '₹' + v.toLocaleString('en-IN', { minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2 });
  }

  function setStatus(msg, isError) {
    if (!el.status) return;
    el.status.textContent = msg || '';
    el.status.classList.toggle('is-error', !!isError && !!msg);
  }

  var toastTimer = null;
  function showToast(msg, isError) {
    if (!el.toast) return;
    el.toast.textContent = msg || '';
    el.toast.classList.toggle('is-error', !!isError);
    el.toast.classList.add('is-show');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      el.toast.classList.remove('is-show');
    }, 2800);
  }

  function clearFieldErrors() {
    root.querySelectorAll('[data-error-for]').forEach(function (node) {
      node.textContent = '';
    });
  }

  function setFieldError(key, msg) {
    var node = root.querySelector('[data-error-for="' + key + '"]');
    if (node) node.textContent = msg || '';
  }

  function normalizeOptionLabel(text) {
    return String(text || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function selectPreferredOption(select, preferNames) {
    if (!select || !preferNames || !preferNames.length) return false;
    for (var i = 0; i < preferNames.length; i++) {
      var want = normalizeOptionLabel(preferNames[i]);
      if (!want) continue;
      var partial = null;
      for (var j = 0; j < select.options.length; j++) {
        var label = normalizeOptionLabel(select.options[j].textContent);
        if (!label || !select.options[j].value) continue;
        if (label === want) {
          select.value = select.options[j].value;
          return true;
        }
        if (partial === null && label.indexOf(want) !== -1) {
          partial = select.options[j].value;
        }
      }
      if (partial !== null) {
        select.value = partial;
        return true;
      }
    }
    return false;
  }

  function fillSelect(select, items, placeholder, preferNames) {
    if (!select) return;
    select.innerHTML = '';
    var opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = placeholder || 'Select';
    select.appendChild(opt0);
    (items || []).forEach(function (item) {
      var opt = document.createElement('option');
      opt.value = String(item.id);
      opt.textContent = item.name;
      if (item.balance != null) opt.setAttribute('data-balance', String(item.balance));
      if (item.rate != null) opt.setAttribute('data-rate', String(item.rate));
      select.appendChild(opt);
    });
    selectPreferredOption(select, preferNames);
  }

  function currentBranchId() {
    if (canSelectBranch && el.branch) {
      return parseInt(el.branch.value || '0', 10) || 0;
    }
    return branchId;
  }

  function api(action, payload) {
    var bid = currentBranchId();
    return fetch('expense_api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({
        action: action,
        _csrf: csrf,
        branch_id: bid
      }, payload || {}))
    }).then(function (r) { return r.json(); });
  }

  function recalcTotals() {
    var amount = parseFloat(el.amount.value || '0') || 0;
    var rate = 0;
    if (el.tax && el.tax.selectedOptions[0]) {
      rate = parseFloat(el.tax.selectedOptions[0].getAttribute('data-rate') || '0') || 0;
    }
    var tax = 0;
    if (rate > 0 && amount > 0) {
      tax = Math.round((amount * rate / 100) * 100) / 100;
    }
    var net = amount;
    el.netDisplay.textContent = money(net);
    el.taxDisplay.textContent = money(tax);
    el.netDisplay.setAttribute('data-value', String(net));
    el.taxDisplay.setAttribute('data-value', String(tax));
  }

  function updateBalance() {
    if (!el.account || !el.balanceWrap) return;
    var opt = el.account.selectedOptions[0];
    var bal = opt ? opt.getAttribute('data-balance') : null;
    var name = opt ? opt.textContent : '';
    if (bal == null || bal === '') {
      el.balanceWrap.hidden = true;
      return;
    }
    el.balanceLabel.textContent = 'Available ' + (name || 'balance');
    el.balanceValue.textContent = money(parseFloat(bal));
    el.balanceWrap.hidden = false;
  }

  function setFormEnabled(enabled) {
    el.form.querySelectorAll('input, select, textarea, button').forEach(function (node) {
      if (node.id === 'expCancelEdit') return;
      node.disabled = !enabled;
    });
  }

  function applyDefaultSelects() {
    fillSelect(el.type, meta.expense_types, 'Select');
    fillSelect(el.mode, meta.payment_modes, 'Select', ['cash']);
    fillSelect(el.account, meta.accounts, 'Select', ['petty cash', 'pettycash']);
    fillSelect(el.tax, meta.tax_groups, 'None');
    updateBalance();
  }

  function setBranchUiVisible(visible) {
    if (!canSelectBranch) return;
    if (el.tabs) el.tabs.hidden = !visible;
    if (!visible) {
      if (el.panelAdd) el.panelAdd.hidden = true;
      if (el.panelView) el.panelView.hidden = true;
      if (el.tabAdd) el.tabAdd.classList.remove('is-active');
      if (el.tabView) el.tabView.classList.add('is-active');
    } else {
      if (el.tabAdd) el.tabAdd.classList.remove('is-active');
      if (el.tabView) el.tabView.classList.add('is-active');
      if (el.panelAdd) el.panelAdd.hidden = true;
      if (el.panelView) el.panelView.hidden = false;
    }
  }

  function clearForm(keepDate) {
    var dateVal = keepDate ? el.date.value : today;
    el.editId.value = '';
    el.form.reset();
    el.date.value = dateVal || today;
    applyDefaultSelects();
    el.submitBtn.textContent = 'Save';
    el.cancelEdit.hidden = true;
    clearFieldErrors();
    recalcTotals();
    updateBalance();
  }

  function loadMeta() {
    var bid = currentBranchId();
    if (bid <= 0) {
      metaLoaded = false;
      setFormEnabled(false);
      setBranchUiVisible(false);
      setStatus(canSelectBranch ? 'Select a branch to continue.' : 'No branch linked to your account.', true);
      return Promise.resolve();
    }
    setStatus('Loading…');
    setFormEnabled(false);
    return api('meta', {}).then(function (res) {
      if (!res || !res.ok) {
        metaLoaded = false;
        setBranchUiVisible(false);
        setStatus((res && res.error) || 'Could not load expense options.', true);
        return;
      }
      meta.expense_types = res.expense_types || [];
      meta.accounts = res.accounts || [];
      meta.payment_modes = res.payment_modes || [];
      meta.tax_groups = res.tax_groups || [];
      metaLoaded = true;
      applyDefaultSelects();
      setFormEnabled(true);
      setBranchUiVisible(true);
      setStatus('');
      updateBalance();
      recalcTotals();
      if (canView) loadList();
    }).catch(function () {
      metaLoaded = false;
      setBranchUiVisible(false);
      setStatus('Could not load expense options.', true);
    });
  }

  function switchTab(tab) {
    if (!canView) return;
    if (canSelectBranch && !metaLoaded) {
      setStatus('Select a branch to continue.', true);
      return;
    }
    var isView = tab === 'view';
    if (el.panelAdd) el.panelAdd.hidden = isView;
    if (el.panelView) el.panelView.hidden = !isView;
    if (el.tabAdd) el.tabAdd.classList.toggle('is-active', !isView);
    if (el.tabView) el.tabView.classList.toggle('is-active', isView);
    if (isView) {
      loadList();
    } else if (!el.editId.value) {
      selectPreferredOption(el.account, ['petty cash', 'pettycash']);
      updateBalance();
    }
  }

  function findOptionIdByName(items, name) {
    var want = String(name || '').trim().toLowerCase();
    if (!want) return 0;
    for (var i = 0; i < (items || []).length; i++) {
      if (String(items[i].name || '').trim().toLowerCase() === want) {
        return Number(items[i].id) || 0;
      }
    }
    return 0;
  }

  function renderExpenseRows(rows) {
    if (!el.tableBody) return;
    if (!rows.length) {
      el.tableBody.innerHTML = '<tr><td colspan="7" class="empty">No expenses for this selection.</td></tr>';
      return;
    }
    el.tableBody.innerHTML = '';
    rows.forEach(function (row) {
      var tr = document.createElement('tr');
      var typeLabel = row.expense_type_name || '';
      var modeLabel = row.payment_mode_name || '';
      var dateLabel = row.date_display || row.date || '';
      tr.innerHTML =
        '<td>' + escapeHtml(dateLabel) + '</td>' +
        '<td>' + escapeHtml(typeLabel) + '</td>' +
        '<td>' + escapeHtml(modeLabel) + '</td>' +
        '<td>' + escapeHtml(row.given_to || '') + '</td>' +
        '<td>' + escapeHtml(money(row.amount)) + '</td>' +
        '<td>' + escapeHtml(row.desc || '') + '</td>' +
        '<td class="table-actions"></td>';
      var actions = tr.querySelector('.table-actions');
      var editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'exp__icon-btn';
      editBtn.title = 'Edit';
      editBtn.setAttribute('aria-label', 'Edit');
      editBtn.innerHTML = penSvg();
      editBtn.addEventListener('click', function () { startEdit(row); });
      var delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'exp__icon-btn exp__icon-btn--danger';
      delBtn.title = 'Delete';
      delBtn.setAttribute('aria-label', 'Delete');
      delBtn.innerHTML = trashSvg();
      delBtn.addEventListener('click', function () {
        pendingDelete = { id: row.id, date: row.date };
        if (el.deleteModal) el.deleteModal.hidden = false;
      });
      actions.appendChild(editBtn);
      actions.appendChild(document.createTextNode(' '));
      actions.appendChild(delBtn);
      el.tableBody.appendChild(tr);
    });
  }

  function loadList() {
    if (!canView || !el.tableBody) return;
    var bid = currentBranchId();
    if (bid <= 0) {
      monthExpenses = [];
      el.tableBody.innerHTML = '<tr><td colspan="7" class="empty">Select a branch to load expenses.</td></tr>';
      return;
    }
    var month = (el.listMonth && el.listMonth.value) || (today ? today.slice(0, 7) : '');
    if (!month) {
      el.tableBody.innerHTML = '<tr><td colspan="7" class="empty">Please select a month.</td></tr>';
      return;
    }
    el.tableBody.innerHTML = '<tr><td colspan="7" class="empty">Loading…</td></tr>';
    api('list', { month: month }).then(function (res) {
      if (!res || !res.ok) {
        monthExpenses = [];
        el.tableBody.innerHTML = '<tr><td colspan="7" class="empty">' +
          ((res && res.error) || 'Could not load expenses.') + '</td></tr>';
        return;
      }
      monthExpenses = res.expenses || [];
      renderExpenseRows(monthExpenses);
    }).catch(function () {
      monthExpenses = [];
      el.tableBody.innerHTML = '<tr><td colspan="7" class="empty">Could not load expenses.</td></tr>';
    });
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function penSvg() {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>';
  }
  function trashSvg() {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';
  }

  function startEdit(row) {
    switchTab('add');
    el.editId.value = String(row.id || '');
    el.date.value = row.date || today;
    var typeId = Number(row.type) || findOptionIdByName(meta.expense_types, row.expense_type_name);
    var modeId = Number(row.mode) || findOptionIdByName(meta.payment_modes, row.payment_mode_name);
    el.type.value = typeId ? String(typeId) : '';
    el.mode.value = modeId ? String(modeId) : '';
    if (row.vendor_account_id) {
      el.account.value = String(row.vendor_account_id);
    } else if (!el.account.value) {
      fillSelect(el.account, meta.accounts, 'Select', ['petty cash']);
    }
    el.givenTo.value = row.given_to || '';
    el.amount.value = row.amount != null ? String(row.amount) : '';
    el.desc.value = row.desc || '';
    el.tax.value = row.tax_group_id != null ? String(row.tax_group_id) : '';
    el.submitBtn.textContent = 'Update';
    el.cancelEdit.hidden = false;
    updateBalance();
    recalcTotals();
    clearFieldErrors();
  }

  function validateForm() {
    clearFieldErrors();
    var ok = true;
    var givenTo = String(el.givenTo.value || '').trim();
    var desc = String(el.desc.value || '').trim();
    if (!el.type.value) { setFieldError('type', 'please select expense type'); ok = false; }
    if (!givenTo) { setFieldError('given_to', 'please enter given to'); ok = false; }
    else if (givenTo.length > 50) { setFieldError('given_to', 'given to max 50 characters'); ok = false; }
    if (!(parseFloat(el.amount.value || '0') > 0)) { setFieldError('amount', 'please enter amount'); ok = false; }
    if (!desc) { setFieldError('desc', 'please enter description'); ok = false; }
    else if (desc.length > 100) { setFieldError('desc', 'description max 100 characters'); ok = false; }
    return ok;
  }

  el.form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!metaLoaded) {
      setStatus('Select a branch and wait for options to load.', true);
      return;
    }
    if (!validateForm()) return;
    var isUpdate = !!el.editId.value;
    var payload = {
      date: el.date.value,
      type: el.type.value,
      mode: el.mode.value,
      given_to: String(el.givenTo.value || '').trim(),
      amount: el.amount.value,
      desc: String(el.desc.value || '').trim(),
      net: el.netDisplay.getAttribute('data-value') || el.amount.value,
      tax: el.taxDisplay.getAttribute('data-value') || '0',
      vendor_account_id: el.account.value
    };
    if (isUpdate) payload.id = el.editId.value;
    el.submitBtn.disabled = true;
    setStatus(isUpdate ? 'Updating…' : 'Saving…');
    api(isUpdate ? 'update' : 'save', payload).then(function (res) {
      el.submitBtn.disabled = false;
      if (!res || !res.ok) {
        setStatus((res && res.error) || 'Save failed.', true);
        showToast((res && res.error) || 'Save failed.', true);
        return;
      }
      setStatus('');
      showToast(isUpdate ? 'Expense updated.' : 'Expense added.');
      clearForm(true);
      if (canView && el.panelView && !el.panelView.hidden) loadList();
    }).catch(function () {
      el.submitBtn.disabled = false;
      setStatus('Save failed.', true);
      showToast('Save failed.', true);
    });
  });

  if (el.cancelEdit) {
    el.cancelEdit.addEventListener('click', function () {
      clearForm(true);
    });
  }
  if (el.amount) el.amount.addEventListener('input', recalcTotals);
  if (el.tax) el.tax.addEventListener('change', recalcTotals);
  if (el.account) el.account.addEventListener('change', updateBalance);
  if (el.branch) {
    el.branch.addEventListener('change', function () {
      if (!el.branch.value) {
        metaLoaded = false;
        setFormEnabled(false);
        setBranchUiVisible(false);
        setStatus('Select a branch to continue.', true);
        return;
      }
      clearForm(true);
      loadMeta();
    });
  }
  if (el.tabAdd) el.tabAdd.addEventListener('click', function () { switchTab('add'); });
  if (el.tabView) el.tabView.addEventListener('click', function () { switchTab('view'); });
  function formatMonthLabel(ym) {
    if (!ym || !/^\d{4}-\d{2}$/.test(ym)) return '';
    var parts = ym.split('-');
    var d = new Date(Number(parts[0]), Number(parts[1]) - 1, 1);
    if (isNaN(d.getTime())) return ym;
    return d.toLocaleString('en-IN', { month: 'long', year: 'numeric' });
  }

  function syncMonthLabel() {
    if (!el.listMonth || !el.monthText) return;
    el.monthText.textContent = formatMonthLabel(el.listMonth.value) || el.listMonth.value;
  }

  function shiftMonth(delta) {
    if (!el.listMonth || !el.listMonth.value) return;
    var parts = el.listMonth.value.split('-');
    var y = Number(parts[0]);
    var m = Number(parts[1]) - 1 + delta;
    var d = new Date(y, m, 1);
    var next = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    el.listMonth.value = next;
    syncMonthLabel();
    loadList();
  }

  if (el.listRefresh) el.listRefresh.addEventListener('click', loadList);
  if (el.listMonth) {
    syncMonthLabel();
    el.listMonth.addEventListener('change', function () {
      syncMonthLabel();
      loadList();
    });
  }
  if (el.monthPrev) el.monthPrev.addEventListener('click', function () { shiftMonth(-1); });
  if (el.monthNext) el.monthNext.addEventListener('click', function () { shiftMonth(1); });
  if (el.deleteNo) {
    el.deleteNo.addEventListener('click', function () {
      pendingDelete = null;
      el.deleteModal.hidden = true;
    });
  }
  if (el.deleteYes) {
    el.deleteYes.addEventListener('click', function () {
      if (!pendingDelete) return;
      var payload = { id: pendingDelete.id, date: pendingDelete.date };
      el.deleteYes.disabled = true;
      api('delete', payload).then(function (res) {
        el.deleteYes.disabled = false;
        el.deleteModal.hidden = true;
        pendingDelete = null;
        if (!res || !res.ok) {
          showToast((res && res.error) || 'Delete failed.', true);
          return;
        }
        showToast('Expense deleted.');
        loadList();
      }).catch(function () {
        el.deleteYes.disabled = false;
        showToast('Delete failed.', true);
      });
    });
  }

  setFormEnabled(false);
  if (!canSelectBranch) {
    if (branchId > 0) loadMeta();
    else setStatus('No branch linked to your account.', true);
  } else {
    setStatus('Select a branch to continue.');
  }
})();
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
