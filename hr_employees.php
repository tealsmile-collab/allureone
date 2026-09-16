<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/greythr.php';
require_once __DIR__ . '/includes/hr_employee_db.php';
require_hr_access();

allurehr_ensure_employee_table();

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$employeeId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$view = strtolower(trim((string) ($_GET['view'] ?? '')));
$showAttendance = ($employeeId > 0 && $view === 'attendance');
$doSync = isset($_GET['sync']) && (string) $_GET['sync'] === '1' && $employeeId <= 0;

$tz = new DateTimeZone('Asia/Kolkata');
$nowIst = new DateTimeImmutable('now', $tz);
$curYear = (int) $nowIst->format('Y');
$curMonth = (int) $nowIst->format('n');
$attYear = isset($_GET['y']) ? (int) $_GET['y'] : $curYear;
$attMonth = isset($_GET['m']) ? (int) $_GET['m'] : $curMonth;
if ($attYear < 2000 || $attYear > 2100) {
    $attYear = $curYear;
}
if ($attMonth < 1 || $attMonth > 12) {
    $attMonth = $curMonth;
}

$error = null;
$flash = null;
$employees = [];
$employee = null;
$totalPages = 1;
$totalElements = 0;
$musterRows = [];
$monthStart = '';
$monthEnd = '';
$localEmployee = null;
$branchOptions = allurehr_active_branch_options();
$roleOptions = allurehr_role_options();
$filterBranchId = max(0, (int) ($_GET['branch'] ?? 0));

if ($employeeId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_local_hr'])) {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Invalid session. Please refresh and try again.'];
    } else {
        $nick = isset($_POST['nickname']) ? trim((string) $_POST['nickname']) : '';
        $branchRaw = isset($_POST['branch_id']) ? trim((string) $_POST['branch_id']) : '';
        $branchId = $branchRaw === '' ? null : (int) $branchRaw;
        $roleRaw = isset($_POST['role_id']) ? trim((string) $_POST['role_id']) : '';
        $roleId = $roleRaw === '' ? null : (int) $roleRaw;
        $saveRes = allurehr_save_local_employee($employeeId, $nick, $branchId, $roleId);
        if ($saveRes['ok'] ?? false) {
            $flash = ['type' => 'ok', 'text' => 'Nickname, branch and role saved.'];
        } else {
            $flash = ['type' => 'error', 'text' => (string) ($saveRes['error'] ?? 'Save failed.')];
        }
    }
}

if ($doSync) {
    $syncRes = allurehr_sync_new_employees();
    if ($syncRes['ok'] ?? false) {
        $ins = (int) ($syncRes['inserted'] ?? 0);
        $apiC = (int) ($syncRes['api_count'] ?? 0);
        $dbC = (int) ($syncRes['db_count_after'] ?? 0);
        $pages = (int) ($syncRes['pages_fetched'] ?? 0);
        $dinggUpd = (int) ($syncRes['dingg_updated'] ?? 0);
        $dinggSkip = (int) ($syncRes['dingg_skipped'] ?? 0);
        $dinggBranches = (int) ($syncRes['dingg_branches'] ?? 0);
        $dinggErr = trim((string) ($syncRes['dingg_error'] ?? ''));
        $dinggBit = " Dingg: {$dinggUpd} nickname/branch updated";
        if ($dinggSkip > 0) {
            $dinggBit .= ", {$dinggSkip} already set";
        }
        $dinggBit .= " ({$dinggBranches} branch" . ($dinggBranches === 1 ? '' : 'es') . ').';
        if ($dinggErr !== '') {
            $dinggBit .= ' ' . $dinggErr;
        }
        if ($ins > 0) {
            $flash = ['type' => 'ok', 'text' => "Sync complete: {$ins} new employee(s) added from {$pages} API page(s). API {$apiC} · DB {$dbC}." . $dinggBit];
        } else {
            $flash = ['type' => 'ok', 'text' => "Sync complete: no new employees ({$pages} API page(s)). API {$apiC} · DB {$dbC}." . $dinggBit];
        }
    } else {
        $flash = ['type' => 'error', 'text' => (string) ($syncRes['error'] ?? 'Sync failed.')];
    }
}

