<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

/**
 * @return array<int, array<string, mixed>>
 */
function allureone_invoice_cancellation_list_for_user(array $user): array
{
    $roleId = (int) ($user['role_id'] ?? 0);
    $userId = (int) ($user['id'] ?? 0);
    $branchId = isset($user['branch_id']) && (int) $user['branch_id'] > 0 ? (int) $user['branch_id'] : null;
    $isAdminScope = $roleId === ROLE_ADMIN || $roleId === ROLE_SUPERADMIN;

    $sql = 'SELECT
        id,
        `Branch Name` AS branch_name,
        `Branch ID` AS branch_id,
        `Invoice Number` AS invoice_number,
        `Invoice ID` AS invoice_id,
        `Invoice Date` AS invoice_date,
        `Client Name` AS client_name,
        `Invoice Amount` AS invoice_amount,
        `Invoice Status` AS invoice_status,
        CancellationRemark AS cancellation_remark,
        AdminRemark AS admin_remark,
        CancellationRequestDate AS cancellation_request_date,
        CancelledDate AS cancelled_date,
        CancellationStatus AS cancellation_status,
        RequestUserID AS request_user_id,
        RequestUserName AS request_user_name,
        AdminID AS admin_id,
        AdminName AS admin_name
    FROM allurepro_InvoiceCancellation';
    $params = [];
    $filters = [];

    if ($isAdminScope) {
        if ($branchId !== null && $branchId > 0) {
            $filters[] = '`Branch ID` = :branch_id';
            $params['branch_id'] = $branchId;
        }
    } else {
        $filters[] = 'RequestUserID = :request_user_id';
        $params['request_user_id'] = $userId;
    }

    if ($filters !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $filters);
    }
    $sql .= ' ORDER BY id DESC LIMIT 10';

    $st = db()->prepare($sql);
    $st->execute($params);

    return $st->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function allureone_invoice_cancellation_detail_for_user(int $id, array $user): ?array
{
    if ($id <= 0) {
        return null;
    }

    $roleId = (int) ($user['role_id'] ?? 0);
    $userId = (int) ($user['id'] ?? 0);
    $branchId = isset($user['branch_id']) && (int) $user['branch_id'] > 0 ? (int) $user['branch_id'] : null;
    $isAdminScope = $roleId === ROLE_ADMIN || $roleId === ROLE_SUPERADMIN;

    $sql = 'SELECT
        id,
        `Branch Name` AS branch_name,
        `Branch ID` AS branch_id,
        `Invoice Number` AS invoice_number,
        `Invoice ID` AS invoice_id,
        `Invoice Date` AS invoice_date,
        `Client Name` AS client_name,
        `Invoice Amount` AS invoice_amount,
        `Invoice Status` AS invoice_status,
        CancellationRemark AS cancellation_remark,
        AdminRemark AS admin_remark,
        CancellationRequestDate AS cancellation_request_date,
        CancelledDate AS cancelled_date,
        CancellationStatus AS cancellation_status,
        RequestUserID AS request_user_id,
        RequestUserName AS request_user_name,
        AdminID AS admin_id,
        AdminName AS admin_name
    FROM allurepro_InvoiceCancellation
    WHERE id = :id';
    $params = ['id' => $id];

    if ($isAdminScope) {
        if ($branchId !== null && $branchId > 0) {
            $sql .= ' AND `Branch ID` = :branch_id';
            $params['branch_id'] = $branchId;
        }
    } else {
        $sql .= ' AND RequestUserID = :request_user_id';
        $params['request_user_id'] = $userId;
    }
    $sql .= ' LIMIT 1';

    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();

    return is_array($row) ? $row : null;
}

function allureone_invoice_cancellation_status_label(int $status): string
{
    if ($status === 1) {
        return 'Cancelled';
    }
    if ($status === 2) {
        return 'Rejected';
    }

    return 'Pending';
}

function allureone_format_datetime_ist(?string $utcDateTime): string
{
    $raw = trim((string) ($utcDateTime ?? ''));
    if ($raw === '') {
        return '—';
    }
    try {
        $dt = new DateTime($raw, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));

        return $dt->format('d-M-Y h:i A');
    } catch (Exception $e) {
        return $raw;
    }
}

$user = current_user();
$detailId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$detailRow = null;
$rows = [];
$loadError = '';

try {
    if ($detailId > 0) {
        $detailRow = allureone_invoice_cancellation_detail_for_user($detailId, $user ?? []);
    } else {
        $rows = allureone_invoice_cancellation_list_for_user($user ?? []);
    }
} catch (PDOException $e) {
    error_log('AllureOne invoice cancellation page failed: ' . $e->getMessage());
    $loadError = 'Could not load invoice cancellation requests.';
}

