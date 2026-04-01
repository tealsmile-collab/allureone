<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/gift_helpers.php';
require_login();

$invoiceCancelFlash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invoice_cancel'])) {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $invoiceCancelFlash = ['type' => 'error', 'text' => 'Invalid session. Please refresh and try again.'];
    } else {
        $cancelBillId = isset($_POST['bill_id']) ? (int) $_POST['bill_id'] : 0;
        if ($cancelBillId <= 0) {
            $invoiceCancelFlash = ['type' => 'error', 'text' => 'Invalid invoice.'];
        } else {
            $cancelResp = dingg_request_bill_cancellation($cancelBillId);
            if (($cancelResp['error'] ?? '') === 'not_configured') {
                $invoiceCancelFlash = ['type' => 'error', 'text' => 'Cancellation API URL is not configured. Set dingg.cancel_bill_url in config (POST with JSON body { "id": bill id }).'];
            } elseif (($cancelResp['error'] ?? '') === 'no_token') {
                $invoiceCancelFlash = ['type' => 'error', 'text' => 'Dingg could not be reached (no token). Try logging out and in again.'];
            } elseif (($cancelResp['ok'] ?? false)) {
                $ch = (int) ($cancelResp['http'] ?? 0);
                $cb = (string) ($cancelResp['body'] ?? '');
                $cj = json_decode($cb, true);
                if ($ch >= 200 && $ch < 300) {
                    $msg = 'Cancellation request submitted.';
                    if (is_array($cj) && isset($cj['message']) && (string) $cj['message'] !== '') {
                        $msg = (string) $cj['message'];
                    }
                    $invoiceCancelFlash = ['type' => 'ok', 'text' => $msg];
                } else {
                    $invoiceCancelFlash = ['type' => 'error', 'text' => 'Request failed (HTTP ' . $ch . ').'];
                }
            }
        }
    }
}

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
        error_log('AllureOne dashboard branch lookup: ' . $e->getMessage());
    }
}

$selectedItemId = isset($_GET['gift']) ? (int) $_GET['gift'] : 0;
$invoiceNoRaw = isset($_GET['invoice_no']) ? (string) $_GET['invoice_no'] : '';
$invoiceNoInput = sanitize_invoice_search_term($invoiceNoRaw);
$invoiceSearchSubmitted = isset($_GET['invoice_search']) && $_GET['invoice_search'] === '1';
$invoiceApiResult = null;
if ($invoiceSearchSubmitted && $invoiceNoInput !== '') {
    $invoiceApiResult = dingg_fetch_vendor_bills($invoiceNoInput);
}

$invBill = null;
$invErrMsg = null;
$invDataCount = 0;
$invHttp = 0;
if ($invoiceApiResult !== null && ($invoiceApiResult['ok'] ?? false)) {
    $invHttp = (int) ($invoiceApiResult['http'] ?? 0);
    $invBodyRaw = (string) ($invoiceApiResult['body'] ?? '');
    $invBodyRaw = preg_replace('/^\xEF\xBB\xBF/', '', $invBodyRaw);
    $invBodyTrimmed = trim($invBodyRaw);

    if ($invHttp < 200 || $invHttp >= 300) {
        $invErrMsg = 'API returned HTTP ' . $invHttp . '.';
        $tryErr = json_decode($invBodyTrimmed, true);
        if (is_array($tryErr)) {
            if (isset($tryErr['message']) && (string) $tryErr['message'] !== '') {
                $invErrMsg .= ' ' . (string) $tryErr['message'];
            }
        } elseif ($invBodyTrimmed !== '') {
            $invErrMsg .= ' ' . (strlen($invBodyTrimmed) > 150 ? substr($invBodyTrimmed, 0, 150) . '…' : $invBodyTrimmed);
        }
    } else {
        $invJsonDecoded = json_decode($invBodyTrimmed, true);
        if (!is_array($invJsonDecoded)) {
            if ($invBodyTrimmed === '') {
                $invErrMsg = 'Empty response from Dingg bills API (HTTP ' . $invHttp . ').';
            } else {
                $jsonErr = json_last_error_msg();
                $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($invBodyTrimmed)));
                if (strlen($snippet) > 180) {
                    $snippet = substr($snippet, 0, 180) . '…';
                }
                $invErrMsg = 'Response was not valid JSON (' . $jsonErr . ').';
                if ($snippet !== '') {
                    $invErrMsg .= ' ' . $snippet;
                }
            }
        } elseif (!dingg_bills_response_is_success($invJsonDecoded)) {
            $em = dingg_bills_api_error_message($invJsonDecoded);
            $invErrMsg = $em !== '' ? $em : 'Search failed.';
        } else {
            $invRows = dingg_bills_api_data_rows($invJsonDecoded);
            if ($invRows === null || $invRows === [] || count($invRows) === 0) {
                $invErrMsg = 'No invoice found for this search.';
            } else {
                $invDataCount = count($invRows);
                $invBill = $invRows[0];
            }
        }
    }
}

