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
            $error = 'Login name and password are required.';
        } elseif (
            (function_exists('mb_strlen') ? mb_strlen($loginname) : strlen($loginname)) > 20
            || (function_exists('mb_strlen') ? mb_strlen($password) : strlen($password)) > 20
        ) {
            $error = 'Login name and password must be at most 20 characters.';
        } else {
            try {
                $pdo = db();
                $stmt = $pdo->prepare(
                    'SELECT id, loginname, password,
                            FullName AS full_name,
                            BranchId AS branch_id,
                            RoleId AS role_id,
                            isactive
                     FROM allureone_users
                     WHERE loginname = :u AND isactive = 1
                     LIMIT 1'
                );
                $stmt->execute(['u' => $loginname]);
                $row = $stmt->fetch();
                if ($row === false) {
                    $error = 'Invalid login name or password.';
                } else {
                    $hash = (string) ($row['password'] ?? '');
                    if ($hash === '' || !password_verify($password, $hash)) {
                        $error = 'Invalid login name or password.';
                    } else {
                        login_user($row);
                        header('Location: dashboard.php');
                        exit;
                    }
                }
            } catch (PDOException $e) {
                error_log('AllureOne login PDO: ' . $e->getMessage());
                $cfg = require __DIR__ . '/config.php';
                $error = !empty($cfg['app']['debug'])
                    ? ('Database error: ' . $e->getMessage())
                    : 'Sign-in is unavailable. Check the database connection and that tables exist (run install.php).';
            } catch (Throwable $e) {
                error_log('AllureOne login: ' . $e->getMessage());
                $cfg = require __DIR__ . '/config.php';
                $error = !empty($cfg['app']['debug'])
                    ? $e->getMessage()
                    : 'An error occurred. Please try again.';
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
        <h1><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="sub">Sign in to continue</p>
        <?php if ($error !== ''): ?>
            <div class="alert alert--error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form class="form" method="post" action="login.php" autocomplete="off" novalidate>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="form__row">
                <label for="loginname">Login name</label>
                <input id="loginname" name="loginname" type="text" maxlength="20" required
                       value="<?= isset($_POST['loginname']) ? htmlspecialchars((string) $_POST['loginname'], ENT_QUOTES, 'UTF-8') : '' ?>">
            </div>
            <div class="form__row">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" maxlength="20" required>
            </div>
            <button class="btn btn--primary btn--block" type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
