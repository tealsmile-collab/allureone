<?php
declare(strict_types=1);

const ROLE_SUPERADMIN = 1;
const ROLE_ADMIN = 2;
const ROLE_MANAGER = 3;
const ROLE_JR_MANAGER = 4;
const ROLE_THERAPIST = 5;
const ROLE_HOUSEKEEPING = 6;
const ROLE_ACCOUNTS = 7;
const ROLE_FRANCHISE_OFFICER = 9;

function allureone_home_path_for_role(int $roleId): string
{
    if ($roleId === ROLE_ACCOUNTS) {
        return 'gift_codes.php';
    }
    if ($roleId === ROLE_FRANCHISE_OFFICER) {
        return 'Franchise-leads.php';
    }
    if ($roleId === ROLE_THERAPIST || $roleId === ROLE_HOUSEKEEPING) {
        return 'appointment.php';
    }

    return 'dashboard.php';
}

function allureone_home_path_for_user(?array $user = null): string
{
    $u = $user ?? current_user();
    if (is_array($u) && !empty($u['record_sale'])) {
        return 'sale_record.php';
    }

    return allureone_home_path_for_role((int) ($u['role_id'] ?? 0));
}

function can_access_sale_record(?array $user = null): bool
{
    $u = $user ?? current_user();
    if (!is_array($u)) {
        return false;
    }

    return !empty($u['record_sale']);
}

function require_sale_record_access(): void
{
    require_login();
    if (!can_access_sale_record()) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body><p>Access denied. Sale Record permission required.</p><p><a href="' . htmlspecialchars(allureone_home_path_for_user(), ENT_QUOTES, 'UTF-8') . '">Home</a></p></body></html>';
        exit;
    }
}

function can_access_appointments(?array $user = null): bool
{
    $u = $user ?? current_user();
    if (!is_array($u)) {
        return false;
    }
    $roleId = (int) ($u['role_id'] ?? 0);

    return in_array($roleId, [
        ROLE_SUPERADMIN,
        ROLE_ADMIN,
        ROLE_MANAGER,
        ROLE_JR_MANAGER,
        ROLE_THERAPIST,
        ROLE_HOUSEKEEPING,
    ], true);
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_validate(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], $token);
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id' => (int) $_SESSION['user_id'],
        'loginname' => (string) $_SESSION['loginname'],
        'full_name' => (string) $_SESSION['full_name'],
        'branch_id' => isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null,
        'role_id' => (int) $_SESSION['role_id'],
        'record_sale' => !empty($_SESSION['record_sale']),
        'invoice_cancellation_disabled' => !empty($_SESSION['invoice_cancellation_disabled']),
    ];
}

function is_invoice_cancellation_enabled(?array $user = null): bool
{
    $u = $user ?? current_user();
    if (!is_array($u)) {
        return false;
    }

    return !((bool) ($u['invoice_cancellation_disabled'] ?? false));
}

function require_login(): void
{
    if (current_user() === null) {
        allureone_redirect('login.php');
    }
}

function require_superadmin(): void
{
    require_login();
    if (current_user()['role_id'] !== ROLE_SUPERADMIN) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body><p>Access denied. Superadmin only.</p><p><a href="dashboard.php">Dashboard</a></p></body></html>';
        exit;
    }
}

function is_accounts_role(?array $user = null): bool
{
    $u = $user ?? current_user();
    if (!is_array($u)) {
        return false;
    }

    return (int) ($u['role_id'] ?? 0) === ROLE_ACCOUNTS;
}

function require_not_accounts_role(): void
{
    require_login();
    if (is_accounts_role()) {
        allureone_redirect('gift_codes.php');
        exit;
    }
}

function is_franchise_officer_role(?array $user = null): bool
{
    $u = $user ?? current_user();
    if (!is_array($u)) {
        return false;
    }

    return (int) ($u['role_id'] ?? 0) === ROLE_FRANCHISE_OFFICER;
}

function require_not_franchise_officer_role(): void
{
    require_login();
    if (is_franchise_officer_role()) {
        allureone_redirect('Franchise-leads.php');
        exit;
    }
}

function login_user(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) ($row['id'] ?? 0);
    $_SESSION['loginname'] = (string) ($row['loginname'] ?? '');
    $full = $row['full_name'] ?? $row['FullName'] ?? '';
    $_SESSION['full_name'] = (string) $full;
    $branch = $row['branch_id'] ?? $row['BranchId'] ?? null;
    $_SESSION['branch_id'] = $branch !== null && $branch !== '' ? (int) $branch : null;
    $role = $row['role_id'] ?? $row['RoleId'] ?? 0;
    $_SESSION['role_id'] = (int) $role;
    $recordSale = $row['record_sale'] ?? $row['RecordSale'] ?? 0;
    $_SESSION['record_sale'] = ((int) $recordSale === 1);
}

/** Stable synthetic user id for Dingg-only login (no allureone_users row). */
function auth_user_id_from_mobile_key(string $mobileDigits): int
{
    $bin = hash('sha256', 'allureone_dingg:' . $mobileDigits, true);
    $parts = unpack('N', substr($bin, 0, 4));
    $n = is_array($parts) ? (int) reset($parts) : 0;

    return max(1, $n % 2147483646);
}

/** Superadmin when mobile is 8369676845 (stored as 918369676845 after normalization). */
function auth_role_id_for_dingg_mobile_digits(string $digitsOnly): int
{
    return $digitsOnly === '918369676845' ? ROLE_SUPERADMIN : 2;
}

function auth_mobile_digits_only(string $value): string
{
    return preg_replace('/\D+/', '', trim($value)) ?? '';
}

/**
 * Resolve active User Master mapping by mobile/email for Dingg login.
 *
 * @return array<string, mixed>|null
 */
function auth_find_active_user_by_mobile_or_email(string $loginInput): ?array
{
    $needle = trim($loginInput);
    if ($needle === '') {
        return null;
    }

    $rows = db()->query(
        'SELECT id, loginname, FullName, BranchId, RoleId, isactive, MobileNo, EmailId
         FROM allureone_users
         WHERE isactive = 1'
    )->fetchAll();

    $emailNeedle = strtolower($needle);
    $mobileNeedle = auth_mobile_digits_only($needle);
    $mobileNeedle10 = strlen($mobileNeedle) > 10 ? substr($mobileNeedle, -10) : $mobileNeedle;

    foreach ($rows as $row) {
        $email = strtolower(trim((string) ($row['EmailId'] ?? '')));
        if ($email !== '' && $email === $emailNeedle) {
            return $row;
        }

        $mobile = auth_mobile_digits_only((string) ($row['MobileNo'] ?? ''));
        if ($mobile === '') {
            continue;
        }
        $mobile10 = strlen($mobile) > 10 ? substr($mobile, -10) : $mobile;
        if ($mobile === $mobileNeedle || ($mobile10 !== '' && $mobile10 === $mobileNeedle10)) {
            return $row;
        }
    }

    return null;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
