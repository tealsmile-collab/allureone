<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/work_branch.php';
require_login();

$return = basename((string) ($_POST['return'] ?? ''));
$allowedReturns = ['leads.php', 'crm.php', 'expense.php'];
if (!in_array($return, $allowedReturns, true)) {
    $return = 'leads.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
    header('Location: ' . $return);
    exit;
}

$branchId = (int) ($_POST['work_branch_id'] ?? 0);
$user = current_user();
if ($branchId > 0 && work_branch_user_can($user, $branchId)) {
    $_SESSION['work_branch_id'] = $branchId;
} else {
    $_SESSION['work_branch_id'] = 0;
}

header('Location: ' . $return);
exit;
