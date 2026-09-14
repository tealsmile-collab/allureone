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
        'SELECT id, loginname, FullName, MobileNo, EmailId, BranchId, RoleId, isactive, RecordSale, MetaConfig, GoogleAdsView, CrmSegments FROM allureone_users WHERE id = :id LIMIT 1'
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
        $mobileNo = isset($_POST['mobile_no']) ? trim((string) $_POST['mobile_no']) : '';
        $emailId = isset($_POST['email_id']) ? trim((string) $_POST['email_id']) : '';
        $branchId = isset($_POST['branch_id']) ? (int) $_POST['branch_id'] : 0;
        $roleId = isset($_POST['role_id']) ? (int) $_POST['role_id'] : 0;
        $len = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';

        if ($action === 'update') {
            $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $recordSale = isset($_POST['record_sale']) ? 1 : 0;
            $metaConfig = isset($_POST['meta_config']) ? 1 : 0;
            $googleAdsView = isset($_POST['google_ads_view']) ? 1 : 0;
            $crmSegments = isset($_POST['crm_segments']) ? 1 : 0;
            if ($userId < 1) {
                $message = 'Invalid user.';
                $messageType = 'error';
            } elseif ($loginname === '' || $fullName === '') {
                $message = 'Login name and full name are required.';
                $messageType = 'error';
            } elseif ($len($loginname) > 20) {
                $message = 'Login name must be at most 20 characters.';
                $messageType = 'error';
            } elseif ($password !== '' && $len($password) > 20) {
                $message = 'Password must be at most 20 characters.';
                $messageType = 'error';
            } elseif ($mobileNo !== '' && $len($mobileNo) > 20) {
                $message = 'Mobile number must be at most 20 characters.';
                $messageType = 'error';
            } elseif ($emailId !== '' && $len($emailId) > 255) {
                $message = 'Email ID must be at most 255 characters.';
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
                        if ($branchId > 0) {
                            $bchk = $pdo->prepare('SELECT COUNT(*) FROM allureone_branch WHERE id = :id');
                            $bchk->execute(['id' => $branchId]);
                            if ((int) $bchk->fetchColumn() === 0) {
                                $message = 'Invalid branch.';
                                $messageType = 'error';
                            } else {
                                $branchForDb = $branchId;
                            }
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
                                             MobileNo = :m, EmailId = :e, BranchId = :b, RoleId = :r, isactive = :a, RecordSale = :rs, MetaConfig = :mc, GoogleAdsView = :gav, CrmSegments = :cs WHERE id = :id'
                                        );
                                        $upd->execute([
                                            'l' => $loginname,
                                            'p' => $hash,
                                            'f' => $fullName,
                                            'm' => $mobileNo !== '' ? $mobileNo : null,
                                            'e' => $emailId !== '' ? $emailId : null,
                                            'b' => $branchForDb,
                                            'r' => $roleId,
                                            'a' => $isActive,
                                            'rs' => $recordSale,
                                            'mc' => $metaConfig,
                                            'gav' => $googleAdsView,
                                            'cs' => $crmSegments,
                                            'id' => $userId,
                                        ]);
                                    } else {
                                        $upd = $pdo->prepare(
                                            'UPDATE allureone_users SET loginname = :l, FullName = :f,
                                             MobileNo = :m, EmailId = :e, BranchId = :b, RoleId = :r, isactive = :a, RecordSale = :rs, MetaConfig = :mc, GoogleAdsView = :gav, CrmSegments = :cs WHERE id = :id'
                                        );
                                        $upd->execute([
                                            'l' => $loginname,
                                            'f' => $fullName,
                                            'm' => $mobileNo !== '' ? $mobileNo : null,
                                            'e' => $emailId !== '' ? $emailId : null,
                                            'b' => $branchForDb,
                                            'r' => $roleId,
                                            'a' => $isActive,
                                            'rs' => $recordSale,
                                            'mc' => $metaConfig,
                                            'gav' => $googleAdsView,
                                            'cs' => $crmSegments,
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
        } elseif ($loginname === '' || $fullName === '') {
            $message = 'Login name and full name are required.';
            $messageType = 'error';
        } elseif ($len($loginname) > 20 || ($password !== '' && $len($password) > 20)) {
            $message = 'Login name and password must be at most 20 characters.';
            $messageType = 'error';
        } elseif ($mobileNo !== '' && $len($mobileNo) > 20) {
            $message = 'Mobile number must be at most 20 characters.';
            $messageType = 'error';
        } elseif ($emailId !== '' && $len($emailId) > 255) {
            $message = 'Email ID must be at most 255 characters.';
            $messageType = 'error';
        } elseif ($roleId < 1) {
            $message = 'Please select a role.';
            $messageType = 'error';
        } else {
            $recordSale = isset($_POST['record_sale']) ? 1 : 0;
            $metaConfig = isset($_POST['meta_config']) ? 1 : 0;
            $googleAdsView = isset($_POST['google_ads_view']) ? 1 : 0;
            $crmSegments = isset($_POST['crm_segments']) ? 1 : 0;
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
                    $passwordForHash = $password !== '' ? $password : bin2hex(random_bytes(16));
                    $hash = password_hash($passwordForHash, PASSWORD_DEFAULT);
                    try {
                        $ins = $pdo->prepare(
                            'INSERT INTO allureone_users (loginname, password, FullName, MobileNo, EmailId, BranchId, RoleId, isactive, RecordSale, MetaConfig, GoogleAdsView, CrmSegments)
                             VALUES (:l, :p, :f, :m, :e, :b, :r, 1, :rs, :mc, :gav, :cs)'
                        );
                        $ins->execute([
                            'l' => $loginname,
                            'p' => $hash,
                            'f' => $fullName,
                            'm' => $mobileNo !== '' ? $mobileNo : null,
                            'e' => $emailId !== '' ? $emailId : null,
                            'b' => $branchForDb,
                            'r' => $roleId,
                            'rs' => $recordSale,
                            'mc' => $metaConfig,
                            'gav' => $googleAdsView,
                            'cs' => $crmSegments,
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

$searchName = trim((string) ($_GET['q'] ?? ''));
if (function_exists('mb_substr')) {
    $searchName = mb_substr($searchName, 0, 100);
} else {
    $searchName = substr($searchName, 0, 100);
}
$listPerPage = 15;
$listPage = max(1, (int) ($_GET['page'] ?? 1));
$listWhereSql = '';
$listWhereBind = [];
if ($searchName !== '') {
    $listWhereSql = ' WHERE u.FullName LIKE :q_name OR u.loginname LIKE :q_login';
    $like = '%' . $searchName . '%';
    $listWhereBind['q_name'] = $like;
    $listWhereBind['q_login'] = $like;
}

$countStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM allureone_users u
     LEFT JOIN allureone_branch b ON b.id = u.BranchId
     JOIN allureone_roles r ON r.id = u.RoleId'
    . $listWhereSql
);
$countStmt->execute($listWhereBind);
$listTotal = (int) $countStmt->fetchColumn();
$listTotalPages = max(1, (int) ceil($listTotal / $listPerPage));
if ($listPage > $listTotalPages) {
    $listPage = $listTotalPages;
}
$listOffset = ($listPage - 1) * $listPerPage;

$listStmt = $pdo->prepare(
    'SELECT u.id, u.loginname, u.FullName, u.MobileNo, u.EmailId, u.BranchId, u.RoleId, u.isactive, u.RecordSale, u.MetaConfig, u.GoogleAdsView, u.CrmSegments,
            b.business_name, b.locality, r.RoleName
     FROM allureone_users u
     LEFT JOIN allureone_branch b ON b.id = u.BranchId
     JOIN allureone_roles r ON r.id = u.RoleId'
    . $listWhereSql
    . ' ORDER BY u.id DESC
     LIMIT ' . (int) $listPerPage . ' OFFSET ' . (int) $listOffset
);
$listStmt->execute($listWhereBind);
$list = $listStmt->fetchAll();

$listQueryBase = [];
if ($searchName !== '') {
    $listQueryBase['q'] = $searchName;
}

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
                <label for="edit_full_name">Full name</label>
                <input id="edit_full_name" name="full_name" type="text" required maxlength="255"
                       value="<?= e((string) ($editRow['FullName'] ?? '')) ?>">
            </div>
            <div class="form__row">
                <label for="edit_mobile_no">Mobile number</label>
                <input id="edit_mobile_no" name="mobile_no" type="text" maxlength="20"
                       value="<?= e((string) ($editRow['MobileNo'] ?? '')) ?>">
            </div>
            <div class="form__row">
                <label for="edit_password">New password <span class="hint">(optional)</span></label>
                <div class="user-password-gen" data-password-gen data-name-input="edit_full_name" data-mobile-input="edit_mobile_no">
                    <input id="edit_password" name="password" type="password" maxlength="20" placeholder="Leave blank to keep current" autocomplete="new-password">
                    <button type="button" class="btn btn--ghost" data-password-generate>Generate</button>
                    <button type="button" class="btn btn--ghost user-password-gen__copy" data-password-copy hidden title="Copy password" aria-label="Copy password">
                        <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path fill="currentColor" d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>
                        </svg>
                    </button>
                </div>
            </div>
            <div class="form__row">
                <label for="edit_email_id">Email ID</label>
                <input id="edit_email_id" name="email_id" type="email" maxlength="255"
                       value="<?= e((string) ($editRow['EmailId'] ?? '')) ?>">
            </div>
            <div class="form__row">
                <label for="edit_branch_id">Branch</label>
                <select id="edit_branch_id" name="branch_id">
                    <option value="">— None —</option>
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
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="record_sale" value="1"<?= ((int) ($editRow['RecordSale'] ?? 0) === 1) ? ' checked' : '' ?>>
                    Record Sale
                </label>
            </div>
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="meta_config" value="1"<?= ((int) ($editRow['MetaConfig'] ?? 0) === 1) ? ' checked' : '' ?>>
                    Meta Config
                </label>
            </div>
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="google_ads_view" value="1"<?= ((int) ($editRow['GoogleAdsView'] ?? 0) === 1) ? ' checked' : '' ?>>
                    Google Ads View
                </label>
            </div>
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="crm_segments" value="1"<?= ((int) ($editRow['CrmSegments'] ?? 0) === 1) ? ' checked' : '' ?>>
                    CRM Segments
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

<?php
$newUserOpen = (!$editId && ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['_action'] ?? '') === 'create'));
?>
<details class="card" style="margin-bottom:1.5rem"<?= $newUserOpen ? ' open' : '' ?>>
    <summary class="card__head card__toggle">
        <span class="card__toggle-inner">
            <span>New user</span>
            <span class="card__chevron" aria-hidden="true">▼</span>
        </span>
    </summary>
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
                <label for="full_name">Full name</label>
                <input id="full_name" name="full_name" type="text" required maxlength="255"
                       value="<?= (!$editId && isset($_POST['full_name']) && ($_POST['_action'] ?? '') === 'create') ? e((string) $_POST['full_name']) : '' ?>">
            </div>
            <div class="form__row">
                <label for="mobile_no">Mobile number</label>
                <input id="mobile_no" name="mobile_no" type="text" maxlength="20"
                       value="<?= (!$editId && isset($_POST['mobile_no']) && ($_POST['_action'] ?? '') === 'create') ? e((string) $_POST['mobile_no']) : '' ?>">
            </div>
            <div class="form__row">
                <label for="password">New password <span class="hint">(optional)</span></label>
                <div class="user-password-gen" data-password-gen data-name-input="full_name" data-mobile-input="mobile_no">
                    <input id="password" name="password" type="password" maxlength="20" placeholder="Optional" autocomplete="new-password">
                    <button type="button" class="btn btn--ghost" data-password-generate>Generate</button>
                    <button type="button" class="btn btn--ghost user-password-gen__copy" data-password-copy hidden title="Copy password" aria-label="Copy password">
                        <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path fill="currentColor" d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>
                        </svg>
                    </button>
                </div>
            </div>
            <div class="form__row">
                <label for="email_id">Email ID</label>
                <input id="email_id" name="email_id" type="email" maxlength="255"
                       value="<?= (!$editId && isset($_POST['email_id']) && ($_POST['_action'] ?? '') === 'create') ? e((string) $_POST['email_id']) : '' ?>">
            </div>
            <div class="form__row">
                <label for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id">
                    <option value="">— None —</option>
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
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="record_sale" value="1"<?= (!$editId && isset($_POST['record_sale']) && ($_POST['_action'] ?? '') === 'create') ? ' checked' : '' ?>>
                    Record Sale
                </label>
            </div>
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="meta_config" value="1"<?= (!$editId && isset($_POST['meta_config']) && ($_POST['_action'] ?? '') === 'create') ? ' checked' : '' ?>>
                    Meta Config
                </label>
            </div>
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="google_ads_view" value="1"<?= (!$editId && isset($_POST['google_ads_view']) && ($_POST['_action'] ?? '') === 'create') ? ' checked' : '' ?>>
                    Google Ads View
                </label>
            </div>
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="crm_segments" value="1"<?= (!$editId && isset($_POST['crm_segments']) && ($_POST['_action'] ?? '') === 'create') ? ' checked' : '' ?>>
                    CRM Segments
                </label>
            </div>
            <button class="btn btn--primary" type="submit">Create user</button>
        </form>
    </div>
</details>

<div class="card">
    <div class="card__head">Users<?= $listTotal > 0 ? ' (' . $listTotal . ')' : '' ?></div>
    <div class="card__body">
        <form method="get" action="user_master.php" class="form form--invoice-search" style="padding:0 0 1rem">
            <div class="form__row">
                <label for="user_search_q">Search by name</label>
                <input id="user_search_q" name="q" type="text" maxlength="100" value="<?= e($searchName) ?>" placeholder="Full name or login">
            </div>
            <div class="form__row form__row--submit">
                <button type="submit" class="btn btn--primary">Search</button>
                <?php if ($searchName !== ''): ?>
                    <a class="btn btn--ghost" href="user_master.php">Clear</a>
                <?php endif; ?>
            </div>
        </form>
        <?php if (count($list) === 0): ?>
            <p class="empty"><?= $searchName !== '' ? 'No users matched your search.' : 'No users.' ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Login</th>
                            <th>Full name</th>
                            <th>Mobile</th>
                            <th>Branch</th>
                            <th>Role</th>
                            <th>Active</th>
                            <th>Record Sale</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list as $u): ?>
                            <tr>
                                <td><?= e((string) $u['loginname']) ?></td>
                                <td><?= e((string) $u['FullName']) ?></td>
                                <td><?= e((string) ($u['MobileNo'] ?? '')) ?></td>
                                <td>
                                    <?php
                                    $branchLocality = trim((string) ($u['locality'] ?? ''));
                                    $branchBusiness = trim((string) ($u['business_name'] ?? ''));
                                    echo e($branchLocality !== '' ? $branchLocality : ($branchBusiness !== '' ? $branchBusiness : '—'));
                                    ?>
                                </td>
                                <td><?= e((string) $u['RoleName']) ?></td>
                                <td><?= ((int) $u['isactive'] === 1) ? 'Yes' : 'No' ?></td>
                                <td><?= ((int) ($u['RecordSale'] ?? 0) === 1) ? 'Yes' : 'No' ?></td>
                                <td class="table-actions"><a href="user_master.php?<?= e(http_build_query(array_merge($listQueryBase, ['edit' => (int) $u['id']]))) ?>">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($listTotalPages > 1): ?>
                <div class="form__actions" style="margin-top:1rem;align-items:center;gap:0.75rem">
                    <?php if ($listPage > 1): ?>
                        <a class="btn btn--ghost" href="user_master.php?<?= e(http_build_query(array_merge($listQueryBase, ['page' => $listPage - 1]))) ?>">Previous</a>
                    <?php endif; ?>
                    <span class="main__meta" style="margin:0">Page <?= (int) $listPage ?> of <?= (int) $listTotalPages ?></span>
                    <?php if ($listPage < $listTotalPages): ?>
                        <a class="btn btn--ghost" href="user_master.php?<?= e(http_build_query(array_merge($listQueryBase, ['page' => $listPage + 1]))) ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.user-password-gen {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex-wrap: wrap;
}
.user-password-gen input {
    flex: 1 1 12rem;
    min-width: 10rem;
}
.user-password-gen__copy {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.4rem 0.55rem;
}
.user-password-gen__copy[hidden] {
    display: none !important;
}
</style>
<script>
(function () {
    function pickRandom(chars, count) {
        var out = [];
        var i;
        if (!chars || !chars.length) {
            return out;
        }
        for (i = 0; i < count; i++) {
            out.push(chars.charAt(Math.floor(Math.random() * chars.length)));
        }
        return out;
    }

    function shuffle(arr) {
        var i, j, tmp;
        for (i = arr.length - 1; i > 0; i--) {
            j = Math.floor(Math.random() * (i + 1));
            tmp = arr[i];
            arr[i] = arr[j];
            arr[j] = tmp;
        }
        return arr;
    }

    function lettersOnly(s) {
        return String(s || '').replace(/[^A-Za-z]/g, '');
    }

    function digitsOnly(s) {
        return String(s || '').replace(/\D/g, '');
    }

    function generatePassword(fullName, mobile) {
        var nameChars = lettersOnly(fullName);
        var mobileChars = digitsOnly(mobile);
        if (!nameChars) {
            nameChars = 'abcdefghijklmnopqrstuvwxyz';
        }
        if (!mobileChars) {
            mobileChars = '0123456789';
        }
        var parts = []
            .concat(pickRandom(mobileChars, 3))
            .concat(pickRandom(nameChars, 4))
            .concat(['@', '$'])
            .concat(pickRandom('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 1));
        return shuffle(parts).join('').slice(0, 10);
    }

    function bindPasswordGen(wrap) {
        var nameId = wrap.getAttribute('data-name-input') || '';
        var mobileId = wrap.getAttribute('data-mobile-input') || '';
        var input = wrap.querySelector('input[name="password"]');
        var genBtn = wrap.querySelector('[data-password-generate]');
        var copyBtn = wrap.querySelector('[data-password-copy]');
        if (!input || !genBtn) {
            return;
        }

        genBtn.addEventListener('click', function () {
            var nameEl = nameId ? document.getElementById(nameId) : null;
            var mobileEl = mobileId ? document.getElementById(mobileId) : null;
            var fullName = nameEl ? String(nameEl.value || '') : '';
            var mobile = mobileEl ? String(mobileEl.value || '') : '';
            if (!lettersOnly(fullName)) {
                if (nameEl) {
                    nameEl.focus();
                }
                window.alert('Enter full name before generating password.');
                return;
            }
            if (!digitsOnly(mobile)) {
                if (mobileEl) {
                    mobileEl.focus();
                }
                window.alert('Enter mobile number before generating password.');
                return;
            }
            input.type = 'text';
            input.value = generatePassword(fullName, mobile);
            if (copyBtn) {
                copyBtn.hidden = false;
            }
            input.focus();
            input.select();
        });

        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var val = String(input.value || '');
                if (!val) {
                    return;
                }
                function done() {
                    var oldTitle = copyBtn.getAttribute('title') || 'Copy password';
                    copyBtn.setAttribute('title', 'Copied');
                    window.setTimeout(function () {
                        copyBtn.setAttribute('title', oldTitle);
                    }, 1200);
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(val).then(done).catch(function () {
                        input.type = 'text';
                        input.focus();
                        input.select();
                        try {
                            document.execCommand('copy');
                            done();
                        } catch (e) {}
                    });
                } else {
                    input.type = 'text';
                    input.focus();
                    input.select();
                    try {
                        document.execCommand('copy');
                        done();
                    } catch (e) {}
                }
            });
        }
    }

    document.querySelectorAll('[data-password-gen]').forEach(bindPasswordGen);
})();
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
