<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!can_access_expense($user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $input = is_array($decoded) ? $decoded : $_POST;
} else {
    $input = $_GET;
}

$csrf = (string) ($input['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!csrf_validate($csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid session. Please refresh.']);
    exit;
}

$action = trim((string) ($input['action'] ?? ''));
$roleId = (int) ($user['role_id'] ?? 0);
$canSelectBranch = in_array($roleId, [ROLE_SUPERADMIN, ROLE_ADMIN], true);
$canViewExpenses = $canSelectBranch;

$branchId = (int) ($user['branch_id'] ?? 0);
if ($canSelectBranch) {
    $requestedBranchId = (int) ($input['branch_id'] ?? 0);
    if ($requestedBranchId > 0) {
        try {
            $branchCheck = db()->prepare(
                'SELECT id
                 FROM allureone_branch
                 WHERE id = :id AND isActive = 1
                 LIMIT 1'
            );
            $branchCheck->execute(['id' => $requestedBranchId]);
            $branchId = (int) ($branchCheck->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            error_log('Expense branch validation failed: ' . $e->getMessage());
            $branchId = 0;
        }
    } else {
        $branchId = 0;
    }
}

if ($branchId <= 0) {
    echo json_encode([
        'ok' => false,
        'error' => $canSelectBranch ? 'Please select a valid branch.' : 'No branch linked to your account.',
    ]);
    exit;
}

/**
 * @return string
 */
function expense_branch_session_key(int $branchId): string
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
        error_log('Expense session key lookup failed: ' . $e->getMessage());
    }

    return '';
}

/**
 * @return array{ok:bool,error?:string,http?:int,json?:mixed,body?:string}
 */
function expense_dingg_get(string $url, string $token): array
{
    $resp = dingg_http_request_authenticated('GET', $url, $token, null);
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');
    $json = json_decode($body, true);
    if ($http < 200 || $http >= 300) {
        $msg = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';

        return [
            'ok' => false,
            'error' => $msg !== '' ? $msg : ('Request failed (HTTP ' . $http . ').'),
            'http' => $http,
            'json' => $json,
            'body' => $body,
        ];
    }

    return ['ok' => true, 'http' => $http, 'json' => $json, 'body' => $body];
}

/**
 * @param array<string, scalar|null> $fields
 * @return array{ok:bool,error?:string,http?:int,json?:mixed,body?:string}
 */
function expense_dingg_form(string $method, string $url, string $token, array $fields): array
{
    $headers = dingg_auth_http_headers($token);
    if ($headers === []) {
        return ['ok' => false, 'error' => 'Missing Dingg session for branch.'];
    }
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    $resp = dingg_http_execute($method, $url, $headers, $body);
    dingg_note_unauthorized_if_needed((int) ($resp['http'] ?? 0), (string) ($resp['body'] ?? ''));

    return expense_dingg_parse_write_response($resp);
}

/**
 * @param array{http?:int,body?:string} $resp
 * @return array{ok:bool,error?:string,http?:int,json?:mixed,body?:string}
 */
function expense_dingg_parse_write_response(array $resp): array
{
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');
    $json = json_decode($body, true);
    $msg = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
    $code = is_array($json) ? (int) ($json['code'] ?? 0) : 0;
    $success = is_array($json) && (
        ($json['success'] ?? null) === true
        || $code === 200
        || $code === 201
        || stripos($msg, 'success') !== false
    );
    if ($http >= 200 && $http < 300 && ($success || $msg === '')) {
        return ['ok' => true, 'http' => $http, 'json' => $json, 'body' => $body];
    }

    return [
        'ok' => false,
        'error' => $msg !== '' ? $msg : ('Request failed (HTTP ' . $http . ').'),
        'http' => $http,
        'json' => $json,
        'body' => $body,
    ];
}

/**
 * @return list<mixed>
 */
function expense_extract_list(mixed $json): array
{
    if (!is_array($json)) {
        return [];
    }
    if (isset($json['data']) && is_array($json['data'])) {
        $data = $json['data'];
        if ($data !== [] && array_is_list($data)) {
            return $data;
        }
        foreach (['list', 'items', 'rows', 'result', 'accounts', 'types', 'modes', 'taxes', 'tax_groups'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && array_is_list($data[$key])) {
                return $data[$key];
            }
        }
        if (array_is_list($data)) {
            return $data;
        }
    }
    foreach (['list', 'items', 'rows', 'result'] as $key) {
        if (isset($json[$key]) && is_array($json[$key]) && array_is_list($json[$key])) {
            return $json[$key];
        }
    }
    if (array_is_list($json)) {
        return $json;
    }

    return [];
}

/**
 * @param list<mixed> $rows
 * @return list<array{id:int,name:string,balance:?float,raw:array<string,mixed>}>
 */
