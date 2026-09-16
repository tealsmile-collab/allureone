<?php
declare(strict_types=1);

/**
 * Local greytHR employee store (allurehr_employee).
 */

function allurehr_ensure_employee_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS allurehr_employee (
                employeeId INT NOT NULL,
                name VARCHAR(255) NOT NULL DEFAULT \'\',
                NickName VARCHAR(255) NULL,
                BranchID INT NULL,
                RoleID INT NULL,
                mobile VARCHAR(20) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (employeeId),
                KEY idx_allurehr_emp_branch (BranchID),
                KEY idx_allurehr_emp_role (RoleID)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $roleCol = $pdo->query("SHOW COLUMNS FROM allurehr_employee LIKE 'RoleID'")->fetch();
        if (!$roleCol) {
            $pdo->exec('ALTER TABLE allurehr_employee ADD COLUMN RoleID INT NULL AFTER BranchID');
            try {
                $pdo->exec('ALTER TABLE allurehr_employee ADD KEY idx_allurehr_emp_role (RoleID)');
            } catch (Throwable $e) {
                // key may already exist
            }
        }
        $mobileCol = $pdo->query("SHOW COLUMNS FROM allurehr_employee LIKE 'mobile'")->fetch();
        if (!$mobileCol) {
            $pdo->exec('ALTER TABLE allurehr_employee ADD COLUMN mobile VARCHAR(20) NULL AFTER RoleID');
        }
    } catch (Throwable $e) {
        error_log('AllureOne allurehr_employee ensure table: ' . $e->getMessage());
    }
}

/**
 * Normalize API mobile: digits only; if exactly 10 digits, prefix country code 91.
 */
function allurehr_normalize_mobile(?string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', trim((string) $raw)) ?? '';
    if ($digits === '') {
        return null;
    }
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    }

    return $digits;
}

/**
 * Upsert employeeId + name + mobile (never overwrite NickName / BranchID / RoleID).
 * Mobile: set when DB blank, or when API value differs from stored.
 *
 * @param list<array<string,mixed>> $employees
 * @return array{ok:bool,inserted:int,updated:int,error?:string}
 */
function allurehr_upsert_employees_from_api(array $employees): array
{
    allurehr_ensure_employee_table();
    $inserted = 0;
    $updated = 0;
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO allurehr_employee (employeeId, name, mobile)
             VALUES (:id, :name, :mobile)
             ON DUPLICATE KEY UPDATE
               name = IF(VALUES(name) <> \'\', VALUES(name), name),
               mobile = CASE
                 WHEN VALUES(mobile) IS NULL OR VALUES(mobile) = \'\' THEN mobile
                 WHEN mobile IS NULL OR mobile = \'\' OR mobile <> VALUES(mobile) THEN VALUES(mobile)
                 ELSE mobile
               END,
               updated_at = CURRENT_TIMESTAMP'
        );
        foreach ($employees as $emp) {
            if (!is_array($emp)) {
                continue;
            }
            $id = (int) ($emp['employeeId'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string) ($emp['name'] ?? ''));
            if ($name === '' && function_exists('greythr_employee_display_name')) {
                $name = greythr_employee_display_name($emp);
            }
            if ($name === '') {
                $name = 'Employee #' . $id;
            }
            $mobile = allurehr_normalize_mobile(isset($emp['mobile']) ? (string) $emp['mobile'] : null);
            $stmt->execute(['id' => $id, 'name' => $name, 'mobile' => $mobile]);
            // rowCount is 1 for insert, 2 for update on MySQL with ON DUPLICATE KEY
            $rc = $stmt->rowCount();
            if ($rc === 1) {
                $inserted++;
            } elseif ($rc >= 2) {
                $updated++;
            }
        }
    } catch (Throwable $e) {
        error_log('AllureOne allurehr upsert: ' . $e->getMessage());

        return ['ok' => false, 'inserted' => $inserted, 'updated' => $updated, 'error' => 'Could not save employees to database.'];
    }

    return ['ok' => true, 'inserted' => $inserted, 'updated' => $updated];
}

