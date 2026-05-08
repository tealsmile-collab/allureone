<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/gift_helpers.php';
require_login();
require_not_franchise_officer_role();

$user = current_user();
$userBranchId = (int) ($user['branch_id'] ?? 0);
$selectedItemId = isset($_GET['gift']) ? (int) $_GET['gift'] : 0;
$listPerPage = 20;
$listPage = max(1, (int) ($_GET['page'] ?? 1));
$listTotal = 0;
$listTotalPages = 1;
$branchLocality = '';
$redeemedLocationOptions = [];
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
try {
    $mainPdo = db();
    $rls = $mainPdo->query(
        'SELECT locality
         FROM allureone_branch
         WHERE isActive = 1
           AND locality IS NOT NULL
           AND TRIM(locality) <> \'\'
         ORDER BY locality ASC'
    );
    foreach ($rls->fetchAll(PDO::FETCH_ASSOC) as $rlRow) {
        $loc = trim((string) ($rlRow['locality'] ?? ''));
        if ($loc !== '') {
            $redeemedLocationOptions[$loc] = $loc;
        }
    }
} catch (PDOException $e) {
    error_log('AllureOne gift_codes redeemed location options lookup: ' . $e->getMessage());
}

$giftRows = [];
$giftDetail = null;
$markRedeemedFlash = null;
$giftDebug = [
    'selected_item_id' => $selectedItemId,
    'branch_locality' => $branchLocality,
    'filter_by_locality' => gift_cards_filter_by_branch_locality_enabled(),
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_redeemed'])) {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $markRedeemedFlash = ['type' => 'error', 'text' => 'Invalid session. Please refresh and try again.'];
    } else {
        $markItemId = isset($_POST['mark_item_id']) ? (int) $_POST['mark_item_id'] : 0;
        $redeemedLocation = trim((string) ($_POST['redeemed_location'] ?? ''));
        if ((function_exists('mb_strlen') ? mb_strlen($redeemedLocation) : strlen($redeemedLocation)) > 255) {
            $redeemedLocation = substr($redeemedLocation, 0, 255);
        }
        if ($markItemId <= 0) {
            $markRedeemedFlash = ['type' => 'error', 'text' => 'Invalid gift selected.'];
        } else {
            try {
                $pdoMark = wp_db();
                $wpPrefixMark = wp_table_prefix();
                $orderLookupSql = "SELECT oi.order_id
                    FROM wp_woocommerce_order_items oi
                    WHERE oi.order_item_id = :item_id
                      AND oi.order_item_type = 'line_item'
                      AND EXISTS (
                        SELECT 1
                        FROM wp_woocommerce_order_itemmeta oim
                        WHERE oim.order_item_id = oi.order_item_id
                          AND oim.meta_key = '_ywgc_gift_card_code'
                      )
                    LIMIT 1";
                $orderLookupSql = str_replace('wp_', $wpPrefixMark, $orderLookupSql);
                $lookupStmt = $pdoMark->prepare($orderLookupSql);
                $lookupStmt->execute(['item_id' => $markItemId]);
                $orderIdToMark = (int) ($lookupStmt->fetchColumn() ?: 0);
                if ($orderIdToMark <= 0) {
                    $markRedeemedFlash = ['type' => 'error', 'text' => 'Gift order not found.'];
                } else {
                    $updateSql = "UPDATE wp_posts
                        SET post_status = 'wc-completed'
                        WHERE ID = :order_id";
                    $updateSql = str_replace('wp_', $wpPrefixMark, $updateSql);
                    $updateStmt = $pdoMark->prepare($updateSql);
                    $updateStmt->execute(['order_id' => $orderIdToMark]);

                    $updateBalanceSql = "UPDATE wp_postmeta
                        SET meta_value = '0'
                        WHERE post_id IN (
                            SELECT post_id
                            FROM wp_postmeta
                            WHERE meta_key = '_ywgc_order_id'
                              AND meta_value = :order_id_meta
                        )
                          AND meta_key = '_ywgc_balance_total'";
                    $updateBalanceSql = str_replace('wp_', $wpPrefixMark, $updateBalanceSql);
                    $updateBalanceStmt = $pdoMark->prepare($updateBalanceSql);
                    $updateBalanceStmt->execute(['order_id_meta' => (string) $orderIdToMark]);

                    if ($redeemedLocation !== '') {
                        $currLocSql = "SELECT meta_value
                            FROM wp_postmeta
                            WHERE post_id = :order_id
                              AND meta_key IN ('billing_location', '_billing_location')
                            ORDER BY CASE meta_key WHEN 'billing_location' THEN 0 ELSE 1 END
                            LIMIT 1";
                        $currLocSql = str_replace('wp_', $wpPrefixMark, $currLocSql);
                        $currLocStmt = $pdoMark->prepare($currLocSql);
                        $currLocStmt->execute(['order_id' => $orderIdToMark]);
                        $currentLoc = trim((string) ($currLocStmt->fetchColumn() ?: ''));

                        if (strcasecmp($currentLoc, $redeemedLocation) !== 0) {
                            $updateLocSql = "UPDATE wp_postmeta
                                SET meta_value = :redeemed_location
                                WHERE post_id = :order_id
                                  AND meta_key IN ('_billing_location', 'billing_location')";
                            $updateLocSql = str_replace('wp_', $wpPrefixMark, $updateLocSql);
                            $updateLocStmt = $pdoMark->prepare($updateLocSql);
                            $updateLocStmt->execute([
                                'redeemed_location' => $redeemedLocation,
                                'order_id' => $orderIdToMark,
                            ]);
                        }
                    }

                    $markRedeemedFlash = ['type' => 'ok', 'text' => 'Gift code marked as Redeemed.'];
                    $selectedItemId = $markItemId;
                }
            } catch (PDOException $e) {
                error_log('AllureOne gift_codes mark redeemed failed: ' . $e->getMessage());
                $markRedeemedFlash = ['type' => 'error', 'text' => 'Could not mark gift as Redeemed.'];
            }
        }
    }
}
try {
    $pdo = wp_db();
    $wpPrefix = wp_table_prefix();
    $giftDebug['wp_prefix'] = $wpPrefix;
    $cfg = require __DIR__ . '/config.php';
    $giftDebug['wp_db_host'] = (string) (($cfg['wordpress_db']['host'] ?? ''));
    $giftDebug['wp_db_name'] = (string) (($cfg['wordpress_db']['database'] ?? ''));
    $baseFromWhere = "FROM wp_woocommerce_order_items oi
        JOIN wp_woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
        JOIN wp_posts p ON p.ID = oi.order_id
        LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id
        WHERE oi.order_item_type = 'line_item'
          AND oi.order_item_id IN (
              SELECT order_item_id
              FROM wp_woocommerce_order_itemmeta
              WHERE meta_key = '_ywgc_gift_card_code'
          )";
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
        " . $baseFromWhere;
    $baseFromWhere = str_replace('wp_', $wpPrefix, $baseFromWhere);
    $baseSelect = str_replace('wp_', $wpPrefix, $baseSelect);

    $baseParams = [];
    if ($branchLocality !== '' && gift_cards_filter_by_branch_locality_enabled()) {
        $baseFromWhere .= "
          AND EXISTS (
              SELECT 1
              FROM wp_postmeta pmf
              WHERE pmf.post_id = oi.order_id
                AND pmf.meta_key = 'billing_location'
                AND LOWER(TRIM(pmf.meta_value)) = LOWER(TRIM(:branch_locality))
          )";
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
    $baseFromWhere = str_replace('wp_', $wpPrefix, $baseFromWhere);
    $giftDebug['base_params'] = $baseParams;

    if ($selectedItemId > 0) {
        $detailSql = $baseSelect . "
          AND oi.order_item_id = :item_id
        GROUP BY oi.order_item_id, oi.order_id, p.post_date, p.post_status
        ORDER BY p.post_date DESC
        LIMIT 1";
        $stmt = $pdo->prepare($detailSql);
        $detailParams = $baseParams;
        $detailParams['item_id'] = $selectedItemId;
        $giftDebug['mode'] = 'detail';
        $giftDebug['sql'] = $detailSql;
        $giftDebug['params'] = $detailParams;
        $stmt->execute($detailParams);
        $giftDetail = $stmt->fetch() ?: null;
        $giftDebug['detail_found'] = $giftDetail !== null;
        if ($giftDetail !== null) {
            $resolvedRecipientEmail = '';
            $orderId = (int) ($giftDetail['order_id'] ?? 0);
            if ($orderId > 0) {
                $emailSql = "SELECT
                        pm.post_id,
                        pm.meta_value AS recipient_email
                     FROM wp_postmeta pm
                     WHERE pm.meta_key = '_ywgc_recipient'
                       AND pm.post_id = :order_id
                     LIMIT 5";
                $emailSql = str_replace('wp_', $wpPrefix, $emailSql);
                $emailStmt = $pdo->prepare($emailSql);
                $emailStmt->execute(['order_id' => $orderId]);
                $emailRows = $emailStmt->fetchAll();
                foreach ($emailRows as $er) {
                    $candidate = extract_email_value((string) ($er['recipient_email'] ?? ''));
                    if ($candidate !== '') {
                        $resolvedRecipientEmail = $candidate;
                        break;
                    }
                }
            }

            if ($resolvedRecipientEmail === '') {
                $giftCode = extract_gift_code((string) ($giftDetail['gift_card_code'] ?? ''));
                if ($giftCode !== '') {
                    $emailByCodeSql = "SELECT pm.meta_value AS recipient_email
                         FROM wp_postmeta pm
                         JOIN wp_posts gp ON gp.ID = pm.post_id
                         WHERE pm.meta_key = '_ywgc_recipient'
                           AND gp.post_title = :gift_code
                         LIMIT 1";
                    $emailByCodeSql = str_replace('wp_', $wpPrefix, $emailByCodeSql);
                    $emailByCodeStmt = $pdo->prepare($emailByCodeSql);
                    $emailByCodeStmt->execute(['gift_code' => $giftCode]);
                    $emailByCode = $emailByCodeStmt->fetchColumn();
                    $resolvedRecipientEmail = extract_email_value((string) ($emailByCode ?: ''));
                }
            }

            if ($resolvedRecipientEmail !== '') {
                $giftDetail['recipient_email'] = $resolvedRecipientEmail;
            }
        }
    } else {
        $countSql = "SELECT COUNT(*)
            FROM (
                SELECT oi.order_item_id
                " . $baseFromWhere . "
                GROUP BY oi.order_item_id
            ) t";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($baseParams);
        $listTotal = (int) ($countStmt->fetchColumn() ?: 0);
        $listTotalPages = max(1, (int) ceil($listTotal / $listPerPage));
        $listPage = min($listPage, $listTotalPages);
        $offset = ($listPage - 1) * $listPerPage;

        $listSql = $baseSelect . "
            GROUP BY oi.order_item_id, oi.order_id, p.post_date, p.post_status
            ORDER BY p.post_date DESC
            LIMIT " . $listPerPage . " OFFSET " . $offset;
        $stmt = $pdo->prepare($listSql);
        $giftDebug['mode'] = 'list';
        $giftDebug['sql'] = $listSql;
        $giftDebug['params'] = $baseParams;
        $giftDebug['list_total'] = $listTotal;
        $giftDebug['list_page'] = $listPage;
        $giftDebug['list_total_pages'] = $listTotalPages;
        $stmt->execute($baseParams);
        $giftRows = $stmt->fetchAll();
        $giftDebug['row_count'] = count($giftRows);
    }
} catch (PDOException $e) {
    error_log('AllureOne gift_codes WP DB: ' . $e->getMessage());
    $giftDebug['error'] = [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
}

$pageTitle = 'Gift Card Sale';
$activeNav = 'gift_codes';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="card">
    <div class="card__head">
        <span>Recent gift codes</span>
    </div>
    <div class="card__body">
        <?php if ($selectedItemId > 0): ?>
            <?php if (is_array($markRedeemedFlash)): ?>
                <p class="alert alert--<?= ($markRedeemedFlash['type'] ?? '') === 'ok' ? 'ok' : 'error' ?>" style="margin:1rem 1.25rem 0"><?= e((string) ($markRedeemedFlash['text'] ?? '')) ?></p>
            <?php endif; ?>
            <?php if ($giftDetail === null): ?>
                <p class="empty">Gift details not found.</p>
                <p style="padding:0 1.25rem 1.25rem;margin:0"><a class="btn btn--ghost" href="gift_codes.php">Back</a></p>
            <?php else: ?>
                <div style="padding:1.25rem">
                    <?php $detailOrderStatus = strtolower(trim((string) ($giftDetail['order_status'] ?? ''))); ?>
                    <div style="margin-top:0;margin-bottom:0.75rem;display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap">
                        <?php if ($detailOrderStatus !== 'completed'): ?>
                            <form id="mark_redeemed_form" method="post" action="gift_codes.php?gift=<?= (int) ($giftDetail['order_item_id'] ?? 0) ?>" style="margin:0">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="mark_item_id" value="<?= (int) ($giftDetail['order_item_id'] ?? 0) ?>">
                                <button type="submit" class="btn btn--primary" name="mark_redeemed" value="1" onclick="return confirm('Mark this gift code as Redeemed?');">Mark Redeemed</button>
                            </form>
                        <?php endif; ?>
                        <a class="btn btn--ghost" href="gift_codes.php">Back</a>
                    </div>
                    <table class="data">
                        <tbody>
                            <tr><th>Order ID</th><td><?= (int) ($giftDetail['order_id'] ?? 0) ?></td></tr>
                            <tr><th>Gift Code</th><td><?= e(extract_gift_code((string) ($giftDetail['gift_card_code'] ?? ''))) ?></td></tr>
                            <tr><th>Recipient Name</th><td><?= e((string) ($giftDetail['recipient_name'] ?? '')) ?></td></tr>
                            <tr><th>Recipient Email</th><td><?= e((string) ($giftDetail['recipient_email'] ?? '')) ?></td></tr>
                            <tr><th>Message</th><td><?= e((string) ($giftDetail['message'] ?? '')) ?></td></tr>
                            <tr><th>Buyer Name</th><td><?= e((string) ($giftDetail['sender_name'] ?? '')) ?></td></tr>
                            <tr><th>Buyer Phone</th><td><?= e((string) ($giftDetail['buyer_phone'] ?? '')) ?></td></tr>
                            <tr><th>Location</th><td><?= e((string) ($giftDetail['location'] ?? '')) ?></td></tr>
                            <tr>
                                <th>Order Status</th>
                                <td>
                                    <?php if ($detailOrderStatus === 'completed'): ?>
                                        <span class="gift-order-status--redeemed">Redeemed</span>
                                    <?php else: ?>
                                        <?= e((string) ($giftDetail['order_status'] ?? '—')) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr><th>Razorpay Transaction</th><td>
                                <?php $rzpTxn = trim((string) ($giftDetail['transaction_id'] ?? '')); ?>
                                <?= e($rzpTxn) ?>
                                <?php if ($rzpTxn !== ''): ?>
                                    <button type="button" class="btn btn--ghost js-rzp-check-status"
                                            data-payment-id="<?= e($rzpTxn) ?>"
                                            style="margin-left:0.5rem;padding:0.35rem 0.7rem">Check Status</button>
                                <?php endif; ?>
                            </td></tr>
                            <tr><th>Amount</th><td><?= e(format_amount($giftDetail['amount'] ?? null)) ?></td></tr>
                            <tr><th>Order Date</th><td><?= e(format_purchase_date($giftDetail['post_date'] ?? null)) ?></td></tr>
                            <tr>
                                <th>Redeemed Location</th>
                                <td>
                                    <?php $selectedRedeemedLoc = trim((string) ($giftDetail['location'] ?? '')); ?>
                                    <select name="redeemed_location" id="redeemed_location" form="mark_redeemed_form" style="min-width:16rem;max-width:100%"<?= $detailOrderStatus === 'completed' ? ' disabled' : '' ?>>
                                        <option value="">Select location</option>
                                        <?php foreach ($redeemedLocationOptions as $rlOpt): ?>
                                            <?php $isSel = (strcasecmp($selectedRedeemedLoc, $rlOpt) === 0); ?>
                                            <option value="<?= e((string) $rlOpt) ?>"<?= $isSel ? ' selected' : '' ?>><?= e((string) $rlOpt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p style="margin-top:0.75rem;margin-bottom:0"><a class="btn btn--ghost" href="gift_codes.php">Back</a></p>
                </div>
            <?php endif; ?>
        <?php elseif (count($giftRows) === 0): ?>
            <p class="empty">No gift cards yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data data--gift-codes">
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
                            $isCompletedOrder = strtolower(trim((string) ($gr['order_status'] ?? ''))) === 'completed';
                            ?>
                            <tr>
                                <td>
                                    <a class="link--underlined" href="gift_codes.php?gift=<?= $itemId ?>"><?= $codeDisplay ?></a>
                                    <?php if ($isCompletedOrder): ?>
                                        <span class="gift-code-completed-check" title="Completed" aria-label="Completed">✓</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="link--underlined" href="gift_codes.php?gift=<?= $itemId ?>"><?= e((string) ($gr['location'] ?? '')) ?></a>
                                </td>
                                <td><?= e((string) ($gr['sender_name'] ?? '')) ?></td>
                                <td class="<?= $isCompletedOrder ? 'gift-code-amount--completed' : '' ?>"><?= e(format_amount($gr['amount'] ?? null)) ?></td>
                                <td><?= e(format_purchase_date($gr['post_date'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($listTotal > $listPerPage): ?>
                <nav class="leads-pagination" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem 0.85rem;padding:1rem 1.25rem 1.15rem;margin:0;justify-content:center">
                    <?php if ($listPage > 1): ?>
                        <a class="btn btn--ghost" href="gift_codes.php?<?= e(http_build_query(['page' => $listPage - 1])) ?>">Previous</a>
                    <?php endif; ?>
                    <span style="font-size:.9rem;color:var(--muted, #64748b)">Page <?= (int) $listPage ?> of <?= (int) $listTotalPages ?></span>
                    <?php if ($listPage < $listTotalPages): ?>
                        <a class="btn btn--ghost" href="gift_codes.php?<?= e(http_build_query(['page' => $listPage + 1])) ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div id="rzp-status-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:2000;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;border-radius:10px;max-width:640px;width:100%;max-height:80vh;overflow:auto;border:1px solid #d6dde6">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:0.8rem 1rem;border-bottom:1px solid #d6dde6">
            <strong>Razorpay Payment Status</strong>
            <button type="button" id="rzp-status-close" class="btn btn--ghost" style="padding:0.3rem 0.65rem">Close</button>
        </div>
        <div id="rzp-status-content" style="padding:1rem"></div>
    </div>
</div>

<script>
(function () {
    var csrf = <?= json_encode(csrf_token()) ?>;
    var rzpModal = document.getElementById('rzp-status-modal');
    var rzpClose = document.getElementById('rzp-status-close');
    var rzpContent = document.getElementById('rzp-status-content');

    function showRzpModal(html) {
        if (!rzpModal || !rzpContent) return;
        rzpContent.innerHTML = html;
        rzpModal.style.display = 'flex';
    }

    function hideRzpModal() {
        if (!rzpModal || !rzpContent) return;
        rzpModal.style.display = 'none';
        rzpContent.innerHTML = '';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s || '');
        return d.innerHTML;
    }

    if (rzpClose) {
        rzpClose.addEventListener('click', hideRzpModal);
    }
    if (rzpModal) {
        rzpModal.addEventListener('click', function (ev) {
            if (ev.target === rzpModal) hideRzpModal();
        });
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        if (!(t && t.classList && t.classList.contains('js-rzp-check-status'))) {
            return;
        }
        var paymentId = String(t.getAttribute('data-payment-id') || '').trim();
        if (!paymentId) return;
        t.disabled = true;
        var oldText = t.textContent;
        t.textContent = 'Checking...';

        fetch('razorpay_payment_status_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ payment_id: paymentId, _csrf: csrf })
        })
            .then(function (r) {
                return r.text().then(function (text) {
                    var j = null;
                    try { j = JSON.parse(text); } catch (e) {}
                    return { j: j, raw: text };
                });
            })
            .then(function (j) {
                if (!j || !j.j || j.j.ok !== true || !j.j.data) {
                    var em = (j && j.j && j.j.error) ? j.j.error : '';
                    if (!em && j && j.raw) {
                        em = String(j.raw).trim().slice(0, 300);
                    }
                    if (!em) em = 'Could not fetch Razorpay status.';
                    showRzpModal('<p class="alert alert--error" style="margin:0">' + esc(em) + '</p>');
                    return;
                }
                var d = j.j.data;
                var statusHtml = esc(d.payment_status);
                if (String(d.payment_status || '').toLowerCase() === 'captured') {
                    statusHtml = '<span style="color:#166534;font-weight:600">Successful (captured)</span>';
                } else if (d.has_error) {
                    statusHtml = '<span style="color:#b91c1c;font-weight:600">' + esc(d.payment_status || 'error') + '</span>';
                }
                var rows =
                    '<table class="data"><tbody>' +
                    '<tr><th>Payment Id</th><td>' + esc(d.payment_id) + '</td></tr>' +
                    '<tr><th>Order Id</th><td>' + esc(d.order_id) + '</td></tr>' +
                    '<tr><th>Payment Status</th><td>' + statusHtml + '</td></tr>' +
                    '<tr><th>Amount</th><td>' + esc(d.amount) + '</td></tr>' +
                    '<tr><th>Contact</th><td>' + esc(d.contact) + '</td></tr>' +
                    '<tr><th>Email</th><td>' + esc(d.email) + '</td></tr>' +
                    '<tr><th>Payment Method</th><td>' + esc(d.payment_method) + '</td></tr>';
                if (d.has_error) {
                    rows +=
                        '<tr><th>Error</th><td>' + esc(d.error_description) + '</td></tr>' +
                        '<tr><th>Error Reason</th><td>' + esc(d.error_reason) + '</td></tr>' +
                        '<tr><th>Error Step</th><td>' + esc(d.error_step) + '</td></tr>';
                }
                rows += '</tbody></table>';
                showRzpModal(rows);
            })
            .catch(function () {
                showRzpModal('<p class="alert alert--error" style="margin:0">Network error while checking payment status.</p>');
            })
            .finally(function () {
                t.disabled = false;
                t.textContent = oldText;
            });
    });
})();
</script>
<script>
var giftCardSaleDebug = <?= json_encode($giftDebug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
console.log('Gift Card Sale debug', giftCardSaleDebug);
if (giftCardSaleDebug && giftCardSaleDebug.error) {
    console.error('Gift Card Sale error details:', giftCardSaleDebug.error);
}
try {
    console.log('Gift Card Sale debug JSON:', JSON.stringify(giftCardSaleDebug));
} catch (e) {}
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
