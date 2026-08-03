<?php
/**
 * Authentication against allureone_users + allureone_roles
 */

declare(strict_types=1);

class Auth
{
    public static function attempt(string $login, string $password): bool
    {
        $db = Database::getInstance();
        // Detect password column dynamically (legacy Allure schemas vary)
        $pwdCol = 'password';
        foreach (['password', 'Password', 'userpassword', 'UserPassword', 'passwd', 'Pwd'] as $candidate) {
            try {
                $db->query("SELECT `{$candidate}` FROM allureone_users LIMIT 0");
                $pwdCol = $candidate;
                break;
            } catch (Throwable $e) {
                continue;
            }
        }

        $sql = "SELECT u.id, u.loginname, u.FullName, u.MobileNo, u.EmailId, u.BranchId,
                       u.RoleId, u.isactive, u.`{$pwdCol}` AS password_hash, r.RoleName
                FROM allureone_users u
                LEFT JOIN allureone_roles r ON r.id = u.RoleId
                WHERE (u.loginname = ? OR u.EmailId = ?)
                LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();

        if (!$user || !(int) $user['isactive']) {
            return false;
        }

        $stored = (string) ($user['password_hash'] ?? '');
        $ok = false;

        if ($stored !== '' && (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$'))) {
            $ok = password_verify($password, $stored);
        } else {
            // Legacy plain / md5 fallbacks commonly used on shared hosting apps
            $ok = hash_equals($stored, $password)
                || hash_equals($stored, md5($password))
                || hash_equals(strtolower($stored), md5($password));
        }

        if (!$ok) {
            return false;
        }

        $role = strtolower(trim((string) ($user['RoleName'] ?? '')));
        $allowed = array_map('strtolower', (array) config('admin_roles', ['admin', 'superadmin']));
        if (!in_array($role, $allowed, true)) {
            return false;
        }

        unset($user['password_hash']);
        $_SESSION['admin_user'] = [
            'id'         => (int) $user['id'],
            'loginname'  => $user['loginname'],
            'full_name'  => $user['FullName'],
            'email'      => $user['EmailId'],
            'mobile'     => $user['MobileNo'],
            'branch_id'  => $user['BranchId'],
            'role_id'    => (int) $user['RoleId'],
            'role'       => $role,
        ];

        session_regenerate_id(true);
        activity_log('login', 'user', (int) $user['id']);
        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['admin_user']['id']);
    }

    public static function user(): ?array
    {
        return $_SESSION['admin_user'] ?? null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['admin_user']['id']) ? (int) $_SESSION['admin_user']['id'] : null;
    }

    public static function role(): string
    {
        return (string) ($_SESSION['admin_user']['role'] ?? '');
    }

    public static function requireAdmin(): void
    {
        if (!self::check()) {
            if (is_ajax()) {
                Response::error('Unauthorized', 401);
            }
            redirect(base_url('admin/login.php'));
        }
    }

    public static function logout(): void
    {
        activity_log('logout', 'user', self::id());
        unset($_SESSION['admin_user']);
        session_regenerate_id(true);
    }
}
