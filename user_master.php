<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_superadmin();

$pdo = db();
$message = '';
$messageType = '';

$flash = [
    'user_created' => ['User created.', 'ok'],
    'user_updated' => ['User updated.', 'ok'],
];
$mk = isset($_GET['msg']) ? (string) $_GET['msg'] : '';
if (isset($flash[$mk])) {
    [$message, $messageType] = $flash[$mk];
}

$branchesActive = $pdo->query(
    'SELECT id, business_name, locality FROM allureone_branch WHERE isActive = 1 ORDER BY id ASC'
)->fetchAll();

$branchesAll = $pdo->query(
    'SELECT id, business_name, locality, isActive FROM allureone_branch ORDER BY id ASC'
)->fetchAll();

$roles = $pdo->query(
    'SELECT id, RoleName FROM allureone_roles WHERE isActive = 1 ORDER BY id ASC'
)->fetchAll();

$rolesAll = $pdo->query(
    'SELECT id, RoleName, isActive FROM allureone_roles ORDER BY id ASC'
)->fetchAll();

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $es = $pdo->prepare(
        'SELECT id, loginname, FullName, BranchId, RoleId, isactive FROM allureone_users WHERE id = :id LIMIT 1'
    );
    $es->execute(['id' => $editId]);
    $editRow = $es->fetch();
    if ($editRow === false) {
        $editId = 0;
        $message = 'User not found.';
        $messageType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $message = 'Invalid session. Please refresh and try again.';
        $messageType = 'error';
    } else {
        $action = isset($_POST['_action']) ? (string) $_POST['_action'] : 'create';
        $loginname = isset($_POST['loginname']) ? trim((string) $_POST['loginname']) : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $fullName = isset($_POST['full_name']) ? trim((string) $_POST['full_name']) : '';
        $branchId = isset($_POST['branch_id']) ? (int) $_POST['branch_id'] : 0;
        $roleId = isset($_POST['role_id']) ? (int) $_POST['role_id'] : 0;
        $len = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';

        if ($action === 'update') {
            $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($userId < 1) {
                $message = 'Invalid user.';
                $messageType = 'error';
            } elseif ($loginname === '' || $fullName === '') {
                $message = 'Login name and full name are required.';
                $messageType = 'error';
            } elseif ($branchId < 1) {
                $message = 'Please select a branch.';
                $messageType = 'error';
            } elseif ($len($loginname) > 20) {
                $message = 'Login name must be at most 20 characters.';
                $messageType = 'error';
            } elseif ($password !== '' && $len($password) > 20) {
                $message = 'Password must be at most 20 characters.';
                $messageType = 'error';
            } elseif ($roleId < 1) {
                $message = 'Please select a role.';
                $messageType = 'error';
            } else {
                $exist = $pdo->prepare('SELECT COUNT(*) FROM allureone_users WHERE id = :id');
                $exist->execute(['id' => $userId]);
                if ((int) $exist->fetchColumn() === 0) {
                    $message = 'User not found.';
                    $messageType = 'error';
                } else {
                    $dup = $pdo->prepare(
                        'SELECT COUNT(*) FROM allureone_users WHERE loginname = :l AND id <> :id'
                    );
                    $dup->execute(['l' => $loginname, 'id' => $userId]);
                    if ((int) $dup->fetchColumn() > 0) {
                        $message = 'Login name already in use.';
                        $messageType = 'error';
                    } else {
                        $branchForDb = null;
                        $bchk = $pdo->prepare('SELECT COUNT(*) FROM allureone_branch WHERE id = :id');
                        $bchk->execute(['id' => $branchId]);
                        if ((int) $bchk->fetchColumn() === 0) {
                            $message = 'Invalid branch.';
                            $messageType = 'error';
                        } else {
                            $branchForDb = $branchId;
                        }
                        if ($message === '') {
                            $roleChk = $pdo->prepare('SELECT COUNT(*) FROM allureone_roles WHERE id = :id');
                            $roleChk->execute(['id' => $roleId]);
                            if ((int) $roleChk->fetchColumn() === 0) {
                                $message = 'Invalid role.';
                                $messageType = 'error';
                            } else {
                                try {
                                    if ($password !== '') {
                                        $hash = password_hash($password, PASSWORD_DEFAULT);
                                        $upd = $pdo->prepare(
                                            'UPDATE allureone_users SET loginname = :l, password = :p, FullName = :f,
                                             BranchId = :b, RoleId = :r, isactive = :a WHERE id = :id'
                                        );
                                        $upd->execute([
                                            'l' => $loginname,
                                            'p' => $hash,
                                            'f' => $fullName,
                                            'b' => $branchForDb,
                                            'r' => $roleId,
                                            'a' => $isActive,
                                            'id' => $userId,
                                        ]);
                                    } else {
                                        $upd = $pdo->prepare(
                                            'UPDATE allureone_users SET loginname = :l, FullName = :f,
                                             BranchId = :b, RoleId = :r, isactive = :a WHERE id = :id'
                                        );
                                        $upd->execute([
                                            'l' => $loginname,
                                            'f' => $fullName,
                                            'b' => $branchForDb,
                                            'r' => $roleId,
                                            'a' => $isActive,
                                            'id' => $userId,
                                        ]);
                                    }
                                    header('Location: user_master.php?msg=user_updated');
                                    exit;
                                } catch (PDOException $e) {
                                    $d = ($e->errorInfo[1] ?? null) === 1062;
                                    if ($d || (string) $e->getCode() === '23000') {
                                        $message = 'Login name already in use.';
                                    } else {
                                        $message = 'Could not update user.';
                                    }
                                    $messageType = 'error';
                                }
                            }
                        }
                    }
                }
            }
        } elseif ($loginname === '' || $password === '' || $fullName === '') {
            $message = 'Login name, password, and full name are required.';
            $messageType = 'error';
        } elseif ($branchId < 1) {
            $message = 'Please select a branch.';
            $messageType = 'error';
        } elseif ($len($loginname) > 20 || $len($password) > 20) {
            $message = 'Login name and password must be at most 20 characters.';
            $messageType = 'error';
        } elseif ($roleId < 1) {
            $message = 'Please select a role.';
            $messageType = 'error';
        } else {
            $branchForDb = null;
            $bchk = $pdo->prepare('SELECT COUNT(*) FROM allureone_branch WHERE id = :id AND isActive = 1');
            $bchk->execute(['id' => $branchId]);
            if ((int) $bchk->fetchColumn() === 0) {
                $message = 'Invalid branch.';
                $messageType = 'error';
            } else {
                $branchForDb = $branchId;
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
                        header('Location: user_master.php?msg=user_created');
                        exit;
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
            b.business_name, b.locality, r.RoleName
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

<?php if ($editId > 0 && $editRow !== null): ?>
<div class="card" style="margin-bottom:1.5rem">
    <div class="card__head">Edit user</div>
    <div class="card__body" style="padding:1.25rem">
        <form class="form" method="post" action="user_master.php?edit=<?= (int) $editId ?>" style="max-width:480px" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="_action" value="update">
            <input type="hidden" name="user_id" value="<?= (int) $editRow['id'] ?>">
            <div class="form__row">
                <label for="edit_loginname">Login name</label>
                <input id="edit_loginname" name="loginname" type="text" required maxlength="20"
                       value="<?= e((string) ($editRow['loginname'] ?? '')) ?>">
            </div>
            <div class="form__row">
                <label for="edit_password">New password <span class="hint">(optional)</span></label>
                <input id="edit_password" name="password" type="password" maxlength="20" placeholder="Leave blank to keep current">
            </div>
            <div class="form__row">
                <label for="edit_full_name">Full name</label>
                <input id="edit_full_name" name="full_name" type="text" required maxlength="255"
                       value="<?= e((string) ($editRow['FullName'] ?? '')) ?>">
            </div>
            <div class="form__row">
                <label for="edit_branch_id">Branch</label>
                <select id="edit_branch_id" name="branch_id" required>
                    <option value="">— Select branch —</option>
                    <?php foreach ($branchesAll as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"
                            <?= ((int) ($editRow['BranchId'] ?? 0) === (int) $b['id']) ? ' selected' : '' ?>>
                            <?= e((string) $b['business_name']) ?>
                            <?= ($b['locality'] ?? '') !== '' ? ' - ' . e((string) $b['locality']) : '' ?>
                            <?= ((int) $b['isActive'] !== 1) ? ' (inactive)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form__row">
                <label for="edit_role_id">Role</label>
                <select id="edit_role_id" name="role_id" required>
                    <?php foreach ($rolesAll as $r): ?>
                        <option value="<?= (int) $r['id'] ?>"
                            <?= ((int) ($editRow['RoleId'] ?? 0) === (int) $r['id']) ? ' selected' : '' ?>>
                            <?= e((string) $r['RoleName']) ?><?= ((int) $r['isActive'] !== 1) ? ' (inactive)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="is_active" value="1"<?= ((int) ($editRow['isactive'] ?? 0) === 1) ? ' checked' : '' ?>>
                    Active
                </label>
            </div>
            <div class="form__actions">
                <button class="btn btn--primary" type="submit">Save changes</button>
                <a class="btn btn--ghost" href="user_master.php">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card__head">New user</div>
    <div class="card__body" style="padding:1.25rem">
        <form class="form" method="post" action="user_master.php" style="max-width:480px" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="_action" value="create">
            <div class="form__row">
                <label for="loginname">Login name</label>
                <input id="loginname" name="loginname" type="text" required maxlength="20"
                       value="<?= (!$editId && isset($_POST['loginname']) && ($_POST['_action'] ?? '') === 'create') ? e((string) $_POST['loginname']) : '' ?>">
            </div>
            <div class="form__row">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required maxlength="20">
            </div>
            <div class="form__row">
                <label for="full_name">Full name</label>
                <input id="full_name" name="full_name" type="text" required maxlength="255"
                       value="<?= (!$editId && isset($_POST['full_name']) && ($_POST['_action'] ?? '') === 'create') ? e((string) $_POST['full_name']) : '' ?>">
            </div>
            <div class="form__row">
                <label for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id" required>
                    <option value="">— Select branch —</option>
                    <?php foreach ($branchesActive as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"
                            <?= (!$editId && isset($_POST['branch_id']) && (int) $_POST['branch_id'] === (int) $b['id'] && ($_POST['_action'] ?? '') === 'create') ? ' selected' : '' ?>>
                            <?= e((string) $b['business_name']) ?>
                            <?= ($b['locality'] ?? '') !== '' ? ' - ' . e((string) $b['locality']) : '' ?>
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
                            <?= (!$editId && isset($_POST['role_id']) && (string) $_POST['role_id'] === (string) $r['id'] && ($_POST['_action'] ?? '') === 'create') ? ' selected' : '' ?>>
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
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list as $u): ?>
                            <tr>
                                <td><?= e((string) $u['loginname']) ?></td>
                                <td><?= e((string) $u['FullName']) ?></td>
                                <td>
                                    <?= e((string) ($u['business_name'] ?? '—')) ?>
                                    <?php if (($u['locality'] ?? '') !== ''): ?>
                                        <?= ' - ' . e((string) $u['locality']) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) $u['RoleName']) ?></td>
                                <td><?= ((int) $u['isactive'] === 1) ? 'Yes' : 'No' ?></td>
                                <td class="table-actions"><a href="user_master.php?edit=<?= (int) $u['id'] ?>">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
