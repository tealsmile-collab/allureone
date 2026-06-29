<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/gift_helpers.php';
require_login();
require_not_accounts_role();
require_not_franchise_officer_role();

$curY = (int) date('Y');
$curM = (int) date('n');
$user = current_user();
$salesTargetShowProjection = ((int) ($user['role_id'] ?? 0) === ROLE_ADMIN);

$pageTitle = 'Sales target';
$activeNav = 'sales_target';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="card">
    <div class="card__head">
        <span>Sales target report</span>
    </div>
    <div class="card__body" style="padding:1.25rem">
        <form class="form form--inline-sales-period" id="sales-target-form" method="get" action="sales_target.php">
            <div class="form__row form__row--month">
                <label for="sales_m">Month</label>
                <select id="sales_m" name="m">
                    <?php
                    $monthNames = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
                    foreach ($monthNames as $mi => $label) {
                        ?>
                        <option value="<?= $mi ?>"<?= $curM === $mi ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php
                    }
                    ?>
                </select>
            </div>
            <div class="form__row form__row--year">
                <label for="sales_y">Year</label>
                <select id="sales_y" name="y">
                    <?php
                    for ($yy = $curY - 5; $yy <= $curY + 2; $yy++) {
                        ?>
                        <option value="<?= $yy ?>"<?= $curY === $yy ? ' selected' : '' ?>><?= $yy ?></option>
                        <?php
                    }
                    ?>
                </select>
            </div>
            <div class="form__row form__row--submit sales-target-actions">
                <button class="btn btn--primary" type="submit">Load report</button>
                <button class="btn btn--secondary" type="button" id="sales-target-excel" style="display:none">Download Excel</button>
            </div>
        </form>
        <p class="main__meta" id="sales-target-period" style="margin:0 0 1rem"></p>
        <p id="sales-target-status" class="main__meta" style="margin:0 0 1rem"></p>
        <div class="table-wrap sales-target-table-wrap" id="sales-target-table-wrap" style="display:none">
            <table class="data sales-target-table">
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th>Monthly Target</th>
                        <th>MTD</th>
                        <th>% of sales Vs Target</th>
                        <th>Expected Avg</th>
                        <th>MTD Avg</th>
                        <th>Remaining Sale</th>
                        <th>Remaining Expected Avg.</th>
                        <?php if ($salesTargetShowProjection): ?>
                        <th>Projection</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="sales-target-body">
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.sales-target-spinner {
    display: inline-block;
    width: 24px;
    height: 24px;
    border: 3px solid #c9d8ea;
    border-top-color: #2f5f90;
    border-radius: 50%;
    animation: salesTargetSpin 0.8s linear infinite;
    vertical-align: middle;
}
@keyframes salesTargetSpin {
    to { transform: rotate(360deg); }
}
.sales-target-table-wrap {
    overflow-x: auto;
    max-width: 100%;
}
.sales-target-table {
    min-width: 960px;
}
.sales-target-table th,
.sales-target-table td {
    white-space: nowrap;
}
.sales-target-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
}
</style>