$giftRows = [];
$giftDetail = null;
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

    if ($selectedItemId > 0) {
        $detailSql = $baseSelect . "
          AND oi.order_item_id = :item_id
        GROUP BY oi.order_item_id, oi.order_id, p.post_date, p.post_status
        ORDER BY p.post_date DESC
        LIMIT 1";
        $stmt = $pdo->prepare($detailSql);
        $detailParams = $baseParams;
        $detailParams['item_id'] = $selectedItemId;
        $stmt->execute($detailParams);
        $giftDetail = $stmt->fetch() ?: null;
        if ($giftDetail !== null) {
            $resolvedRecipientEmail = '';
            $orderId = (int) ($giftDetail['order_id'] ?? 0);
            if ($orderId > 0) {
            $emailStmt = $pdo->prepare(
                "SELECT
                    pm.post_id,
                    pm.meta_value AS recipient_email
                 FROM wp_postmeta pm
                 WHERE pm.meta_key = '_ywgc_recipient'
                   AND pm.post_id = :order_id
                 LIMIT 5"
            );
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

            // Fallback: some setups store _ywgc_recipient on gift-card post (post_title = code)
            if ($resolvedRecipientEmail === '') {
                $giftCode = extract_gift_code((string) ($giftDetail['gift_card_code'] ?? ''));
                if ($giftCode !== '') {
                    $emailByCodeStmt = $pdo->prepare(
                        "SELECT pm.meta_value AS recipient_email
                         FROM wp_postmeta pm
                         JOIN wp_posts gp ON gp.ID = pm.post_id
                         WHERE pm.meta_key = '_ywgc_recipient'
                           AND gp.post_title = :gift_code
                         LIMIT 1"
                    );
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
        $listSql = $baseSelect . "
        GROUP BY oi.order_item_id, oi.order_id, p.post_date, p.post_status
        ORDER BY p.post_date DESC
        LIMIT 5";
        $stmt = $pdo->prepare($listSql);
        $stmt->execute($baseParams);
        $giftRows = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    error_log('AllureOne dashboard WP DB: ' . $e->getMessage());
}

$invoice_input_pattern = '[^\'"%&()#:<>?\\[\\]]+';

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/layout_start.php';
?>

<details class="card" open>
    <summary class="card__head card__toggle">
        <span class="card__toggle-inner">
            <span>Invoice cancellation request</span>
            <span class="card__chevron" aria-hidden="true">▼</span>
        </span>
    </summary>
    <div class="card__body" style="padding:1.25rem">
        <?php if ($invoiceCancelFlash !== null): ?>
            <p class="alert alert--<?= ($invoiceCancelFlash['type'] ?? '') === 'ok' ? 'ok' : 'error' ?>" style="margin-top:0;margin-bottom:1rem"><?= e((string) ($invoiceCancelFlash['text'] ?? '')) ?></p>
        <?php endif; ?>
        <form class="form form--invoice-search" method="get" action="dashboard.php">
            <div class="form__row">
                <label for="invoice_no">Invoice number</label>
                <input id="invoice_no" name="invoice_no" type="text" maxlength="64" autocomplete="off"
                       placeholder="Enter invoice number"
                       title="Not allowed: quotes, %, &amp;, ( ), #, :, &lt; &gt;, ?, [ ]"
                       pattern="<?= e($invoice_input_pattern) ?>"
                       value="<?= e($invoiceNoInput) ?>">
            </div>
            <div class="form__row form__row--submit">
                <button class="btn btn--primary" type="submit" name="invoice_search" value="1">Search</button>
            </div>
        </form>
        <?php if ($invoiceSearchSubmitted): ?>
            <?php if ($invoiceNoInput === ''): ?>
                <p class="alert alert--error" style="margin-top:1rem;margin-bottom:0">Please enter an invoice number.</p>
            <?php elseif ($invoiceApiResult !== null && !($invoiceApiResult['ok'] ?? false)): ?>
                <?php if (($invoiceApiResult['error'] ?? '') === 'no_token'): ?>
                    <p class="alert alert--error" style="margin-top:1rem;margin-bottom:0">Dingg could not be reached (no token). Check config (mobile, password), PHP error log, and network to api.dingg.app.</p>
                    <?php
                    $invTokDetail = trim((string) ($invoiceApiResult['error_detail'] ?? ''));
                    ?>
                    <?php if ($invTokDetail !== ''): ?>
                        <p class="main__meta" style="margin-top:0.5rem;margin-bottom:0;font-size:0.85rem"><?= e($invTokDetail) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="alert alert--error" style="margin-top:1rem;margin-bottom:0">Could not search invoices.</p>
                <?php endif; ?>
            <?php elseif ($invBill !== null && is_array($invBill)): ?>
                <?php
                $billId = (int) ($invBill['id'] ?? 0);
                $billNo = (string) ($invBill['bill_number'] ?? '');
                $invDate = dingg_format_invoice_date(isset($invBill['selected_date']) ? (string) $invBill['selected_date'] : null);
                $clientName = dingg_format_invoice_client_name($invBill);
                $statusLabel = dingg_format_invoice_status_label($invBill);
                $invLocationMap = dingg_fetch_vendor_business_location_map();
                $invBranchLabel = dingg_invoice_bill_branch_display($invBill, $invLocationMap);
                ?>
                <div class="invoice-detail" style="margin-top:1.25rem">
                    <?php if ($invDataCount > 1): ?>
                        <p class="main__meta" style="margin:0 0 0.75rem">Showing first of <?= $invDataCount ?> matches.</p>
                    <?php endif; ?>
                    <table class="data">
                        <tbody>
                            <tr><th>Invoice number</th><td><?= e($billNo) ?></td></tr>
                            <tr><th>Invoice date</th><td><?= e($invDate) ?></td></tr>
                            <tr><th>Client name</th><td><?= e($clientName) ?></td></tr>
                            <tr><th>Branch</th><td><?= e($invBranchLabel !== '' ? $invBranchLabel : '—') ?></td></tr>
                            <tr><th>Total</th><td><?= e(format_amount($invBill['total'] ?? null)) ?></td></tr>
                            <tr><th>Status</th><td><?= e($statusLabel) ?></td></tr>
                            <tr><th>Bill ID</th><td><?= $billId ?></td></tr>
                        </tbody>
                    </table>
                    <div class="invoice-detail__actions" style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center">
                        <a class="btn btn--ghost" href="dashboard.php">Back</a>
                        <?php if ($billId > 0): ?>
                            <form method="post" action="dashboard.php" class="invoice-cancel-form" style="display:inline"
                                  onsubmit="return confirm('Raise cancellation request for this invoice?');">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="invoice_cancel" value="1">
                                <input type="hidden" name="bill_id" value="<?= $billId ?>">
                                <button type="submit" class="btn btn--danger">Cancel</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($invErrMsg !== null): ?>
                <p class="alert alert--error" style="margin-top:1rem;margin-bottom:0"><?= e($invErrMsg) ?></p>
                <p style="margin-top:0.75rem;margin-bottom:0"><a class="btn btn--ghost" href="dashboard.php">Back</a></p>
            <?php else: ?>
                <div class="invoice-api-out" style="margin-top:1rem">
                    <p class="main__meta" style="margin:0 0 0.5rem">HTTP <?= (int) ($invoiceApiResult['http'] ?? 0) ?> · term <?= e($invoiceNoInput) ?></p>
                    <pre class="invoice-api-json"><?= e((string) ($invoiceApiResult['body'] ?? '')) ?></pre>
                </div>
                <p style="margin-top:0.75rem;margin-bottom:0"><a class="btn btn--ghost" href="dashboard.php">Back</a></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</details>

<details class="card card--spaced-top" open>
    <summary class="card__head card__toggle">
        <span class="card__toggle-inner">
            <span>Gift cards</span>
            <span class="card__chevron" aria-hidden="true">▼</span>
        </span>
    </summary>
    <div class="card__body">
        <?php if ($selectedItemId > 0): ?>
            <?php if ($giftDetail === null): ?>
                <p class="empty">Gift details not found.</p>
                <p style="padding:0 1.25rem 1.25rem;margin:0"><a class="btn btn--ghost" href="dashboard.php">Back</a></p>
            <?php else: ?>
                <div style="padding:1.25rem">
                    <p style="margin-top:0"><a class="btn btn--ghost" href="dashboard.php">Back</a></p>
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
                            <tr><th>Razorpay Transaction</th><td><?= e((string) ($giftDetail['transaction_id'] ?? '')) ?></td></tr>
                            <tr><th>Amount</th><td><?= e(format_amount($giftDetail['amount'] ?? null)) ?></td></tr>
                            <tr><th>Order Date</th><td><?= e(format_purchase_date($giftDetail['post_date'] ?? null)) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php elseif (count($giftRows) === 0): ?>
            <p class="empty">No gift cards yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Location</th>
                            <th>Buyer name</th>
                            <th>Amount</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($giftRows as $gr): ?>
                            <tr>
                                <td>
                                    <a class="link--underlined" href="dashboard.php?gift=<?= (int) ($gr['order_item_id'] ?? 0) ?>">
                                        <?= e((string) ($gr['location'] ?? '')) ?>
                                    </a>
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
</details>

<script>
(function () {
    var el = document.getElementById('invoice_no');
    if (!el) return;
    el.addEventListener('input', function () {
        this.value = this.value.replace(/['"%&()#:<>?\[\]]/g, '');
    });
})();

</script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
