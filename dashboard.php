<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$pdo = db();
$stmt = $pdo->query(
    'SELECT SenderName AS BuyerName, GiftCode, PurchaseDate, PaymentStatus
     FROM allureone_giftcard
     WHERE isActive = 1
     ORDER BY PurchaseDate DESC
     LIMIT 10'
);
$giftRows = $stmt->fetchAll();

function format_purchase_date(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '—';
    }
    $t = strtotime($dt);
    if ($t === false) {
        return '—';
    }
    return date('d-M-y', $t);
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="card">
    <div class="card__head">Gift cards (latest 10)</div>
    <div class="card__body">
        <?php if (count($giftRows) === 0): ?>
            <p class="empty">No gift cards yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Buyer name</th>
                            <th>Gift code</th>
                            <th>Purchase date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($giftRows as $gr): ?>
                            <tr>
                                <td><?= e((string) ($gr['BuyerName'] ?? '')) ?></td>
                                <td><?= e((string) ($gr['GiftCode'] ?? '')) ?></td>
                                <td><?= e(format_purchase_date($gr['PurchaseDate'] ?? null)) ?></td>
                                <td><?= e((string) ($gr['PaymentStatus'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