if ($employeeId > 0) {
    $res = greythr_get_employee($employeeId);
    if (!($res['ok'] ?? false)) {
        $error = (string) ($res['error'] ?? 'Could not load employee.');
    } else {
        $employee = $res['employee'];
        // Ensure row exists locally with name
        allurehr_upsert_employees_from_api([$employee]);
        $localEmployee = allurehr_get_local_employee($employeeId);
    }

    if ($showAttendance && $employee !== null) {
        $monthStartDt = DateTimeImmutable::createFromFormat('Y-n-j', $attYear . '-' . $attMonth . '-1', $tz);
        if ($monthStartDt === false) {
            $monthStartDt = $nowIst->modify('first day of this month');
            $attYear = (int) $monthStartDt->format('Y');
            $attMonth = (int) $monthStartDt->format('n');
        }
        $monthStartDt = $monthStartDt->setTime(0, 0, 0);
        $monthEndDt = $monthStartDt->modify('last day of this month');
        if ($attYear === $curYear && $attMonth === $curMonth && $monthEndDt > $nowIst) {
            $monthEndDt = $nowIst;
        }
        $monthStart = $monthStartDt->format('Y-m-d');
        $monthEnd = $monthEndDt->format('Y-m-d');

        $musterRes = greythr_employee_muster($employeeId, $monthStart, $monthEnd);
        if (!($musterRes['ok'] ?? false)) {
            $error = (string) ($musterRes['error'] ?? 'Could not load attendance muster.');
        } else {
            $musterRows = greythr_muster_table_rows($musterRes['records']);
        }
    }
} else {
    $list = allurehr_list_local_employees($page, $perPage, $filterBranchId);
    $employees = $list['rows'];
    $totalPages = max(1, (int) ($list['total_pages'] ?? 1));
    $totalElements = (int) ($list['total'] ?? count($employees));
    if ($page > $totalPages) {
        $page = $totalPages;
        $list = allurehr_list_local_employees($page, $perPage, $filterBranchId);
        $employees = $list['rows'];
        $totalPages = max(1, (int) ($list['total_pages'] ?? 1));
        $totalElements = (int) ($list['total'] ?? count($employees));
    }
}

function hr_emp_display(mixed $v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    if (is_bool($v)) {
        return $v ? 'Yes' : 'No';
    }

    return (string) $v;
}

function hr_format_display_date(string $ymd): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
    if ($dt === false) {
        return $ymd;
    }

    return $dt->format('d-m-Y');
}

$empName = is_array($employee) ? greythr_employee_display_name($employee) : '';
$hrListQuery = [];
if ($filterBranchId > 0) {
    $hrListQuery['branch'] = $filterBranchId;
}
$listParams = $hrListQuery;
if ($page > 1) {
    $listParams['page'] = $page;
}
$listHref = $listParams === [] ? 'hr_employees.php' : ('hr_employees.php?' . http_build_query($listParams));
$detailQuery = $hrListQuery + ['id' => $employeeId];
if ($page > 1) {
    $detailQuery['page'] = $page;
}
$detailHref = 'hr_employees.php?' . http_build_query($detailQuery);
$attendanceHref = 'hr_employees.php?' . http_build_query($detailQuery + ['view' => 'attendance']);
$syncParams = $hrListQuery + ['sync' => 1];
if ($page > 1) {
    $syncParams['page'] = $page;
}
$syncHref = 'hr_employees.php?' . http_build_query($syncParams);

$formNick = '';
$formBranchId = 0;
$formRoleId = 0;
if (is_array($localEmployee)) {
    $formNick = trim((string) ($localEmployee['NickName'] ?? ''));
    $formBranchId = (int) ($localEmployee['BranchID'] ?? 0);
    $formRoleId = (int) ($localEmployee['RoleID'] ?? 0);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_local_hr'])) {
    $formNick = isset($_POST['nickname']) ? trim((string) $_POST['nickname']) : $formNick;
    $formBranchId = isset($_POST['branch_id']) ? (int) $_POST['branch_id'] : $formBranchId;
    $formRoleId = isset($_POST['role_id']) ? (int) $_POST['role_id'] : $formRoleId;
}