function allurehr_employee_db_count(): int
{
    allurehr_ensure_employee_table();
    try {
        return (int) db()->query('SELECT COUNT(*) FROM allurehr_employee')->fetchColumn();
    } catch (Throwable $e) {
        error_log('AllureOne allurehr count: ' . $e->getMessage());

        return 0;
    }
}

/**
 * Sync: call Get Employees page-by-page (using totalPages) and upsert into allurehr_employee,
 * then match Dingg employees/get nicknames + BranchID for isDingg branches.
 *
 * @return array{ok:bool,api_count:int,db_count_before:int,db_count_after:int,inserted:int,pages_fetched:int,dingg_updated?:int,dingg_skipped?:int,dingg_branches?:int,dingg_error?:?string,error?:string}
 */
function allurehr_sync_new_employees(): array
{
    allurehr_ensure_employee_table();
    $dbBefore = allurehr_employee_db_count();
    $existing = [];
    try {
        foreach (db()->query('SELECT employeeId FROM allurehr_employee')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $existing[(int) $id] = true;
        }
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'api_count' => 0,
            'db_count_before' => $dbBefore,
            'db_count_after' => $dbBefore,
            'inserted' => 0,
            'pages_fetched' => 0,
            'error' => 'Could not read local employees.',
        ];
    }

    $pageSize = 50;
    $uiPage = 1;
    $totalPages = 1;
    $totalElements = 0;
    $pagesFetched = 0;
    $seenIds = [];
    $inserted = 0;
    $lastError = null;

    // Walk every API page (1-based UI → 0-based greytHR inside greythr_list_employees).
    while ($uiPage <= $totalPages && $uiPage <= 100) {
        $res = greythr_list_employees($uiPage, $pageSize);
        if (!($res['ok'] ?? false)) {
            $lastError = (string) ($res['error'] ?? ('Failed loading employees page ' . $uiPage . '.'));
            break;
        }
        $pagesFetched++;
        $pagesMeta = is_array($res['pages'] ?? null) ? $res['pages'] : null;
        if ($uiPage === 1 && is_array($pagesMeta)) {
            $totalPages = max(1, (int) ($pagesMeta['totalPages'] ?? 1));
            $totalElements = (int) ($pagesMeta['totalElements'] ?? 0);
        } elseif (is_array($pagesMeta)) {
            $tp = (int) ($pagesMeta['totalPages'] ?? $totalPages);
            if ($tp > $totalPages) {
                $totalPages = $tp;
            }
            $te = (int) ($pagesMeta['totalElements'] ?? 0);
            if ($te > $totalElements) {
                $totalElements = $te;
            }
        }

        $pageEmployees = is_array($res['employees'] ?? null) ? $res['employees'] : [];
        $newOnPage = [];
        foreach ($pageEmployees as $emp) {
            if (!is_array($emp)) {
                continue;
            }
            $id = (int) ($emp['employeeId'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $seenIds[$id] = true;
            if (!isset($existing[$id])) {
                $newOnPage[] = $emp;
            }
        }

        // Upsert all rows on this page (refresh names; insert missing).
        $upsert = allurehr_upsert_employees_from_api($pageEmployees);
        if (!($upsert['ok'] ?? false)) {
            $lastError = (string) ($upsert['error'] ?? ('Failed saving employees page ' . $uiPage . '.'));
            break;
        }
        foreach ($newOnPage as $emp) {
            $id = (int) ($emp['employeeId'] ?? 0);
            if ($id > 0) {
                $existing[$id] = true;
                $inserted++;
            }
        }

        $uiPage++;
    }

    $dbAfter = allurehr_employee_db_count();
    $apiCount = $totalElements > 0 ? $totalElements : count($seenIds);

    if ($pagesFetched === 0) {
        return [
            'ok' => false,
            'api_count' => 0,
            'db_count_before' => $dbBefore,
            'db_count_after' => $dbAfter,
            'inserted' => 0,
            'pages_fetched' => 0,
            'error' => $lastError ?? 'Could not load employees from greytHR.',
        ];
    }

    if ($lastError !== null) {
        return [
            'ok' => false,
            'api_count' => $apiCount,
            'db_count_before' => $dbBefore,
            'db_count_after' => $dbAfter,
            'inserted' => max(0, $dbAfter - $dbBefore),
            'pages_fetched' => $pagesFetched,
            'dingg_updated' => 0,
            'dingg_skipped' => 0,
            'dingg_branches' => 0,
            'error' => $lastError . " (fetched {$pagesFetched}/{$totalPages} pages)",
        ];
    }

    $dingg = allurehr_sync_dingg_nicknames();

    return [
        'ok' => true,
        'api_count' => $apiCount,
        'db_count_before' => $dbBefore,
        'db_count_after' => $dbAfter,
        'inserted' => max(0, $dbAfter - $dbBefore),
        'pages_fetched' => $pagesFetched,
        'dingg_updated' => (int) ($dingg['updated'] ?? 0),
        'dingg_skipped' => (int) ($dingg['skipped'] ?? 0),
        'dingg_branches' => (int) ($dingg['branches'] ?? 0),
        'dingg_error' => isset($dingg['error']) ? (string) $dingg['error'] : null,
    ];
}

/**
 * Last 10 digits of a mobile (after 91-prefix normalize) for matching greytHR vs Dingg.
 */
function allurehr_mobile_match_key(?string $raw): string
{
    $normalized = allurehr_normalize_mobile($raw);
    if ($normalized === null || $normalized === '') {
        return '';
    }
    if (strlen($normalized) > 10) {
        return substr($normalized, -10);
    }

    return $normalized;
}

function allurehr_branch_session_key(int $branchId): string
{
    if ($branchId <= 0) {
        return '';
    }
    try {
        $st = db()->prepare(
            'SELECT session_key
             FROM allureone_session_data
             WHERE branch_id = :branch_id
             ORDER BY updated_date DESC
             LIMIT 1'
        );
        $st->execute(['branch_id' => $branchId]);

        return trim((string) ($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        error_log('AllureOne HR Dingg session key lookup failed: ' . $e->getMessage());
    }

    return '';
}

/**
 * Truncate nickname the same way as the employee save form (25 chars).
 */
function allurehr_clip_nickname(string $name): string
{
    $nick = trim($name);
    if ($nick === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($nick, 0, 25);
    }

    return substr($nick, 0, 25);
}

function allurehr_nickname_and_branch_present(?string $nickName, mixed $branchId): bool
{
    $nick = trim((string) ($nickName ?? ''));
    $bid = (int) ($branchId ?? 0);

    return $nick !== '' && $bid > 0;
}

/**
 * After greytHR upsert: for each isDingg branch, GET Dingg employees/get, match mobile,
 * and fill NickName + BranchID when both are not already set.
 *
 * @return array{updated:int,skipped:int,branches:int,matched:int,error?:string}
 */
function allurehr_sync_dingg_nicknames(): array
{
    if (!function_exists('dingg_http_request_authenticated')) {
        require_once __DIR__ . '/dingg.php';
    }

    $out = ['updated' => 0, 'skipped' => 0, 'branches' => 0, 'matched' => 0];

    try {
        $branches = db()->query(
            'SELECT id, business_name, locality
             FROM allureone_branch
             WHERE isActive = 1 AND isDingg = 1
             ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('AllureOne HR Dingg branches: ' . $e->getMessage());

        return $out + ['error' => 'Could not load Dingg branches.'];
    }

    if ($branches === []) {
        return $out;
    }

    try {
        $empRows = db()->query(
            'SELECT employeeId, mobile, NickName, BranchID
             FROM allurehr_employee
             WHERE mobile IS NOT NULL AND TRIM(mobile) <> \'\''
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('AllureOne HR Dingg employees: ' . $e->getMessage());

        return $out + ['error' => 'Could not load local employees for Dingg match.'];
    }

    /** @var array<string, list<array{employeeId:int,NickName:?string,BranchID:?int}>> $byMobile */
    $byMobile = [];
    foreach ($empRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = allurehr_mobile_match_key(isset($row['mobile']) ? (string) $row['mobile'] : null);
        if ($key === '') {
            continue;
        }
        $byMobile[$key][] = [
            'employeeId' => (int) ($row['employeeId'] ?? 0),
            'NickName' => isset($row['NickName']) ? (string) $row['NickName'] : null,
            'BranchID' => isset($row['BranchID']) && $row['BranchID'] !== null && $row['BranchID'] !== ''
                ? (int) $row['BranchID']
                : null,
        ];
    }

    if ($byMobile === []) {
        return $out;
    }

    /** @var array<int, true> $alreadyUpdated */
    $alreadyUpdated = [];
    $errors = [];

    try {
        $upd = db()->prepare(
            'UPDATE allurehr_employee
             SET NickName = :nick, BranchID = :branch, updated_at = CURRENT_TIMESTAMP
             WHERE employeeId = :id
               AND NOT (
                 NickName IS NOT NULL AND TRIM(NickName) <> \'\'
                 AND BranchID IS NOT NULL AND BranchID > 0
               )'
        );
    } catch (Throwable $e) {
        error_log('AllureOne HR Dingg update prepare: ' . $e->getMessage());

        return $out + ['error' => 'Could not prepare Dingg nickname update.'];
    }

    foreach ($branches as $branch) {
        if (!is_array($branch)) {
            continue;
        }
        $branchId = (int) ($branch['id'] ?? 0);
        if ($branchId <= 0) {
            continue;
        }

        $token = allurehr_branch_session_key($branchId);
        if ($token === '') {
            $errors[] = 'branch ' . $branchId . ' has no session key';
            continue;
        }

        $resp = dingg_http_request_authenticated(
            'GET',
            'https://api.dingg.app/api/v1/employees/get',
            $token,
            null
        );
        $out['branches']++;
        $http = (int) ($resp['http'] ?? 0);
        $body = (string) ($resp['body'] ?? '');
        $json = json_decode($body, true);
        if ($http < 200 || $http >= 300 || !is_array($json) || (($json['success'] ?? true) === false)) {
            $msg = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
            $errors[] = 'branch ' . $branchId . ($msg !== '' ? ': ' . $msg : (' HTTP ' . $http));
            error_log('AllureOne HR Dingg employees/get failed for branch ' . $branchId . ' HTTP ' . $http);
            continue;
        }

        $data = $json['data'] ?? [];
        if (!is_array($data)) {
            continue;
        }

        foreach ($data as $dinggEmp) {
            if (!is_array($dinggEmp)) {
                continue;
            }
            $dinggName = trim((string) ($dinggEmp['name'] ?? ''));
            if ($dinggName === '') {
                continue;
            }
            $key = allurehr_mobile_match_key(isset($dinggEmp['mobile_no']) ? (string) $dinggEmp['mobile_no'] : null);
            if ($key === '' || !isset($byMobile[$key])) {
                continue;
            }

            $nick = allurehr_clip_nickname($dinggName);
            if ($nick === '') {
                continue;
            }

            foreach ($byMobile[$key] as &$local) {
                $empId = (int) ($local['employeeId'] ?? 0);
                if ($empId <= 0 || isset($alreadyUpdated[$empId])) {
                    continue;
                }
                $out['matched']++;
                if (allurehr_nickname_and_branch_present($local['NickName'] ?? null, $local['BranchID'] ?? null)) {
                    $out['skipped']++;
                    $alreadyUpdated[$empId] = true;
                    continue;
                }
                try {
                    $upd->execute([
                        'nick' => $nick,
                        'branch' => $branchId,
                        'id' => $empId,
                    ]);
                    if ($upd->rowCount() > 0) {
                        $out['updated']++;
                        $local['NickName'] = $nick;
                        $local['BranchID'] = $branchId;
                    } else {
                        $out['skipped']++;
                    }
                    $alreadyUpdated[$empId] = true;
                } catch (Throwable $e) {
                    error_log('AllureOne HR Dingg nickname update employee ' . $empId . ': ' . $e->getMessage());
                }
            }
            unset($local);
        }
    }

    if ($errors !== []) {
        $out['error'] = implode('; ', array_slice($errors, 0, 5));
    }

    return $out;
}

/**
 * @return array{employeeId:int,name:string,NickName:?string,BranchID:?int,RoleID:?int}|null
 */
function allurehr_get_local_employee(int $employeeId): ?array
{
    if ($employeeId <= 0) {
        return null;
    }
    allurehr_ensure_employee_table();
    try {
        $st = db()->prepare(
            'SELECT employeeId, name, NickName, BranchID, RoleID
             FROM allurehr_employee
             WHERE employeeId = :id
             LIMIT 1'
        );
        $st->execute(['id' => $employeeId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'employeeId' => (int) ($row['employeeId'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'NickName' => isset($row['NickName']) && $row['NickName'] !== null ? (string) $row['NickName'] : null,
            'BranchID' => isset($row['BranchID']) && $row['BranchID'] !== null && $row['BranchID'] !== ''
                ? (int) $row['BranchID']
                : null,
            'RoleID' => isset($row['RoleID']) && $row['RoleID'] !== null && $row['RoleID'] !== ''
                ? (int) $row['RoleID']
                : null,
        ];
    } catch (Throwable $e) {
        error_log('AllureOne allurehr get: ' . $e->getMessage());

        return null;
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function allurehr_save_local_employee(int $employeeId, string $nickName, ?int $branchId, ?int $roleId = null): array
{
    if ($employeeId <= 0) {
        return ['ok' => false, 'error' => 'Invalid employee id.'];
    }
    allurehr_ensure_employee_table();
    $nick = trim($nickName);
    if (function_exists('mb_substr')) {
        $nick = mb_substr($nick, 0, 25);
    } else {
        $nick = substr($nick, 0, 25);
    }
    if ($branchId !== null && $branchId <= 0) {
        $branchId = null;
    }
    if ($roleId !== null && $roleId <= 0) {
        $roleId = null;
    }
    if ($branchId !== null) {
        try {
            $chk = db()->prepare('SELECT id FROM allureone_branch WHERE id = :id AND isActive = 1 LIMIT 1');
            $chk->execute(['id' => $branchId]);
            if ($chk->fetch() === false) {
                return ['ok' => false, 'error' => 'Selected branch is invalid.'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not validate branch.'];
        }
    }
    if ($roleId !== null) {
        $allowed = [];
        foreach (allurehr_role_options() as $opt) {
            $allowed[(int) $opt['id']] = true;
        }
        if (!isset($allowed[$roleId])) {
            return ['ok' => false, 'error' => 'Selected role is invalid.'];
        }
    }

    try {
        $pdo = db();
        $exists = $pdo->prepare('SELECT employeeId FROM allurehr_employee WHERE employeeId = :id LIMIT 1');
        $exists->execute(['id' => $employeeId]);
        if ($exists->fetch() === false) {
            $ins = $pdo->prepare(
                'INSERT INTO allurehr_employee (employeeId, name, NickName, BranchID, RoleID)
                 VALUES (:id, :name, :nick, :branch, :role)'
            );
            $ins->execute([
                'id' => $employeeId,
                'name' => 'Employee #' . $employeeId,
                'nick' => $nick !== '' ? $nick : null,
                'branch' => $branchId,
                'role' => $roleId,
            ]);
        } else {
            $upd = $pdo->prepare(
                'UPDATE allurehr_employee
                 SET NickName = :nick, BranchID = :branch, RoleID = :role, updated_at = CURRENT_TIMESTAMP
                 WHERE employeeId = :id'
            );
            $upd->execute([
                'nick' => $nick !== '' ? $nick : null,
                'branch' => $branchId,
                'role' => $roleId,
                'id' => $employeeId,
            ]);
        }
    } catch (Throwable $e) {
        error_log('AllureOne allurehr save: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'Could not save nickname / branch / role.'];
    }

    return ['ok' => true];
}

/**
 * Role dropdown: Accounts, manager, therapist, housekeeping, then other roles
 * from allureone_roles (excludes Superadmin / Admin).
 *
 * @return list<array{id:int,label:string}>
 */
function allurehr_role_options(): array
{
    $preferredOrder = [
        ROLE_ACCOUNTS => 'Accounts',
        ROLE_MANAGER => 'manager',
        ROLE_THERAPIST => 'therapist',
        ROLE_HOUSEKEEPING => 'housekeeping',
    ];
    $exclude = [ROLE_SUPERADMIN => true, ROLE_ADMIN => true];
    /** @var array<int, string> $byId */
    $byId = [];
    try {
        $rows = db()->query(
            'SELECT id, RoleName
             FROM allureone_roles
             WHERE isActive = 1
             ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($exclude[$id])) {
                continue;
            }
            $name = trim((string) ($row['RoleName'] ?? ''));
            if ($name === '') {
                continue;
            }
            $byId[$id] = $name;
        }
    } catch (Throwable $e) {
        error_log('AllureOne allurehr roles: ' . $e->getMessage());
    }

    $out = [];
    foreach ($preferredOrder as $id => $label) {
        if (!isset($byId[$id])) {
            continue;
        }
        $out[] = ['id' => $id, 'label' => $label];
        unset($byId[$id]);
    }
    // Remaining = "Other" roles from allureone_roles
    asort($byId, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($byId as $id => $name) {
        $out[] = ['id' => (int) $id, 'label' => $name];
    }

    return $out;
}

/**
 * @return list<array{id:int,label:string}>
 */
function allurehr_active_branch_options(): array
{
    $out = [];
    try {
        $rows = db()->query(
            'SELECT id, business_name, locality
             FROM allureone_branch
             WHERE isActive = 1
             ORDER BY locality ASC, business_name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $loc = trim((string) ($row['locality'] ?? ''));
            $bn = trim((string) ($row['business_name'] ?? ''));
            $label = $loc !== '' ? $loc : ($bn !== '' ? $bn : ('Branch #' . $id));
            if ($loc !== '' && $bn !== '' && strcasecmp($loc, $bn) !== 0) {
                $label = $loc . ' · ' . $bn;
            }
            $out[] = ['id' => $id, 'label' => $label];
        }
    } catch (Throwable $e) {
        error_log('AllureOne allurehr branches: ' . $e->getMessage());
    }

    return $out;
}

/**
 * Paginated employee list from allurehr_employee, optional BranchID filter.
 *
 * @return array{rows: list<array{employeeId:int,name:string,mobile:string,locality:string}>, total:int, total_pages:int}
 */
function allurehr_list_local_employees(int $page, int $perPage, int $branchId = 0): array
{
    allurehr_ensure_employee_table();
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $empty = ['rows' => [], 'total' => 0, 'total_pages' => 1];
    try {
        $pdo = db();
        $where = '';
        $params = [];
        if ($branchId > 0) {
            $where = ' WHERE e.BranchID = :branch';
            $params['branch'] = $branchId;
        }
        $countSt = $pdo->prepare('SELECT COUNT(*) FROM allurehr_employee e' . $where);
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT e.employeeId, e.name, e.mobile, TRIM(IFNULL(b.locality, \'\')) AS locality
                FROM allurehr_employee e
                LEFT JOIN allureone_branch b ON b.id = e.BranchID'
            . $where
            . ' ORDER BY e.name ASC, e.employeeId ASC
                LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'employeeId' => (int) ($row['employeeId'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'mobile' => (string) ($row['mobile'] ?? ''),
                'locality' => trim((string) ($row['locality'] ?? '')),
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'total_pages' => $totalPages];
    } catch (Throwable $e) {
        error_log('AllureOne allurehr local list: ' . $e->getMessage());

        return $empty;
    }
}
