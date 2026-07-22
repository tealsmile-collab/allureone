<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const ALLUREONE_REMEMBER_LOGIN_COOKIE = 'allureone_remember_loginname';

if (current_user() !== null) {
    allureone_redirect(allureone_home_path_for_user());
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
            setcookie(
                ALLUREONE_REMEMBER_LOGIN_COOKIE,
                $loginname,
                [
                    'expires' => time() + (86400 * 30),
                    'path' => '/',
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]
            );
            $pdo = db();
            $st = $pdo->prepare(
                'SELECT id, loginname, password, FullName, BranchId, RoleId, isactive, RecordSale
                 FROM allureone_users
                 WHERE loginname = :login
                 LIMIT 1'
            );
            $st->execute(['login' => $loginname]);
            $row = $st->fetch();
            if (!is_array($row)) {
                $error = 'Invalid user name or password.';
            } elseif ((int) ($row['isactive'] ?? 0) !== 1) {
                $error = 'Your account is inactive. Please contact admin.';
            } elseif (!password_verify($password, (string) ($row['password'] ?? ''))) {
                $error = 'Invalid user name or password.';
            } else {
                $branchId = isset($row['BranchId']) && (int) $row['BranchId'] > 0 ? (int) $row['BranchId'] : null;
                $sessionKey = '';
                if ($branchId !== null) {
                    $sst = $pdo->prepare(
                        'SELECT session_key
                         FROM allureone_session_data
                         WHERE branch_id = :branch_id
                         ORDER BY updated_date DESC
                         LIMIT 1'
                    );
                    $sst->execute(['branch_id' => $branchId]);
                    $sessionKey = trim((string) ($sst->fetchColumn() ?: ''));
                }

                $invoiceCancellationDisabled = ($branchId !== null && $sessionKey === '');
                dingg_clear_session_encrypted_token();
                if ($sessionKey !== '') {
                    dingg_encrypt_session_token($sessionKey);
                }

                $displayName = trim((string) ($row['FullName'] ?? ''));
                if ($displayName === '') {
                    $displayName = 'User';
                }

                login_user([
                    'id' => (int) ($row['id'] ?? 0),
                    'loginname' => (string) ($row['loginname'] ?? $loginname),
                    'full_name' => $displayName,
                    'branch_id' => $branchId,
                    'role_id' => (int) ($row['RoleId'] ?? 0),
                    'RecordSale' => (int) ($row['RecordSale'] ?? 0),
                ]);
                $_SESSION['invoice_cancellation_disabled'] = $invoiceCancellationDisabled;
                session_write_close();
                allureone_redirect(allureone_home_path_for_user());
                exit;
            }
        }
    }
}

$config = require __DIR__ . '/config.php';
$appName = $config['app']['name'];
$rememberedLoginname = trim((string) ($_COOKIE[ALLUREONE_REMEMBER_LOGIN_COOKIE] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <?php pwa_render_head_tags(); ?>
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
                       value="<?= isset($_POST['loginname']) ? htmlspecialchars((string) $_POST['loginname'], ENT_QUOTES, 'UTF-8') : htmlspecialchars($rememberedLoginname, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form__row">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" maxlength="128" required>
            </div>
            <button class="btn btn--primary btn--block" type="submit">Sign in</button>
        </form>
    </div>
    <script src="assets/js/pwa.js" defer></script>
</body>
</html>