function expense_normalize_options(array $rows, string $kind): array
{
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        // Payment methods: Dingg expense "mode" expects the mode "value" (e.g. 1 for CASH), not row id.
        if ($kind === 'mode') {
            $id = (int) ($row['value'] ?? $row['mode'] ?? $row['id'] ?? 0);
        } else {
            $id = (int) ($row['id'] ?? $row['value'] ?? $row['mode'] ?? $row['type'] ?? 0);
        }
        $name = trim((string) (
            $row['name']
            ?? $row['title']
            ?? $row['label']
            ?? $row['payment_mode']
            ?? $row['account_name']
            ?? $row['type_name']
            ?? ''
        ));
        if ($id <= 0 || $name === '' || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $balance = null;
        foreach (['balance', 'available_balance', 'current_balance', 'closing_balance'] as $bKey) {
            if (array_key_exists($bKey, $row) && is_numeric($row[$bKey])) {
                $balance = (float) $row[$bKey];
                break;
            }
        }
        $rate = null;
        if ($kind === 'tax') {
            foreach (['rate', 'tax_rate', 'percentage', 'percent'] as $rKey) {
                if (array_key_exists($rKey, $row) && is_numeric($row[$rKey])) {
                    $rate = (float) $row[$rKey];
                    break;
                }
            }
        }
        $item = [
            'id' => $id,
            'name' => $name,
            'balance' => $balance,
            'raw' => $row,
        ];
        if ($kind === 'tax') {
            $item['rate'] = $rate;
        }
        $out[] = $item;
    }
    usort($out, static function (array $a, array $b): int {
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return $out;
}

$token = expense_branch_session_key($branchId);
if ($token === '') {
    echo json_encode(['ok' => false, 'error' => 'Dingg session key is not configured for this branch.']);
    exit;
}

if ($action === 'meta') {
    $typeR = expense_dingg_get('https://api.dingg.app/api/v1/vendor/expense/type', $token);
    $accountR = expense_dingg_get('https://api.dingg.app/api/v1/vendor/account/list', $token);
    $modeR = expense_dingg_get('https://api.dingg.app/api/v1/payment_mode', $token);
    $taxR = expense_dingg_get('https://api.dingg.app/api/v1/vendor/tax', $token);

    $errors = [];
    if (!$typeR['ok']) {
        $errors[] = 'Expense types: ' . (string) ($typeR['error'] ?? 'failed');
    }
    if (!$accountR['ok']) {
        $errors[] = 'Accounts: ' . (string) ($accountR['error'] ?? 'failed');
    }
    if (!$modeR['ok']) {
        $errors[] = 'Payment methods: ' . (string) ($modeR['error'] ?? 'failed');
    }
    if (!$taxR['ok']) {
        $errors[] = 'Tax groups: ' . (string) ($taxR['error'] ?? 'failed');
    }

    echo json_encode([
        'ok' => $errors === [],
        'error' => $errors === [] ? '' : implode(' ', $errors),
        'expense_types' => expense_normalize_options(expense_extract_list($typeR['json'] ?? null), 'type'),
        'accounts' => expense_normalize_options(expense_extract_list($accountR['json'] ?? null), 'account'),
        'payment_modes' => expense_normalize_options(expense_extract_list($modeR['json'] ?? null), 'mode'),
        'tax_groups' => expense_normalize_options(expense_extract_list($taxR['json'] ?? null), 'tax'),
        'branch_id' => $branchId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Convert report date (DD-MM-YYYY or YYYY-MM-DD) to Y-m-d.
 */
function expense_report_date_to_ymd(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
        return $raw;
    }
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $raw, $m) === 1) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    return '';
}

if ($action === 'list') {
    if (!$canViewExpenses) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied.']);
        exit;
    }
    $month = trim((string) ($input['month'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        echo json_encode(['ok' => false, 'error' => 'Please select a valid month.']);
        exit;
    }
    try {
        $startDt = DateTime::createFromFormat('Y-m-d', $month . '-01', new DateTimeZone('Asia/Kolkata'));
        if ($startDt === false) {
            throw new RuntimeException('Invalid month');
        }
        $startDate = $startDt->format('Y-m-d');
        $endDate = $startDt->format('Y-m-t');
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Please select a valid month.']);
        exit;
    }

    $url = 'https://api.dingg.app/api/v1/vendor/report/sales?' . http_build_query([
        'start_date' => $startDate,
        'report_type' => 'by_expense',
        'end_date' => $endDate,
        'locations' => 'null',
        'app_type' => 'web',
        'range_type' => 'month',
    ], '', '&', PHP_QUERY_RFC3986);

    $r = expense_dingg_get($url, $token);
    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'Could not load expenses.']);
        exit;
    }
    $json = is_array($r['json'] ?? null) ? $r['json'] : [];
    $status = strtolower(trim((string) ($json['status'] ?? '')));
    $success = ($json['success'] ?? null) === true || $status === 'success';
    if (!$success && $status !== '' && $status !== 'success') {
        $msg = trim((string) ($json['message'] ?? ''));
        echo json_encode(['ok' => false, 'error' => $msg !== '' ? $msg : 'Could not load expenses.']);
        exit;
    }

    $data = $json['data'] ?? [];
    if (!is_array($data)) {
        $data = [];
    }
    $rows = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['expense id'] ?? $row['expense_id'] ?? $row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $dateYmd = expense_report_date_to_ymd((string) ($row['date'] ?? ''));
        $rows[] = [
            'id' => $id,
            'date' => $dateYmd,
            'date_display' => trim((string) ($row['date'] ?? '')),
            'amount' => (float) ($row['amount'] ?? 0),
            'given_to' => trim((string) ($row['given to'] ?? $row['given_to'] ?? '')),
            'desc' => trim((string) ($row['description'] ?? $row['desc'] ?? '')),
            'type' => 0,
            'mode' => 0,
            'vendor_account_id' => 0,
            'tax_group_id' => null,
            'net' => isset($row['Net']) && $row['Net'] !== '' ? (float) $row['Net'] : (isset($row['net']) ? (float) $row['net'] : null),
            'tax' => isset($row['Tax']) && $row['Tax'] !== '' ? (float) $row['Tax'] : (isset($row['tax']) ? (float) $row['tax'] : null),
            'expense_type_name' => trim((string) ($row['expense type'] ?? $row['expense_type'] ?? '')),
            'payment_mode_name' => trim((string) ($row['Payment mode'] ?? $row['payment_mode'] ?? '')),
            'location' => trim((string) ($row['location'] ?? '')),
            'branch' => trim((string) ($row['branch'] ?? '')),
            'raw' => $row,
        ];
    }

    echo json_encode([
        'ok' => true,
        'month' => $month,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'expenses' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'save' || $action === 'update') {
    if ($action === 'update' && !$canViewExpenses) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied.']);
        exit;
    }

    $date = trim((string) ($input['date'] ?? ''));
    $type = (int) ($input['type'] ?? 0);
    $mode = (int) ($input['mode'] ?? 0);
    $givenTo = trim((string) ($input['given_to'] ?? ''));
    $amount = (float) ($input['amount'] ?? 0);
    $desc = trim((string) ($input['desc'] ?? ''));
    $net = (float) ($input['net'] ?? $amount);
    $tax = (float) ($input['tax'] ?? 0);
    $vendorAccountId = (int) ($input['vendor_account_id'] ?? 0);
    $expenseId = (int) ($input['id'] ?? 0);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        echo json_encode(['ok' => false, 'error' => 'Please select a valid date.']);
        exit;
    }
    if ($type <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Please select expense type.']);
        exit;
    }
    if ($mode <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Please select payment mode.']);
        exit;
    }
    if ($vendorAccountId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Please select account.']);
        exit;
    }
    if ($givenTo === '') {
        echo json_encode(['ok' => false, 'error' => 'Please enter given to.']);
        exit;
    }
    if (mb_strlen($givenTo) > 50) {
        echo json_encode(['ok' => false, 'error' => 'Given to max 50 characters.']);
        exit;
    }
    if ($amount <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Please enter amount.']);
        exit;
    }
    if ($desc === '') {
        echo json_encode(['ok' => false, 'error' => 'Please enter description.']);
        exit;
    }
    if (mb_strlen($desc) > 100) {
        echo json_encode(['ok' => false, 'error' => 'Description max 100 characters.']);
        exit;
    }
    if ($action === 'update' && $expenseId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing expense id.']);
        exit;
    }

    $fields = [
        'date' => $date,
        'type' => $type,
        'mode' => $mode,
        'given_to' => $givenTo,
        'amount' => $amount,
        'desc' => $desc,
        'net' => $net,
        'vendor_account_id' => $vendorAccountId,
    ];
    if ($action === 'save') {
        $fields['tax'] = $tax;
    }
    if ($action === 'update') {
        $fields['id'] = $expenseId;
    }

    $url = 'https://api.dingg.app/api/v1/vendor/expense';
    $r = expense_dingg_form($action === 'update' ? 'PUT' : 'POST', $url, $token, $fields);

    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'Could not save expense.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => $action === 'update' ? 'Expense updated.' : 'Expense added.',
        'dingg' => $r['json'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'delete') {
    if (!$canViewExpenses) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied.']);
        exit;
    }
    $expenseId = (int) ($input['id'] ?? 0);
    $date = trim((string) ($input['date'] ?? ''));
    if ($expenseId <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        echo json_encode(['ok' => false, 'error' => 'Invalid expense to delete.']);
        exit;
    }
    $url = 'https://api.dingg.app/api/v1/vendor/expense?id=' . rawurlencode((string) $expenseId)
        . '&date=' . rawurlencode($date);
    $resp = dingg_http_request_authenticated('DELETE', $url, $token, null);
    $parsed = expense_dingg_parse_write_response($resp);
    if (!$parsed['ok']) {
        echo json_encode(['ok' => false, 'error' => $parsed['error'] ?? 'Could not delete expense.']);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'Expense deleted.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
