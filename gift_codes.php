<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/gift_helpers.php';
require_login();

$user = current_user();
$userBranchId = (int) ($user['branch_id'] ?? 0);
$branchLocality = '';
if ($userBranchId > 0) {
    try {
        $mainPdo = db();
        $bs = $mainPdo->prepare('SELECT locality FROM allureone_branch WHERE id = :id AND isActive = 1 LIMIT 1');
        $bs->execute(['id' => $userBranchId]);
        $branchRow = $bs->fetch();
        if ($branchRow !== false) {
            $branchLocality = trim((string) ($branchRow['locality'] ?? ''));
        }
    } catch (PDOException $e) {
        error_log('AllureOne gift_codes branch lookup: ' . $e->getMessage());
    }
}

$giftRows = [];
try {
    $pdo = wp_db();
    $baseSelect = "SELECT
            oi.order_item_id,
            oi.order_id,
            MAX(CASE WHEN oim.meta_key = '_ywgc_gift_card_code' THEN oim.meta_value END) AS gift_card_code,
            (
                SELECT pm2.meta_value
                FROM wp_postmeta pm2
                JOIN wp_posts gp ON gp.ID = pm2.post_id
                WHERE pm2.meta_key = '_ywgc_recipient'
                  AND gp.post_title = (
                      SELECT oim2.meta_value
                      FROM wp_woocommerce_order_itemmeta oim2
                      WHERE oim2.order_item_id = oi.order_item_id
                        AND oim2.meta_key = '_ywgc_gift_card_code'
                      LIMIT 1
                  )
                LIMIT 1
            ) AS recipient_email,
            MAX(CASE WHEN oim.meta_key = '_ywgc_recipient_name' THEN oim.meta_value END) AS recipient_name,
            MAX(CASE WHEN oim.meta_key = '_ywgc_sender_name' THEN oim.meta_value END) AS sender_name,
            MAX(CASE WHEN oim.meta_key = '_ywgc_message' THEN oim.meta_value END) AS message,
            MAX(CASE WHEN oim.meta_key = '_line_total' THEN oim.meta_value END) AS amount,
            p.post_date,
            REPLACE(p.post_status, 'wc-', '') AS order_status,
            MAX(CASE WHEN pm.meta_key = '_billing_first_name' THEN pm.meta_value END) AS buyer_first_name,
            MAX(CASE WHEN pm.meta_key = '_billing_email' THEN pm.meta_value END) AS buyer_email,
            MAX(CASE WHEN pm.meta_key = '_billing_phone' THEN pm.meta_value END) AS buyer_phone,
            MAX(CASE WHEN pm.meta_key = 'billing_location' THEN pm.meta_value END) AS location,
            MAX(CASE WHEN pm.meta_key = '_payment_method' THEN pm.meta_value END) AS payment_method,
            MAX(CASE WHEN pm.meta_key = '_transaction_id' THEN pm.meta_value END) AS transaction_id,
            MAX(CASE WHEN pm.meta_key = '_razorpay_payment_id' THEN pm.meta_value END) AS razorpay_payment_id
        FROM wp_woocommerce_order_items oi
        JOIN wp_woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
        JOIN wp_posts p ON p.ID = oi.order_id
        LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id
        WHERE oi.order_item_type = 'line_item'
          AND oi.order_item_id IN (
              SELECT order_item_id
              FROM wp_woocommerce_order_itemmeta
              WHERE meta_key = '_ywgc_gift_card_code'
          )";

    $baseParams = [];
    if ($branchLocality !== '' && gift_cards_filter_by_branch_locality_enabled()) {
        $baseSelect .= "
          AND EXISTS (
              SELECT 1
              FROM wp_postmeta pmf
              WHERE pmf.post_id = oi.order_id
                AND pmf.meta_key = 'billing_location'
                AND LOWER(TRIM(pmf.meta_value)) = LOWER(TRIM(:branch_locality))
          )";
        $baseParams['branch_locality'] = $branchLocality;
    }

    $listSql = $baseSelect . "
        GROUP BY oi.order_item_id, oi.order_id, p.post_date, p.post_status
        ORDER BY p.post_date DESC
        LIMIT 10";
    $stmt = $pdo->prepare($listSql);
    $stmt->execute($baseParams);
    $giftRows = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('AllureOne gift_codes WP DB: ' . $e->getMessage());
}

$pageTitle = 'Gift codes';
$activeNav = 'gift_codes';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="card">
    <div class="card__head">
        <span>Recent gift codes</span>
    </div>
    <div class="card__body">
        <?php if (count($giftRows) === 0): ?>
            <p class="empty">No gift cards yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Gift code</th>
                            <th>Location</th>
                            <th>Buyer name</th>
                            <th>Amount</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($giftRows as $gr): ?>
                            <?php
                            $itemId = (int) ($gr['order_item_id'] ?? 0);
                            $codeDisplay = e(extract_gift_code((string) ($gr['gift_card_code'] ?? '')));
                            ?>
                            <tr>
                                <td>
                                    <a class="link--underlined" href="dashboard.php?gift=<?= $itemId ?>"><?= $codeDisplay ?></a>
                                </td>
                                <td>
                                    <a class="link--underlined" href="dashboard.php?gift=<?= $itemId ?>"><?= e((string) ($gr['location'] ?? '')) ?></a>
                                </td>
                                <td><?= e((string) ($gr['sender_name'] ?? '')) ?></td>
                                <td><?= e(format_amount($gr['amount'] ?? null)) ?></td>
                                <td><?= e(format_purchase_date($gr['post_date'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