<script>
(function () {
    var form = document.getElementById('sales-target-form');
    var monthEl = document.getElementById('sales_m');
    var yearEl = document.getElementById('sales_y');
    var periodEl = document.getElementById('sales-target-period');
    var statusEl = document.getElementById('sales-target-status');
    var tableWrap = document.getElementById('sales-target-table-wrap');
    var bodyEl = document.getElementById('sales-target-body');
    var excelBtn = document.getElementById('sales-target-excel');
    var apiUrl = <?= json_encode(allureone_url('sales_target_api.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var loadingHtml = '<span class="sales-target-spinner" aria-hidden="true"></span>';
    var appliedMonth = '';
    var appliedYear = '';
    var loadedRows = [];
    var loadedPeriod = null;
    var showProjectionColumn = <?= $salesTargetShowProjection ? 'true' : 'false' ?>;
    var tableColCount = showProjectionColumn ? 9 : 8;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s || '');
        return d.innerHTML;
    }

    function formatAmount(val) {
        if (val === null || val === undefined || val === '') {
            return '';
        }
        var n = Number(val);
        if (!isFinite(n)) {
            return '';
        }
        return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatInt(val) {
        if (val === null || val === undefined || val === '') {
            return '';
        }
        var n = Number(val);
        if (!isFinite(n)) {
            return '';
        }
        return Math.trunc(n).toLocaleString('en-IN');
    }

    function formatPercentVsTarget(mtd, target, withSign) {
        if (mtd === null || mtd === undefined || mtd === '' || target === null || target === undefined || target === '') {
            return '';
        }
        var achieved = Number(mtd);
        var monthlyTarget = Number(target);
        if (!isFinite(achieved) || !isFinite(monthlyTarget) || monthlyTarget <= 0) {
            return '';
        }
        var val = (achieved / monthlyTarget * 100).toFixed(2);
        return withSign ? val + '%' : val;
    }

    function sortRowsByData(rows) {
        if (!Array.isArray(rows)) {
            return [];
        }
        var withData = [];
        var withoutData = [];
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i] || {};
            if (row.monthly_target != null || row.mtd != null) {
                withData.push(row);
            } else {
                withoutData.push(row);
            }
        }
        return withData.concat(withoutData);
    }

    function excelNumber(val, decimals) {
        if (val === null || val === undefined || val === '') {
            return '';
        }
        var n = Number(val);
        if (!isFinite(n)) {
            return '';
        }
        if (decimals !== undefined) {
            return n.toFixed(decimals);
        }
        return String(Math.trunc(n));
    }

    function renderRows(rows) {
        if (!bodyEl) {
            return;
        }
        rows = sortRowsByData(rows);
        if (!Array.isArray(rows) || rows.length === 0) {
            bodyEl.innerHTML = '<tr><td colspan="' + tableColCount + '">No branches to display.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i] || {};
            html += '<tr>';
            html += '<td>' + esc(row.branch_name || '') + '</td>';
            html += '<td>' + esc(formatAmount(row.monthly_target)) + '</td>';
            html += '<td>' + esc(formatAmount(row.mtd)) + '</td>';
            html += '<td>' + esc(formatPercentVsTarget(row.mtd, row.monthly_target, true)) + '</td>';
            html += '<td>' + esc(formatInt(row.expected_avg)) + '</td>';
            html += '<td>' + esc(formatInt(row.mtd_avg)) + '</td>';
            html += '<td>' + esc(formatInt(row.remaining_sale)) + '</td>';
            html += '<td>' + esc(formatInt(row.remaining_expected_avg)) + '</td>';
            if (showProjectionColumn) {
                html += '<td>' + esc(formatInt(row.projection)) + '</td>';
            }
            html += '</tr>';
        }
        bodyEl.innerHTML = html;
    }

    function hideReport() {
        loadedRows = [];
        loadedPeriod = null;
        if (tableWrap) {
            tableWrap.style.display = 'none';
        }
        if (excelBtn) {
            excelBtn.style.display = 'none';
        }
        if (periodEl) {
            periodEl.textContent = '';
        }
        if (statusEl) {
            statusEl.textContent = '';
        }
        if (bodyEl) {
            bodyEl.innerHTML = '';
        }
    }

    function csvCell(val) {
        var s = String(val == null ? '' : val);
        if (/[",\n\r]/.test(s)) {
            return '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    }

    function downloadExcel() {
        if (!loadedRows.length) {
            return;
        }
        var headers = [
            'Branch',
            'Monthly Target',
            'MTD',
            '% of sales Vs Target',
            'Expected Avg',
            'MTD Avg',
            'Remaining Sale',
            'Remaining Expected Avg.'
        ];
        if (showProjectionColumn) {
            headers.push('Projection');
        }
        var lines = [headers.map(csvCell).join(',')];
        var exportRows = sortRowsByData(loadedRows);
        for (var i = 0; i < exportRows.length; i++) {
            var row = exportRows[i] || {};
            var cells = [
                row.branch_name || '',
                excelNumber(row.monthly_target, 2),
                excelNumber(row.mtd, 2),
                formatPercentVsTarget(row.mtd, row.monthly_target),
                excelNumber(row.expected_avg),
                excelNumber(row.mtd_avg),
                excelNumber(row.remaining_sale),
                excelNumber(row.remaining_expected_avg)
            ];
            if (showProjectionColumn) {
                cells.push(excelNumber(row.projection));
            }
            lines.push(cells.map(csvCell).join(','));
        }
        var blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'application/vnd.ms-excel;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        var period = loadedPeriod || {};
        var fname = 'sales-target';
        if (period.start) {
            fname += '-' + String(period.start).slice(0, 7);
        } else if (appliedYear && appliedMonth) {
            fname += '-' + appliedYear + '-' + String(appliedMonth).padStart(2, '0');
        }
        a.href = url;
        a.download = fname + '.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function onPeriodChange() {
        if (!monthEl || !yearEl) {
            return;
        }
        var nextM = String(monthEl.value || '').trim();
        var nextY = String(yearEl.value || '').trim();
        if (appliedMonth !== '' && appliedYear !== '' && (nextM !== appliedMonth || nextY !== appliedYear)) {
            hideReport();
        }
    }

    function loadReport() {
        if (!monthEl || !yearEl) {
            return;
        }
        var m = String(monthEl.value || '').trim();
        var y = String(yearEl.value || '').trim();
        appliedMonth = m;
        appliedYear = y;
        if (excelBtn) {
            excelBtn.style.display = 'none';
        }
        if (tableWrap) {
            tableWrap.style.display = '';
        }
        if (statusEl) {
            statusEl.innerHTML = loadingHtml;
        }
        if (bodyEl) {
            bodyEl.innerHTML = '<tr><td colspan="' + tableColCount + '" style="text-align:center">' + loadingHtml + '</td></tr>';
        }
        if (periodEl) {
            periodEl.textContent = '';
        }

        fetch(apiUrl + '?y=' + encodeURIComponent(y) + '&m=' + encodeURIComponent(m), {
            credentials: 'same-origin'
        })
            .then(function (r) {
                return r.text().then(function (text) {
                    var j = null;
                    try {
                        j = text ? JSON.parse(text) : null;
                    } catch (e) {
                        var invalidMsg = 'Could not load sales target data';
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
                    var msg = (x.j && x.j.error) ? String(x.j.error) : 'Could not load sales target data.';
                    if (statusEl) {
                        statusEl.textContent = msg;
                    }
                    if (bodyEl) {
                        bodyEl.innerHTML = '<tr><td colspan="' + tableColCount + '">' + esc(msg) + '</td></tr>';
                    }
                    hideReport();
                    appliedMonth = m;
                    appliedYear = y;
                    if (tableWrap) {
                        tableWrap.style.display = '';
                    }
                    return;
                }
                var period = x.j.period || {};
                loadedPeriod = period;
                loadedRows = sortRowsByData(Array.isArray(x.j.rows) ? x.j.rows.slice() : []);
                appliedMonth = m;
                appliedYear = y;
                if (periodEl && period.start && period.end) {
                    var meta = 'Period: ' + period.start + ' to ' + period.end;
                    if (period.days_remaining !== undefined) {
                        meta += ' · Days remaining in month: ' + period.days_remaining;
                    }
                    periodEl.textContent = meta;
                }
                if (statusEl) {
                    statusEl.textContent = '';
                }
                renderRows(loadedRows);
                if (excelBtn && loadedRows.length > 0) {
                    excelBtn.style.display = '';
                }
            })
            .catch(function (err) {
                var msg = (err && err.message) ? String(err.message) : 'Could not load sales target data.';
                if (statusEl) {
                    statusEl.textContent = msg;
                }
                if (bodyEl) {
                    bodyEl.innerHTML = '<tr><td colspan="' + tableColCount + '">' + esc(msg) + '</td></tr>';
                }
                hideReport();
                appliedMonth = m;
                appliedYear = y;
                if (tableWrap) {
                    tableWrap.style.display = '';
                }
            });
    }

    if (monthEl) {
        monthEl.addEventListener('change', onPeriodChange);
    }
    if (yearEl) {
        yearEl.addEventListener('change', onPeriodChange);
    }
    if (excelBtn) {
        excelBtn.addEventListener('click', downloadExcel);
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            loadReport();
        });
    }
})();
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
