<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_superadmin();

$pdo = db();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $message = 'Invalid session. Please refresh and try again.';
        $messageType = 'error';
    } else {
        $name = isset($_POST['branch_name']) ? trim((string) $_POST['branch_name']) : '';
        $location = isset($_POST['location']) ? trim((string) $_POST['location']) : '';
        if ($name === '') {
            $message = 'Branch name is required.';
            $messageType = 'error';
        } elseif ((function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 255) {
            $message = 'Branch name is too long.';
            $messageType = 'error';
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO allureone_branch (BranchName, Location, isActive) VALUES (:n, :l, 1)'
            );
            $ins->execute(['n' => $name, 'l' => $location]);
            $message = 'Branch created.';
            $messageType = 'ok';
        }
    }
}

$list = $pdo->query(
    'SELECT id, BranchName, Location, isActive FROM allureone_branch ORDER BY id ASC'
)->fetchAll();

$pageTitle = 'Branch Master';
$activeNav = 'branch';
require __DIR__ . '/includes/layout_start.php';
?>

<?php if ($message !== ''): ?>
    <div class="alert alert--<?= $messageType === 'ok' ? 'ok' : 'error' ?>" style="margin-bottom:1rem"><?= e($message) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card__head">New branch</div>
    <div class="card__body" style="padding:1.25rem">
        <form class="form" method="post" action="branch_master.php" style="max-width:480px">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <div class="form__row">
                <label for="branch_name">Branch name</label>
                <input id="branch_name" name="branch_name" type="text" required maxlength="255"
                       value="<?= isset($_POST['branch_name']) ? e((string) $_POST['branch_name']) : '' ?>">
            </div>
            <div class="form__row">
                <label for="location">Location</label>
                <textarea id="location" name="location" maxlength="2000"><?= isset($_POST['location']) ? e((string) $_POST['location']) : '' ?></textarea>
            </div>
            <button class="btn btn--primary" type="submit">Create branch</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card__head">Branches</div>
    <div class="card__body">
        <?php if (count($list) === 0): ?>
            <p class="empty">No branches.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Location</th>
                            <th>Active</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list as $b): ?>
                            <tr>
                                <td><?= (int) $b['id'] ?></td>
                                <td><?= e((string) $b['BranchName']) ?></td>
                                <td><?= e((string) ($b['Location'] ?? '')) ?></td>
                                <td><?= ((int) $b['isActive'] === 1) ? 'Yes' : 'No' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
