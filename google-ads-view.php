<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_not_accounts_role();
require_not_franchise_officer_role();

$user = current_user();
$roleId = (int) ($user['role_id'] ?? 0);
if ($roleId !== ROLE_SUPERADMIN && $roleId !== ROLE_ADMIN) {
    http_response_code(403);
    exit('Forbidden');
}

$todayYmd = date('Ymd');
$selectedDateInput = trim((string) ($_GET['date'] ?? ''));
if ($selectedDateInput === '') {
    $selectedDateInput = date('Y-m-d');
}
$startDate = $todayYmd;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDateInput) === 1) {
    $startDate = str_replace('-', '', $selectedDateInput);
}

$pageTitle = 'Google Ads View';
$activeNav = 'google_ads_view';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="card">
    <div class="card__head">
        <span>Google Ads Website Visits</span>
    </div>
    <div class="card__body">
        <form method="get" action="google-ads-view.php" class="form form--invoice-search" style="padding:1rem 1.25rem 0">
            <p class="main__meta" style="width:100%;margin:0 0 0.4rem 0;font-size:0.8rem">
                Note: This data shows client website visit count through Google Ads on selected date. Google Business Profile visits are not counted here.
            </p>
            <div class="form__row">
                <label for="google_ads_view_date">Date</label>
                <input type="date" id="google_ads_view_date" name="date" value="<?= e($selectedDateInput) ?>">
            </div>
            <div class="form__row form__row--submit">
                <button type="submit" class="btn btn--primary">Apply</button>
            </div>
        </form>
        <div id="google-ads-view-status" class="main__meta" style="padding:0 1.25rem 1rem">
            <span class="google-ads-spinner" aria-hidden="true"></span>
        </div>
        <div class="table-wrap" id="google-ads-view-table">
            <table class="data">
                <thead>
                    <tr>
                        <th>Event Name</th>
                        <th>Visits</th>
                        <th>Calls</th>
                    </tr>
                </thead>
                <tbody id="google-ads-view-body">
                    <tr><td colspan="3" style="text-align:center"><span class="google-ads-spinner" aria-hidden="true"></span></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.google-ads-spinner {
    display: inline-block;
    width: 24px;
    height: 24px;
    border: 3px solid #c9d8ea;
    border-top-color: #2f5f90;
    border-radius: 50%;
    animation: googleAdsSpin 0.8s linear infinite;
    vertical-align: middle;
}
@keyframes googleAdsSpin {
    to { transform: rotate(360deg); }
}
</style>

<script>
(function () {
    var form = document.querySelector('form[action="google-ads-view.php"]');
    var dateInput = document.getElementById('google_ads_view_date');
    var bodyEl = document.getElementById('google-ads-view-body');
    var statusEl = document.getElementById('google-ads-view-status');
    var tableEl = document.getElementById('google-ads-view-table');
    var apiUrl = <?= json_encode(allureone_url('google-ads-view-api.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var loadingHtml = '<span class="google-ads-spinner" aria-hidden="true"></span>';
    var appliedDate = dateInput ? String(dateInput.value || '').trim() : '';

    function showTable() {
        if (tableEl) tableEl.style.display = '';
    }

    function hideTable() {
        if (tableEl) tableEl.style.display = 'none';
        if (statusEl) statusEl.textContent = '';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s || '');
        return d.innerHTML;
    }

    function renderRows(results, total, totalCalls) {
        if (!bodyEl) return;
        if (!Array.isArray(results) || results.length === 0) {
            bodyEl.innerHTML = '<tr><td colspan="3">No event data found.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < results.length; i++) {
            var row = results[i] || {};
            var callCell = '—';
            if (row.call_event) {
                callCell = String(Number(row.call_count || 0));
            }
            html += '<tr><td>' + esc(row.event || '') + '</td><td>' + Number(row.count || 0) + '</td><td>' + callCell + '</td></tr>';
        }
        html += '<tr><th>TOTAL</th><th>' + Number(total || 0) + '</th><th>' + Number(totalCalls || 0) + '</th></tr>';
        bodyEl.innerHTML = html;
    }

    function loadData() {
        if (!dateInput) return;
        var dateVal = String(dateInput.value || '').trim();
        appliedDate = dateVal;
        showTable();
        if (statusEl) statusEl.innerHTML = loadingHtml;
        if (bodyEl) bodyEl.innerHTML = '<tr><td colspan="3" style="text-align:center">' + loadingHtml + '</td></tr>';
        fetch(apiUrl + '?date=' + encodeURIComponent(dateVal), {
            credentials: 'same-origin'
        })
            .then(function (r) {
                return r.text().then(function (text) {
                    var j = null;
                    try {
                        j = text ? JSON.parse(text) : null;
                    } catch (e) {
                        var invalidMsg = 'Could not load Google Ads data';
                        if (r.status) {
                            invalidMsg += ' (HTTP ' + r.status + ')';
                        }
                        throw new Error(invalidMsg);
                    }
                    return { ok: r.ok, j: j };
                });
            })
            .then(function (x) {
                if (!x.ok || !x.j || x.j.ok !== true) {
                    var msg = (x.j && x.j.error) ? String(x.j.error) : 'Could not load Google Ads data.';
                    if (statusEl) statusEl.textContent = msg;
                    if (bodyEl) bodyEl.innerHTML = '<tr><td colspan="3">' + esc(msg) + '</td></tr>';
                    return;
                }
                if (statusEl) statusEl.textContent = '';
                renderRows(x.j.results || [], Number(x.j.total || 0), Number(x.j.total_calls || 0));
            })
            .catch(function (err) {
                var msg = (err && err.message) ? String(err.message) : 'Network error while loading Google Ads data.';
                if (statusEl) statusEl.textContent = msg;
                if (bodyEl) bodyEl.innerHTML = '<tr><td colspan="3">' + esc(msg) + '</td></tr>';
            });
    }

    if (dateInput) {
        dateInput.addEventListener('change', function () {
            var nextDate = String(dateInput.value || '').trim();
            if (nextDate !== appliedDate) {
                hideTable();
            }
        });
        dateInput.addEventListener('input', function () {
            var nextDate = String(dateInput.value || '').trim();
            if (nextDate !== appliedDate) {
                hideTable();
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            loadData();
        });
    }
    loadData();
})();
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
