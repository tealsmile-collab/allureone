<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_superadmin();

$pdo = db();
$message = '';
$messageType = '';

$branches = $pdo->query(
    'SELECT id, BranchName FROM allureone_branch WHERE isActive = 1 ORDER BY id ASC'
)->fetchAll();

$roles = $pdo->query(
    'SELECT id, RoleName FROM allureone_roles WHERE isActive = 1 ORDER BY id ASC'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $message = 'Invalid session. Please refresh and try again.';
        $messageType = 'error';
    } else {
        $loginname = isset($_POST['loginname']) ? trim((string) $_POST['loginname']) : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $fullName = isset($_POST['full_name']) ? trim((string) $_POST['full_name']) : '';
        $branchId = isset($_POST['branch_id']) ? (int) $_POST['branch_id'] : 0;
        $roleId = isset($_POST['role_id']) ? (int) $_POST['role_id'] : 0;

        $len = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';

        if ($loginname === '' || $password === '' || $fullName === '') {
            $message = 'Login name, password, and full name are required.';
            $messageType = 'error';
        } elseif ($len($loginname) > 20 || $len($password) > 20) {
            $message = 'Login name and password must be at most 20 characters.';
            $messageType = 'error';
        } elseif ($roleId < 1) {
            $message = 'Please select a role.';
            $messageType = 'error';
        } else {
            $branchForDb = null;
            if ($branchId > 0) {
                $bchk = $pdo->prepare('SELECT COUNT(*) FROM allureone_branch WHERE id = :id AND isActive = 1');
                $bchk->execute(['id' => $branchId]);
                if ((int) $bchk->fetchColumn() === 0) {
                    $message = 'Invalid branch.';
                    $messageType = 'error';
                } else {
                    $branchForDb = $branchId;
                }
            }

            if ($message === '') {
                $roleChk = $pdo->prepare('SELECT COUNT(*) FROM allureone_roles WHERE id = :id AND isActive = 1');
                $roleChk->execute(['id' => $roleId]);
                if ((int) $roleChk->fetchColumn() === 0) {
                    $message = 'Invalid role.';
                    $messageType = 'error';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    try {
                        $ins = $pdo->prepare(
                            'INSERT INTO allureone_users (loginname, password, FullName, BranchId, RoleId, isactive)
                             VALUES (:l, :p, :f, :b, :r, 1)'
                        );
                        $ins->execute([
                            'l' => $loginname,
                            'p' => $hash,
                            'f' => $fullName,
                            'b' => $branchForDb,
                            'r' => $roleId,
                        ]);
                        $message = 'User created.';
                        $messageType = 'ok';
                        $_POST = [];
                    } catch (PDOException $e) {
                        $dup = ($e->errorInfo[1] ?? null) === 1062;
                        if ($dup || (string) $e->getCode() === '23000') {
                            $message = 'Login name already exists.';
                        } else {
                            $message = 'Could not create user.';
                        }
                        $messageType = 'error';
                    }
                }
            }
        }
    }
}

$list = $pdo->query(
    'SELECT u.id, u.loginname, u.FullName, u.BranchId, u.RoleId, u.isactive,
            b.BranchName, r.RoleName
     FROM allureone_users u
     LEFT JOIN allureone_branch b ON b.id = u.BranchId
     JOIN allureone_roles r ON r.id = u.RoleId
     ORDER BY u.id DESC
     LIMIT 50'
)->fetchAll();

$pageTitle = 'User Master';
$activeNav = 'user';
require __DIR__ . '/includes/layout_start.php';
?>

<?php if ($message !== ''): ?>
    <div class="alert alert--<?= $messageType === 'ok' ? 'ok' : 'error' ?>" style="margin-bottom:1rem"><?= e($message) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card__head">New user</div>
    <div class="card__body" style="padding:1.25rem">
        <form class="form" method="post" action="user_master.php" style="max-width:480px" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <div class="form__row">
                <label for="loginname">Login name</label>
                <input id="loginname" name="loginname" type="text" required maxlength="20"
                       value="<?= isset($_POST['loginname']) ? e((string) $_POST['loginname']) : '' ?>">
            </div>
            <div class="form__row">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required maxlength="20">
            </div>
            <div class="form__row">
                <label for="full_name">Full name</label>
                <input id="full_name" name="full_name" type="text" required maxlength="255"
                       value="<?= isset($_POST['full_name']) ? e((string) $_POST['full_name']) : '' ?>">
            </div>
            <div class="form__row">
                <label for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id">
                    <option value="0">— None —</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"
                            <?= (isset($_POST['branch_id']) && (int) $_POST['branch_id'] === (int) $b['id']) ? ' selected' : '' ?>>
                            <?= e((string) $b['BranchName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form__row">
                <label for="role_id">Role</label>
                <select id="role_id" name="role_id" required>
                    <option value="">— Select —</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int) $r['id'] ?>"
                            <?= (isset($_POST['role_id']) && (string) $_POST['role_id'] === (string) $r['id']) ? ' selected' : '' ?>>
                            <?= e((string) $r['RoleName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn--primary" type="submit">Create user</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card__head">Users (latest 50)</div>
    <div class="card__body">
        <?php if (count($list) === 0): ?>
            <p class="empty">No users.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Login</th>
                            <th>Full name</th>
                            <th>Branch</th>
                            <th>Role</th>
                            <th>Active</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list as $u): ?>
                            <tr>
                                <td><?= e((string) $u['loginname']) ?></td>
                                <td><?= e((string) $u['FullName']) ?></td>
                                <td><?= e((string) ($u['BranchName'] ?? '—')) ?></td>
                                <td><?= e((string) $u['RoleName']) ?></td>
                                <td><?= ((int) $u['isactive'] === 1) ? 'Yes' : 'No' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
