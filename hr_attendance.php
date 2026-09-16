<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/greythr.php';
require_hr_access();

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$date = isset($_GET['date']) ? trim((string) $_GET['date']) : $today;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    $date = $today;
}
// Default Present only on first open; allow uncheck via present=0 after Apply.
if (!array_key_exists('present', $_GET) && !isset($_GET['date']) && !isset($_GET['page'])) {
    $presentOnly = true;
} else {
    $presentOnly = isset($_GET['present']) && (string) $_GET['present'] === '1';
}

$error = null;
$rows = [];
$totalPages = 1;
$totalElements = 0;

/**
 * @param list<array<string,mixed>> $insightRows
 * @param array<int,string> $nameMap
 * @return list<array{employee_id:int,name:string,in_time:string,out_time:string,present:bool}>
 */
function hr_attendance_normalize(array $insightRows, array $nameMap): array
{
    $out = [];
    foreach ($insightRows as $row) {
        $eid = (int) ($row['employee'] ?? 0);
        $insights = is_array($row['insights'] ?? null) ? $row['insights'] : [];
        $inTime = greythr_insight_average($insights, 'inTime');
        $outTime = greythr_insight_average($insights, 'outTime');
        $name = $nameMap[$eid] ?? ('Employee #' . $eid);
        $out[] = [
            'employee_id' => $eid,
            'name' => $name,
            'in_time' => $inTime !== '' ? $inTime : '—',
            'out_time' => $outTime !== '' ? $outTime : '—',
            'present' => greythr_is_present_intime($inTime),
        ];
    }

    return $out;
}