$pageTitle = 'Invoice Cancellation';
$activeNav = 'invoice_cancellation';
require __DIR__ . '/includes/layout_start.php';
?>

<?php if ($loadError !== ''): ?>
    <p class="alert alert--error" style="margin-top:0;margin-bottom:1rem"><?= e($loadError) ?></p>
<?php endif; ?>

<details class="card" open>
    <summary class="card__head card__toggle">
        <span class="card__toggle-inner">
            <span>Invoice Cancellation Requests</span>
            <span class="card__chevron" aria-hidden="true">▼</span>
        </span>
    </summary>
    <div class="card__body" style="padding:1.25rem">
        <?php if ($detailId > 0): ?>
            <?php if ($detailRow === null): ?>
                <p class="empty" style="margin-top:0">Request not found or access denied.</p>
                <p style="margin-bottom:0"><a class="btn btn--ghost" href="invoice_cancellation.php">Back to List</a></p>
            <?php else: ?>
                <table class="data">
                    <tbody>
                        <tr><th>Branch</th><td><?= e((string) ($detailRow['branch_name'] ?? '')) ?></td></tr>
                        <tr><th>Branch ID</th><td><?= (int) ($detailRow['branch_id'] ?? 0) ?></td></tr>
                        <tr><th>Invoice Number</th><td><?= e((string) ($detailRow['invoice_number'] ?? '')) ?></td></tr>
                        <tr><th>Invoice ID</th><td><?= (int) ($detailRow['invoice_id'] ?? 0) ?></td></tr>
                        <tr><th>Invoice Date</th><td><?= e((string) ($detailRow['invoice_date'] ?? '')) ?></td></tr>
                        <tr><th>Client Name</th><td><?= e((string) ($detailRow['client_name'] ?? '')) ?></td></tr>
                        <tr><th>Amount</th><td><?= e((string) ($detailRow['invoice_amount'] ?? '')) ?></td></tr>
                        <tr><th>Status</th><td><?= e(allureone_invoice_cancellation_status_label((int) ($detailRow['cancellation_status'] ?? 0))) ?></td></tr>
                        <tr><th>Invoice Status</th><td><?= e((string) ($detailRow['invoice_status'] ?? '')) ?></td></tr>
                        <tr><th>Cancellation Reason</th><td><?= e((string) ($detailRow['cancellation_remark'] ?? '')) ?></td></tr>
                        <tr><th>Admin Remark</th><td><?= e((string) ($detailRow['admin_remark'] ?? '')) ?></td></tr>
                        <tr><th>Requested By</th><td><?= e((string) ($detailRow['request_user_name'] ?? '')) ?> (ID: <?= (int) ($detailRow['request_user_id'] ?? 0) ?>)</td></tr>
                        <tr><th>Requested On</th><td><?= e(allureone_format_datetime_ist((string) ($detailRow['cancellation_request_date'] ?? ''))) ?></td></tr>
                        <tr><th>Reviewed By</th><td><?= e((string) ($detailRow['admin_name'] ?? '')) ?><?= (int) ($detailRow['admin_id'] ?? 0) > 0 ? ' (ID: ' . (int) $detailRow['admin_id'] . ')' : '' ?></td></tr>
                        <tr><th>Reviewed On</th><td><?= e(allureone_format_datetime_ist((string) ($detailRow['cancelled_date'] ?? ''))) ?></td></tr>
                    </tbody>
                </table>
                <p style="margin-bottom:0;margin-top:0.75rem"><a class="btn btn--ghost" href="invoice_cancellation.php">Back to List</a></p>
            <?php endif; ?>
        <?php else: ?>
            <?php if (count($rows) === 0): ?>
                <p class="empty" style="margin:0">No invoice cancellation requests found.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Branch</th>
                                <th>Invoice Number</th>
                                <th>Client Name</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?= e((string) ($row['branch_name'] ?? '')) ?></td>
                                    <td><a class="link--underlined" href="invoice_cancellation.php?id=<?= (int) ($row['id'] ?? 0) ?>"><?= e((string) ($row['invoice_number'] ?? '')) ?></a></td>
                                    <td><?= e((string) ($row['client_name'] ?? '')) ?></td>
                                    <td><?= e((string) ($row['invoice_amount'] ?? '')) ?></td>
                                    <td><?= e(allureone_invoice_cancellation_status_label((int) ($row['cancellation_status'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</details>

<?php require __DIR__ . '/includes/layout_end.php'; ?>