$pageTitle = $showAttendance
    ? ('Attendance · ' . ($empName !== '' ? $empName : ('#' . $employeeId)))
    : ($employeeId > 0 ? 'Employee Details' : 'Employee List');
$activeNav = 'hr_employees';
require __DIR__ . '/includes/layout_start.php';

$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
?>
<div class="card">
    <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap">
        <h1 style="margin:0;font-size:1.25rem"><?= e($pageTitle) ?></h1>
        <?php if ($employeeId <= 0 && !$showAttendance): ?>
            <a class="btn btn--primary" href="<?= e($syncHref) ?>" id="hr-emp-sync-btn">Sync</a>
        <?php endif; ?>
    </div>
    <div class="card__body">
        <?php if ($flash !== null): ?>
            <p class="alert alert--<?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'error' ?>" style="margin:0 0 1rem"><?= e((string) ($flash['text'] ?? '')) ?></p>
        <?php endif; ?>
        <?php if ($error !== null && !$showAttendance): ?>
            <p class="alert alert--error" style="margin:0 0 1rem"><?= e($error) ?></p>
        <?php endif; ?>

        <?php if ($showAttendance && $employee !== null): ?>
            <p style="margin:0 0 1rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center">
                <a class="btn btn--ghost" href="<?= e($detailHref) ?>">← Back to details</a>
                <a class="btn btn--ghost" href="<?= e($listHref) ?>">Employee list</a>
            </p>

            <form method="get" action="hr_employees.php" class="form form--inline-sales-period" id="hr-emp-att-form" autocomplete="off">
                <input type="hidden" name="id" value="<?= (int) $employeeId ?>">
                <input type="hidden" name="view" value="attendance">
                <?php if ($page > 1): ?>
                    <input type="hidden" name="page" value="<?= (int) $page ?>">
                <?php endif; ?>
                <?php if ($filterBranchId > 0): ?>
                    <input type="hidden" name="branch" value="<?= (int) $filterBranchId ?>">
                <?php endif; ?>
                <div class="form__row form__row--month">
                    <label for="hr_emp_att_month">Month</label>
                    <select id="hr_emp_att_month" name="m" required>
                        <?php foreach ($monthNames as $num => $label): ?>
                            <option value="<?= (int) $num ?>"<?= $num === $attMonth ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form__row form__row--year">
                    <label for="hr_emp_att_year">Year</label>
                    <select id="hr_emp_att_year" name="y" required>
                        <?php for ($y = $curYear; $y >= $curYear - 5; $y--): ?>
                            <option value="<?= (int) $y ?>"<?= $y === $attYear ? ' selected' : '' ?>><?= (int) $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form__row form__row--submit">
                    <button type="submit" class="btn btn--primary" id="hr-emp-att-apply">Apply</button>
                </div>
            </form>
            <div id="hr-emp-att-loading" class="hr-att-loading" aria-live="polite">
                <span class="hr-att-spinner" aria-hidden="true"></span>
                <span>Loading attendance…</span>
            </div>

            <?php if ($error !== null): ?>
                <p class="alert alert--error" style="margin:1rem 0 0"><?= e($error) ?></p>
            <?php elseif (count($musterRows) === 0): ?>
                <p class="empty" style="margin-top:1rem">No attendance records for <?= e($monthNames[$attMonth] . ' ' . $attYear) ?>.</p>
            <?php else: ?>
                <p class="main__meta" style="margin:0.85rem 0 0.5rem">
                    <?= e($empName) ?> · <?= e(hr_format_display_date($monthStart)) ?> to <?= e(hr_format_display_date($monthEnd)) ?>
                </p>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>In Time</th>
                                <th>Out Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($musterRows as $row): ?>
                                <tr>
                                    <td><?= e(hr_format_display_date($row['date'])) ?></td>
                                    <td><?= e($row['in_time']) ?></td>
                                    <td><?= e($row['out_time']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <style>
            .hr-att-loading { display:none; align-items:center; gap:0.55rem; margin:1rem 0 0; color:#334155; font-size:0.9rem; }
            .hr-att-loading.is-visible { display:flex; }
            .hr-att-spinner {
              display:inline-block; width:22px; height:22px;
              border:3px solid #c9d8ea; border-top-color:#2f5f90; border-radius:50%;
              animation: hrAttSpin 0.8s linear infinite; flex:0 0 auto;
            }
            @keyframes hrAttSpin { to { transform: rotate(360deg); } }
            </style>
            <script>
            (function () {
              var form = document.getElementById('hr-emp-att-form');
              var loading = document.getElementById('hr-emp-att-loading');
              var btn = document.getElementById('hr-emp-att-apply');
              function showLoader() {
                if (loading) loading.classList.add('is-visible');
                if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }
              }
              function hideLoader() {
                if (loading) loading.classList.remove('is-visible');
                if (btn) { btn.disabled = false; btn.removeAttribute('aria-busy'); }
              }
              hideLoader();
              if (form) form.addEventListener('submit', showLoader);
              window.addEventListener('pageshow', function (e) { if (e.persisted) hideLoader(); });
            })();
            </script>

        <?php elseif ($employee !== null): ?>
            <p style="margin:0 0 1rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center">
                <a class="btn btn--ghost" href="<?= e($listHref) ?>">← Back to list</a>
                <a class="btn btn--primary" href="<?= e($attendanceHref) ?>">Attendance</a>
            </p>
            <div class="table-wrap">
                <table class="data">
                    <tbody>
                        <?php
                        $detailFields = [
                            'employeeId' => 'Employee ID',
                            'employeeNo' => 'Employee No',
                            'name' => 'Name',
                            'firstName' => 'First name',
                            'middleName' => 'Middle name',
                            'lastName' => 'Last name',
                            'designation' => 'Designation',
                            'title' => 'Title',
                            'gender' => 'Gender',
                            'dateOfBirth' => 'Date of birth',
                            'dateOfJoin' => 'Date of join',
                            'originalHireDate' => 'Original hire date',
                            'leavingDate' => 'Leaving date',
                            'leftorg' => 'Left organization',
                            'mobile' => 'Mobile',
                            'email' => 'Email',
                            'personalEmail' => 'Personal email',
                            'status' => 'Status',
                            'probationPeriod' => 'Probation period',
                            'yearsInJob' => 'Years in job',
                            'yearsInService' => 'Years in service',
                            'lastModified' => 'Last modified',
                        ];
                        foreach ($detailFields as $key => $label):
                            $val = $employee[$key] ?? null;
                            ?>
                            <tr>
                                <th style="width:38%;text-align:left"><?= e($label) ?></th>
                                <td><?= e(hr_emp_display($val)) ?></td>
                            </tr>
                            <?php if ($key === 'name'): ?>
                            <tr>
                                <td colspan="2" style="padding:0.85rem 0.75rem">
                                    <form method="post" action="<?= e($detailHref) ?>" class="form" style="max-width:none;margin:0;flex-direction:row;flex-wrap:wrap;align-items:flex-end;gap:0.75rem 1rem" autocomplete="off">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="save_local_hr" value="1">
                                        <div class="form__row" style="margin:0;min-width:180px;flex:1 1 180px">
                                            <label for="hr_nickname">Nickname</label>
                                            <input id="hr_nickname" name="nickname" type="text" maxlength="25" value="<?= e($formNick) ?>" placeholder="Optional nickname">
                                        </div>
                                        <div class="form__row" style="margin:0;min-width:220px;flex:1 1 220px">
                                            <label for="hr_branch_id">Branch</label>
                                            <select id="hr_branch_id" name="branch_id">
                                                <option value="">— Select branch —</option>
                                                <?php foreach ($branchOptions as $b): ?>
                                                    <option value="<?= (int) $b['id'] ?>"<?= $formBranchId === (int) $b['id'] ? ' selected' : '' ?>><?= e($b['label']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form__row" style="margin:0;min-width:200px;flex:1 1 200px">
                                            <label for="hr_role_id">Role</label>
                                            <select id="hr_role_id" name="role_id">
                                                <option value="">— Select role —</option>
                                                <?php foreach ($roleOptions as $r): ?>
                                                    <option value="<?= (int) $r['id'] ?>"<?= $formRoleId === (int) $r['id'] ? ' selected' : '' ?>><?= e($r['label']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form__row" style="margin:0">
                                            <button type="submit" class="btn btn--primary">Save</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($employeeId <= 0): ?>
            <form method="get" action="hr_employees.php" class="form form--inline-sales-period" id="hr-emp-branch-filter" autocomplete="off" style="margin:0;padding:0.85rem 1.25rem 0.65rem;box-sizing:border-box">
                <div class="form__row" style="margin:0;min-width:14rem">
                    <label for="hr_list_branch">Branch</label>
                    <select id="hr_list_branch" name="branch" onchange="this.form.submit()">
                        <option value="">All branches</option>
                        <?php foreach ($branchOptions as $b): ?>
                            <option value="<?= (int) $b['id'] ?>"<?= $filterBranchId === (int) $b['id'] ? ' selected' : '' ?>><?= e($b['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            <?php if ($error === null && count($employees) === 0): ?>
                <p class="empty">No employees found.</p>
            <?php elseif (count($employees) > 0): ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Locality</th>
                                <th>Employee Name</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): ?>
                                <?php
                                $eid = (int) ($emp['employeeId'] ?? 0);
                                $name = trim((string) ($emp['name'] ?? ''));
                                if ($name === '') {
                                    $name = 'Employee #' . $eid;
                                }
                                $locality = trim((string) ($emp['locality'] ?? ''));
                                $rowQuery = $hrListQuery + ['id' => $eid] + ($page > 1 ? ['page' => $page] : []);
                                $rowDetailHref = 'hr_employees.php?' . http_build_query($rowQuery);
                                ?>
                                <tr>
                                    <td><?= e($locality) ?></td>
                                    <td>
                                        <?php if ($eid > 0): ?>
                                            <a class="link--underlined" href="<?= e($rowDetailHref) ?>"><?= e($name) ?></a>
                                        <?php else: ?>
                                            <?= e($name) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="leads-pagination" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem 0.85rem;padding:1rem 0 0;margin:0;justify-content:center">
                        <?php
                        $prevParams = $hrListQuery;
                        if (($page - 1) > 1) {
                            $prevParams['page'] = $page - 1;
                        }
                        $prevHref = $prevParams === [] ? 'hr_employees.php' : ('hr_employees.php?' . http_build_query($prevParams));
                        $nextHref = 'hr_employees.php?' . http_build_query($hrListQuery + ['page' => $page + 1]);
                        ?>
                        <?php if ($page > 1): ?>
                            <a class="btn btn--ghost" href="<?= e($prevHref) ?>">Previous</a>
                        <?php endif; ?>
                        <span style="font-size:.9rem;color:var(--muted, #64748b)">
                            Page <?= (int) $page ?> of <?= (int) $totalPages ?>
                            <?php if ($totalElements > 0): ?>
                                · <?= (int) $totalElements ?> employees
                            <?php endif; ?>
                        </span>
                        <?php if ($page < $totalPages): ?>
                            <a class="btn btn--ghost" href="<?= e($nextHref) ?>">Next</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
  var syncBtn = document.getElementById('hr-emp-sync-btn');
  if (!syncBtn) return;
  syncBtn.addEventListener('click', function () {
    syncBtn.setAttribute('aria-busy', 'true');
    syncBtn.textContent = 'Syncing…';
  });
})();
</script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