// 1) Get Employees API — walk all pages (totalPages) and collate by employeeId
$empAll = greythr_fetch_all_employees();
$nameMap = is_array($empAll['map'] ?? null) ? $empAll['map'] : [];
if (!($empAll['ok'] ?? false) || $nameMap === []) {
    $error = (string) ($empAll['error'] ?? 'Could not load employees for name lookup.');
} elseif ($presentOnly) {
    // 2) Attendance API (all pages) → present employees only (has inTime)
    $allRaw = [];
    $apiUiPage = 1;
    $attTotalPages = 1;
    $guard = 0;
    while ($guard < 40) {
        $guard++;
        $res = greythr_attendance_insights($date, $date, $apiUiPage, 50);
        if (!($res['ok'] ?? false)) {
            $error = (string) ($res['error'] ?? 'Could not load attendance.');
            break;
        }
        foreach ($res['rows'] as $r) {
            $allRaw[] = $r;
        }
        $pagesMeta = $res['pages'];
        $attTotalPages = is_array($pagesMeta) ? max(1, (int) ($pagesMeta['totalPages'] ?? 1)) : 1;
        if ($apiUiPage >= $attTotalPages) {
            break;
        }
        $apiUiPage++;
    }
    if ($error === null) {
        $normalized = array_values(array_filter(
            hr_attendance_normalize($allRaw, $nameMap),
            static fn (array $r): bool => !empty($r['present'])
        ));
        $totalElements = count($normalized);
        $totalPages = max(1, (int) ceil($totalElements / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $rows = array_slice($normalized, $offset, $perPage);
    }
} else {
    // 2) Attendance API → all employees for date
    $res = greythr_attendance_insights($date, $date, $page, $perPage);
    if (!($res['ok'] ?? false)) {
        $error = (string) ($res['error'] ?? 'Could not load attendance.');
    } else {
        $pagesMeta = $res['pages'];
        if (is_array($pagesMeta)) {
            $totalPages = max(1, (int) ($pagesMeta['totalPages'] ?? 1));
            $totalElements = (int) ($pagesMeta['totalElements'] ?? count($res['rows']));
        }
        if ($page > $totalPages) {
            $page = $totalPages;
            $res = greythr_attendance_insights($date, $date, $page, $perPage);
        }
        if ($res['ok'] ?? false) {
            $rows = hr_attendance_normalize($res['rows'], $nameMap);
            if (is_array($res['pages'] ?? null)) {
                $totalPages = max(1, (int) ($res['pages']['totalPages'] ?? 1));
                $totalElements = (int) ($res['pages']['totalElements'] ?? count($rows));
            }
        }
    }
}

$queryBase = ['date' => $date, 'present' => $presentOnly ? '1' : '0'];

$pageTitle = 'Attendance';
$activeNav = 'hr_attendance';
require __DIR__ . '/includes/layout_start.php';
?>
<div class="card">
    <div class="card__head">
        <h1 style="margin:0;font-size:1.25rem">Attendance</h1>
    </div>
    <div class="card__body">
        <form method="get" action="hr_attendance.php" class="form form--inline-sales-period" id="hr-attendance-form" autocomplete="off">
            <div class="form__row">
                <label for="hr_att_date">Date</label>
                <input id="hr_att_date" name="date" type="date" required value="<?= e($date) ?>" max="<?= e($today) ?>">
            </div>
            <div class="form__row form__row--check">
                <input type="hidden" name="present" value="0">
                <label class="check-label" for="hr_att_present">
                    <input id="hr_att_present" type="checkbox" name="present" value="1"<?= $presentOnly ? ' checked' : '' ?>>
                    <span>Present only (has inTime)</span>
                </label>
            </div>
            <div class="form__row form__row--submit">
                <button type="submit" class="btn btn--primary" id="hr-att-apply-btn">Apply</button>
            </div>
        </form>
        <div id="hr-att-loading" class="hr-att-loading" aria-live="polite">
            <span class="hr-att-spinner" aria-hidden="true"></span>
            <span>Loading attendance…</span>
        </div>

        <div id="hr-att-results">
        <?php if ($error !== null): ?>
            <p class="alert alert--error" style="margin:1rem 0 0"><?= e($error) ?></p>
        <?php elseif (count($rows) === 0): ?>
            <p class="empty" style="margin-top:1rem">No attendance records for <?= e($date) ?>.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:1rem">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Employee Name</th>
                            <th>In Time</th>
                            <th>Out Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td>
                                    <?php if ((int) $r['employee_id'] > 0): ?>
                                        <a class="link--underlined" href="hr_employees.php?id=<?= (int) $r['employee_id'] ?>"><?= e((string) $r['name']) ?></a>
                                    <?php else: ?>
                                        <?= e((string) $r['name']) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) $r['in_time']) ?></td>
                                <td><?= e((string) $r['out_time']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="leads-pagination" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem 0.85rem;padding:1rem 0 0;margin:0;justify-content:center">
                    <?php if ($page > 1): ?>
                        <a class="btn btn--ghost hr-att-page-link" href="hr_attendance.php?<?= e(http_build_query(array_merge($queryBase, ['page' => $page - 1]))) ?>">Previous</a>
                    <?php endif; ?>
                    <span style="font-size:.9rem;color:var(--muted, #64748b)">
                        Page <?= (int) $page ?> of <?= (int) $totalPages ?>
                        <?php if ($totalElements > 0): ?>
                            · <?= (int) $totalElements ?> records
                        <?php endif; ?>
                    </span>
                    <?php if ($page < $totalPages): ?>
                        <a class="btn btn--ghost hr-att-page-link" href="hr_attendance.php?<?= e(http_build_query(array_merge($queryBase, ['page' => $page + 1]))) ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
</div>
<style>
.hr-att-loading {
  display: none;
  align-items: center;
  gap: 0.55rem;
  margin: 1rem 0 0;
  color: #334155;
  font-size: 0.9rem;
}
.hr-att-loading.is-visible {
  display: flex;
}
.hr-att-spinner {
  display: inline-block;
  width: 22px;
  height: 22px;
  border: 3px solid #c9d8ea;
  border-top-color: #2f5f90;
  border-radius: 50%;
  animation: hrAttSpin 0.8s linear infinite;
  flex: 0 0 auto;
}
@keyframes hrAttSpin {
  to { transform: rotate(360deg); }
}
#hr-attendance-form.form--inline-sales-period .form__row--check {
  display: flex;
  align-items: flex-end;
  padding-bottom: 0.35rem;
}
</style>
<script>
(function () {
  var form = document.getElementById('hr-attendance-form');
  var loading = document.getElementById('hr-att-loading');
  var btn = document.getElementById('hr-att-apply-btn');
  var results = document.getElementById('hr-att-results');
  var dateEl = document.getElementById('hr_att_date');
  var presentEl = document.getElementById('hr_att_present');
  var loadedDate = dateEl ? String(dateEl.value || '') : '';
  var loadedPresent = presentEl ? !!presentEl.checked : false;

  function showLoader() {
    if (results) results.hidden = true;
    if (loading) {
      loading.classList.add('is-visible');
      loading.setAttribute('aria-busy', 'true');
    }
    if (btn) {
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
    }
  }
  function hideLoader() {
    if (loading) {
      loading.classList.remove('is-visible');
      loading.removeAttribute('aria-busy');
    }
    if (btn) {
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
    }
  }
  function syncResultsVisibility() {
    if (!results || !dateEl) return;
    var dateChanged = String(dateEl.value || '') !== loadedDate;
    var presentChanged = presentEl ? (!!presentEl.checked !== loadedPresent) : false;
    results.hidden = dateChanged || presentChanged;
  }
  hideLoader();
  if (dateEl) {
    dateEl.addEventListener('change', syncResultsVisibility);
    dateEl.addEventListener('input', syncResultsVisibility);
  }
  if (presentEl) {
    presentEl.addEventListener('change', syncResultsVisibility);
  }
  if (form) {
    form.addEventListener('submit', showLoader);
  }
  document.querySelectorAll('.hr-att-page-link').forEach(function (a) {
    a.addEventListener('click', showLoader);
  });
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) hideLoader();
  });
})();
</script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
