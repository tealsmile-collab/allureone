<?php
declare(strict_types=1);

const ROLE_SUPERADMIN = 1;

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
    ];
}

function require_login(): void
{
    if (current_user() === null) {
        header('Location: login.php');
        exit;
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

function login_user(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['loginname'] = $row['loginname'];
    $_SESSION['full_name'] = $row['FullName'];
    $_SESSION['branch_id'] = $row['BranchId'] !== null ? (int) $row['BranchId'] : null;
    $_SESSION['role_id'] = (int) $row['RoleId'];
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
