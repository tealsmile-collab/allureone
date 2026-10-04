<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/greythr.php';
require_once __DIR__ . '/includes/hr_employee_db.php';
require_hr_attendance_access();

allurehr_ensure_employee_table();

$user = current_user();
$userBranchId = isset($user['branch_id']) && (int) $user['branch_id'] > 0 ? (int) $user['branch_id'] : 0;
$roleId = (int) ($user['role_id'] ?? 0);
$isHrAdmin = ($roleId === ROLE_SUPERADMIN || $roleId === ROLE_ADMIN);

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$date = isset($_GET['date']) ? trim((string) $_GET['date']) : $today;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    $date = $today;
}
$isToday = ($date === $today);
// Load greytHR only after Apply/form submit (date in query) or pagination.
// Note: pressing Enter in the date field may omit the Apply button's `apply` param.
$loadData = isset($_GET['date']) || isset($_GET['apply']) || isset($_GET['page']);
// Default Present only checked until user applies.
if (!$loadData) {
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

/**
 * @param list<array{employeeId:int,name:string,locality?:string}> $employees
 * @return list<array{employee_id:int,name:string,locality:string,in_time:string,out_time:string,present:bool}>
 */
function hr_attendance_load_today_swipes(array $employees, string $date, bool $withLocalityLabel): array
{
    $empIds = [];
    $metaById = [];
    foreach ($employees as $emp) {
        $eid = (int) ($emp['employeeId'] ?? 0);
        if ($eid <= 0) {
            continue;
        }
        $empIds[] = $eid;
        $name = trim((string) ($emp['name'] ?? ''));
        if ($name === '') {
            $name = 'Employee #' . $eid;
        }
        $locality = trim((string) ($emp['locality'] ?? ''));
        $metaById[$eid] = ['name' => $name, 'locality' => $locality];
    }
    if ($empIds === []) {
        return [];
    }

    $swipeMap = greythr_employee_swipes_batch($empIds, $date, $date, true, 10);
    $normalized = [];
    foreach ($empIds as $eid) {
        $swipeRes = $swipeMap[$eid] ?? ['ok' => false, 'list' => []];
        $inTime = '';
        $outTime = '';
        if ($swipeRes['ok'] ?? false) {
            $clocks = greythr_swipes_in_out($swipeRes['list'] ?? []);
            $inTime = (string) ($clocks['in_time'] ?? '');
            $outTime = (string) ($clocks['out_time'] ?? '');
        }
        $meta = $metaById[$eid] ?? ['name' => 'Employee #' . $eid, 'locality' => ''];
        $displayName = (string) $meta['name'];
        $locality = (string) ($meta['locality'] ?? '');
        if ($withLocalityLabel && $locality !== '') {
            $displayName .= ' (' . $locality . ')';
        }
        $normalized[] = [
            'employee_id' => $eid,
            'name' => $displayName,
            'locality' => $locality,
            'in_time' => $inTime !== '' ? $inTime : '—',
            'out_time' => $outTime !== '' ? $outTime : '—',
            'present' => greythr_is_present_intime($inTime),
        ];
    }

    return $normalized;
}

if ($loadData && $isToday) {
    // Today: swipes API (parallel). Admin/superadmin = all branches; others = own branch.
    @set_time_limit(180);
    if ($isHrAdmin) {
        $branchEmployees = allurehr_employees_all_with_locality();
        if ($branchEmployees === []) {
            $error = 'No employees with BranchID found. Sync employees and assign branches in Employee List first.';
        } else {
            $normalized = hr_attendance_load_today_swipes($branchEmployees, $date, true);
            if ($presentOnly) {
                $normalized = array_values(array_filter(
                    $normalized,
                    static fn (array $r): bool => !empty($r['present'])
                ));
            }
            // Group by locality, then present first, then name.
            usort($normalized, static function (array $a, array $b): int {
                $locCmp = strcasecmp((string) ($a['locality'] ?? ''), (string) ($b['locality'] ?? ''));
                if ($locCmp !== 0) {
                    return $locCmp;
                }
                $ap = !empty($a['present']) ? 0 : 1;
                $bp = !empty($b['present']) ? 0 : 1;
                if ($ap !== $bp) {
                    return $ap <=> $bp;
                }

                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            });
            $totalElements = count($normalized);
            $totalPages = max(1, (int) ceil($totalElements / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;
            $rows = array_slice($normalized, $offset, $perPage);
        }
    } elseif ($userBranchId <= 0) {
        $error = 'Your user account has no branch assigned. Set Branch in User Master to load today’s attendance.';
    } else {
        $branchEmployees = allurehr_employees_by_branch($userBranchId);
        if ($branchEmployees === []) {
            $error = 'No employees found for your branch in Employee List. Sync employees and assign BranchID first.';
        } else {
            $normalized = hr_attendance_load_today_swipes($branchEmployees, $date, false);
            if ($presentOnly) {
                $normalized = array_values(array_filter(
                    $normalized,
                    static fn (array $r): bool => !empty($r['present'])
                ));
            }
            usort($normalized, static function (array $a, array $b): int {
                $ap = !empty($a['present']) ? 0 : 1;
                $bp = !empty($b['present']) ? 0 : 1;
                if ($ap !== $bp) {
                    return $ap <=> $bp;
                }

                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            });
            $totalElements = count($normalized);
            $totalPages = max(1, (int) ceil($totalElements / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;
            $rows = array_slice($normalized, $offset, $perPage);
        }
    }
} elseif ($loadData) {
    // Past dates: existing insights API.
    $empAll = greythr_fetch_all_employees();
    $nameMap = is_array($empAll['map'] ?? null) ? $empAll['map'] : [];
    if (!($empAll['ok'] ?? false) || $nameMap === []) {
        $error = (string) ($empAll['error'] ?? 'Could not load employees for name lookup.');
    } elseif ($presentOnly) {
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
}

$queryBase = ['apply' => '1', 'date' => $date, 'present' => $presentOnly ? '1' : '0'];

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
                <input type="hidden" name="apply" value="1">
                <button type="submit" class="btn btn--primary" id="hr-att-apply-btn">Apply</button>
            </div>
        </form>
        <div id="hr-att-loading" class="hr-att-loading" aria-live="polite">
            <span class="hr-att-spinner" aria-hidden="true"></span>
            <span>Loading attendance…</span>
        </div>

        <div id="hr-att-results">
        <?php if (!$loadData): ?>
            <p class="empty" style="margin-top:1rem">Select a date and click Apply to load attendance.</p>
        <?php elseif ($error !== null): ?>
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
                                    <?php if ($isHrAdmin && (int) $r['employee_id'] > 0): ?>
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
