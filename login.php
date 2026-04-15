<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (current_user() !== null) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf'] ?? '';
    if (!csrf_validate($token)) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $loginname = isset($_POST['loginname']) ? trim((string) $_POST['loginname']) : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

        if ($loginname === '' || $password === '') {
            $error = 'User name and password are required.';
        } elseif ((function_exists('mb_strlen') ? mb_strlen($loginname) : strlen($loginname)) > 50) {
            $error = 'User name is too long.';
        } elseif ((function_exists('mb_strlen') ? mb_strlen($password) : strlen($password)) > 128) {
            $error = 'Password is too long.';
        } else {
            $result = dingg_vendor_login_credentials($loginname, $password);
            if (!($result['ok'] ?? false)) {
                $error = (string) ($result['error'] ?? 'Sign-in failed.');
            } else {
                $tok = (string) ($result['token'] ?? '');
                if ($tok === '') {
                    $error = 'No token received from Dingg.';
                } else {
                    $mappedUser = auth_find_active_user_by_mobile_or_email($loginname);
                    if ($mappedUser === null) {
                        $error = 'User is not mapped in User Master with active status.';
                    } else {
                    dingg_clear_session_encrypted_token();
                    dingg_encrypt_session_token($tok);
                    $_SESSION['dingg_bearer_bootstrap'] = $tok;
                    $displayName = trim((string) ($result['employee_name'] ?? ''));
                    if ($displayName === '') {
                        $displayName = trim((string) ($mappedUser['FullName'] ?? ''));
                    }
                    if ($displayName === '') {
                        $displayName = 'User';
                    }
                    login_user([
                        'id' => (int) ($mappedUser['id'] ?? 0),
                        'loginname' => (string) ($mappedUser['loginname'] ?? $loginname),
                        'full_name' => $displayName,
                        'branch_id' => isset($mappedUser['BranchId']) ? (int) $mappedUser['BranchId'] : null,
                        'role_id' => (int) ($mappedUser['RoleId'] ?? 0),
                    ]);
                    session_write_close();
                    header('Location: dashboard.php', true, 302);
                    exit;
                    }
                }
            }
        }
    }
}

$config = require __DIR__ . '/config.php';
$appName = $config['app']['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-page">
    <div class="login-card">
        <h1 class="login-heading">
            <img src="assets/images/allure-logo.png" alt="<?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>" class="login-logo" loading="eager" decoding="async">
        </h1>
        <?php if ($error !== ''): ?>
            <div class="alert alert--error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form class="form" method="post" action="login.php" autocomplete="off" novalidate>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="form__row">
                <label for="loginname">User Name</label>
                <input id="loginname" name="loginname" type="text" inputmode="text" maxlength="50" required
                       placeholder="Email or mobile"
                       value="<?= isset($_POST['loginname']) ? htmlspecialchars((string) $_POST['loginname'], ENT_QUOTES, 'UTF-8') : '' ?>">
            </div>
            <div class="form__row">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" maxlength="128" required>
            </div>
            <button class="btn btn--primary btn--block" type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
