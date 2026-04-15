<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/app_client.php';
require_once __DIR__ . '/includes/gift_helpers.php';
require_login();

/**
 * Save cancellation request metadata for admin review.
 *
 * @param array<string, mixed> $requestData
 */
function allureone_save_invoice_cancellation_request(array $requestData): bool
{
    $sql = 'INSERT INTO allurepro_InvoiceCancellation (
        `Invoice Number`,
        `Invoice ID`,
        `Branch Name`,
        `Branch ID`,
        `Invoice Date`,
        `Client Name`,
        `Invoice Amount`,
        `Invoice Status`,
        `CancellationRemark`,
        `AdminRemark`,
        `AdminID`,
        `AdminName`,
        `RequestUserID`,
        `RequestUserName`,
        `CancellationRequestDate`,
        `CancelledDate`,
        `CancellationStatus`
    ) VALUES (
        :invoice_number,
        :invoice_id,
        :branch_name,
        :branch_id,
        :invoice_date,
        :client_name,
        :invoice_amount,
        :invoice_status,
        :cancellation_remark,
        :admin_remark,
        :admin_id,
        :admin_name,
        :request_user_id,
        :request_user_name,
        NOW(),
        NULL,
        0
    )';

    $stmt = db()->prepare($sql);

    return $stmt->execute([
        'invoice_number' => (string) ($requestData['invoice_number'] ?? ''),
        'invoice_id' => (int) ($requestData['invoice_id'] ?? 0),
        'branch_name' => (string) ($requestData['branch_name'] ?? ''),
        'branch_id' => (int) ($requestData['branch_id'] ?? 0),
        'invoice_date' => (string) ($requestData['invoice_date'] ?? ''),
        'client_name' => (string) ($requestData['client_name'] ?? ''),
        'invoice_amount' => (string) ($requestData['invoice_amount'] ?? ''),
        'invoice_status' => (string) ($requestData['invoice_status'] ?? ''),
        'cancellation_remark' => (string) ($requestData['cancellation_remark'] ?? ''),
        'admin_remark' => null,
        'admin_id' => null,
        'admin_name' => null,
        'request_user_id' => (int) ($requestData['request_user_id'] ?? 0),
        'request_user_name' => (string) ($requestData['request_user_name'] ?? ''),
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function allureone_fetch_pending_cancellation_reviews(?int $branchId = null): array
{
    $sql = 'SELECT
        id,
        `Invoice Number` AS invoice_number,
        `Invoice ID` AS invoice_id,
        `Branch Name` AS branch_name,
        `Branch ID` AS branch_id,
        `Invoice Date` AS invoice_date,
        `Client Name` AS client_name,
        `Invoice Amount` AS invoice_amount,
        `Invoice Status` AS invoice_status,
        CancellationRemark AS cancellation_remark,
        AdminRemark AS admin_remark,
        RequestUserID AS request_user_id,
        RequestUserName AS request_user_name,
        CancellationRequestDate AS cancellation_request_date
    FROM allurepro_InvoiceCancellation
    WHERE CancellationStatus = 0';
    $params = [];
    if ($branchId !== null && $branchId > 0) {
        $sql .= ' AND `Branch ID` = :branch_id';
        $params['branch_id'] = $branchId;
    }
    $sql .= ' ORDER BY id DESC';

    $st = db()->prepare($sql);
    $st->execute($params);

    return $st->fetchAll();
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

function allureone_reject_cancellation_request(
    int $requestId,
    string $adminRemark,
    int $adminId,
    string $adminName,
    ?int $branchId = null
): bool {
    $sql = 'UPDATE allurepro_InvoiceCancellation
            SET CancellationStatus = 2,
                AdminRemark = :admin_remark,
                AdminID = :admin_id,
                AdminName = :admin_name,
                CancelledDate = NOW()
            WHERE id = :id
              AND CancellationStatus = 0';
    $params = [
        'admin_remark' => $adminRemark,
        'admin_id' => $adminId,
        'admin_name' => $adminName,
        'id' => $requestId,
    ];
    if ($branchId !== null && $branchId > 0) {
        $sql .= ' AND `Branch ID` = :branch_id';
        $params['branch_id'] = $branchId;
    }

    $st = db()->prepare($sql);
    $st->execute($params);

    return $st->rowCount() > 0;
}

function allureone_mark_cancellation_approved(
    int $requestId,
    string $adminRemark,
    int $adminId,
    string $adminName,
    ?int $branchId = null
): bool {
    $sql = 'UPDATE allurepro_InvoiceCancellation
            SET CancellationStatus = 1,
                AdminRemark = :admin_remark,
                AdminID = :admin_id,
                AdminName = :admin_name,
                CancelledDate = NOW()
            WHERE id = :id
              AND CancellationStatus = 0';
    $params = [
        'admin_remark' => $adminRemark,
        'admin_id' => $adminId,
        'admin_name' => $adminName,
        'id' => $requestId,
    ];
    if ($branchId !== null && $branchId > 0) {
        $sql .= ' AND `Branch ID` = :branch_id';
        $params['branch_id'] = $branchId;
    }

    $st = db()->prepare($sql);
    $st->execute($params);

    return $st->rowCount() > 0;
}

/**
 * @return array<string, mixed>|null
 */
function allureone_fetch_pending_cancellation_review_by_id(int $requestId, ?int $branchId = null): ?array
{
    $sql = 'SELECT
        id,
        `Invoice Number` AS invoice_number,
        `Invoice ID` AS invoice_id,
        `Branch Name` AS branch_name,
        `Branch ID` AS branch_id,
        `Invoice Date` AS invoice_date,
        `Client Name` AS client_name,
        `Invoice Amount` AS invoice_amount,
        `Invoice Status` AS invoice_status,
        CancellationRemark AS cancellation_remark,
        AdminRemark AS admin_remark,
        RequestUserID AS request_user_id,
        RequestUserName AS request_user_name,
        CancellationRequestDate AS cancellation_request_date
    FROM allurepro_InvoiceCancellation
    WHERE id = :id
      AND CancellationStatus = 0';
    $params = ['id' => $requestId];
    if ($branchId !== null && $branchId > 0) {
        $sql .= ' AND `Branch ID` = :branch_id';
        $params['branch_id'] = $branchId;
    }
    $sql .= ' LIMIT 1';

    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();

    return is_array($row) ? $row : null;
}

$invoiceCancelFlash = null;
$invoiceReviewFlash = null;
$user = current_user();
$userRoleId = (int) ($user['role_id'] ?? 0);
$userBranchId = isset($user['branch_id']) && (int) $user['branch_id'] > 0 ? (int) $user['branch_id'] : null;
$selectedReviewId = isset($_GET['review']) ? (int) $_GET['review'] : 0;
$canReviewCancellations = $userRoleId === ROLE_ADMIN || $userRoleId === ROLE_SUPERADMIN;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invoice_review_action']) && $canReviewCancellations) {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $invoiceReviewFlash = ['type' => 'error', 'text' => 'Invalid session. Please refresh and try again.'];
    } else {
        $reviewAction = trim((string) ($_POST['invoice_review_action'] ?? ''));
        $reviewId = isset($_POST['invoice_review_id']) ? (int) $_POST['invoice_review_id'] : 0;
        $adminRemark = trim((string) ($_POST['admin_remark'] ?? ''));
        if ($reviewId <= 0) {
            $invoiceReviewFlash = ['type' => 'error', 'text' => 'Invalid cancellation request.'];
        } elseif ($adminRemark === '') {
            $invoiceReviewFlash = ['type' => 'error', 'text' => 'Please enter Admin Remark.'];
        } elseif ((function_exists('mb_strlen') ? mb_strlen($adminRemark) : strlen($adminRemark)) > 100) {
            $invoiceReviewFlash = ['type' => 'error', 'text' => 'Admin Remark can be maximum 100 characters.'];
        } elseif ($reviewAction === 'reject') {
            try {
                $ok = allureone_reject_cancellation_request(
                    $reviewId,
                    $adminRemark,
                    (int) ($user['id'] ?? 0),
                    trim((string) ($user['full_name'] ?? '')),
                    $userBranchId
                );
                $invoiceReviewFlash = $ok
                    ? ['type' => 'ok', 'text' => 'Cancellation request rejected.']
                    : ['type' => 'error', 'text' => 'Request not found, already reviewed, or not in your branch scope.'];
                if ($ok) {
                    $selectedReviewId = 0;
                } else {
                    $selectedReviewId = $reviewId;
                }
            } catch (PDOException $e) {
                error_log('AllureOne cancellation reject failed: ' . $e->getMessage());
                $invoiceReviewFlash = ['type' => 'error', 'text' => 'Could not process rejection. Please try again.'];
                $selectedReviewId = $reviewId;
            }
        } elseif ($reviewAction === 'approve') {
            try {
                $reviewRow = allureone_fetch_pending_cancellation_review_by_id($reviewId, $userBranchId);
                if ($reviewRow === null) {
                    $invoiceReviewFlash = ['type' => 'error', 'text' => 'Request not found, already reviewed, or not in your branch scope.'];
                    $selectedReviewId = 0;
                } else {
                    $approveResp = dingg_delete_vendor_bill(
                        (int) ($reviewRow['invoice_id'] ?? 0),
                        (string) ($reviewRow['cancellation_remark'] ?? '')
                    );
                    if (!($approveResp['ok'] ?? false)) {
                        $invoiceReviewFlash = ['type' => 'error', 'text' => 'Could not call cancellation API.'];
                        $selectedReviewId = $reviewId;
                    } else {
                        $http = (int) ($approveResp['http'] ?? 0);
                        $body = (string) ($approveResp['body'] ?? '');
                        if (dingg_response_looks_unauthorized($http, $body)) {
                            $invoiceReviewFlash = ['type' => 'error', 'text' => dingg_auth_expired_user_message()];
                            $selectedReviewId = $reviewId;
                        } elseif ($http >= 200 && $http < 300) {
                            $updated = allureone_mark_cancellation_approved(
                                $reviewId,
                                $adminRemark,
                                (int) ($user['id'] ?? 0),
                                trim((string) ($user['full_name'] ?? '')),
                                $userBranchId
                            );
                            if ($updated) {
                                $invoiceReviewFlash = ['type' => 'ok', 'text' => 'Cancellation approved successfully.'];
                                $selectedReviewId = 0;
                            } else {
                                $invoiceReviewFlash = ['type' => 'error', 'text' => 'API succeeded but request row could not be updated.'];
                                $selectedReviewId = $reviewId;
                            }
                        } else {
                            $errMsg = 'Cancellation API failed (HTTP ' . $http . ').';
                            $decoded = json_decode($body, true);
                            if (is_array($decoded) && isset($decoded['message']) && trim((string) $decoded['message']) !== '') {
                                $errMsg .= ' ' . trim((string) $decoded['message']);
                            }
                            $invoiceReviewFlash = ['type' => 'error', 'text' => $errMsg];
                            $selectedReviewId = $reviewId;
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log('AllureOne cancellation approve failed: ' . $e->getMessage());
                $invoiceReviewFlash = ['type' => 'error', 'text' => 'Could not process approval. Please try again.'];
                $selectedReviewId = $reviewId;
            }
        } else {
            $invoiceReviewFlash = ['type' => 'error', 'text' => 'Invalid review action.'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invoice_cancel'])) {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $invoiceCancelFlash = ['type' => 'error', 'text' => 'Invalid session. Please refresh and try again.'];
    } else {
        $cancelBillId = isset($_POST['bill_id']) ? (int) $_POST['bill_id'] : 0;
        if ($cancelBillId <= 0) {
            $invoiceCancelFlash = ['type' => 'error', 'text' => 'Invalid invoice.'];
        } else {
            $cancelBearer = isset($_POST['dingg_bearer']) ? trim((string) $_POST['dingg_bearer']) : '';
            $cancelReason = isset($_POST['cancel_reason']) ? trim((string) $_POST['cancel_reason']) : '';
            if ($cancelReason === '') {
                $invoiceCancelFlash = ['type' => 'error', 'text' => 'Please enter a cancellation reason.'];
            } elseif ((function_exists('mb_strlen') ? mb_strlen($cancelReason) : strlen($cancelReason)) > 100) {
                $invoiceCancelFlash = ['type' => 'error', 'text' => 'Cancellation reason can be maximum 100 characters.'];
                $cancelReason = '';
            }
            if ($invoiceCancelFlash === null) {
                $loggedInUser = current_user();
                try {
                    allureone_save_invoice_cancellation_request([
                        'invoice_number' => trim((string) ($_POST['invoice_number'] ?? '')),
                        'invoice_id' => $cancelBillId,
                        'branch_name' => trim((string) ($_POST['invoice_branch_name'] ?? '')),
                        'branch_id' => (int) ($_POST['invoice_branch_id'] ?? 0),
                        'invoice_date' => trim((string) ($_POST['invoice_date'] ?? '')),
                        'client_name' => trim((string) ($_POST['invoice_client_name'] ?? '')),
                        'invoice_amount' => trim((string) ($_POST['invoice_amount'] ?? '')),
                        'invoice_status' => trim((string) ($_POST['invoice_status'] ?? '')),
                        'cancellation_remark' => $cancelReason,
                        'request_user_id' => (int) (($loggedInUser['id'] ?? 0)),
                        'request_user_name' => trim((string) (($loggedInUser['full_name'] ?? ''))),
                    ]);
                    $invoiceCancelFlash = ['type' => 'ok', 'text' => 'Cancellation request raised and sent to Admin for review.'];
                } catch (PDOException $e) {
                    error_log('AllureOne invoice cancellation save failed: ' . $e->getMessage());
                    $invoiceCancelFlash = ['type' => 'error', 'text' => 'Could not save cancellation request. Please try again.'];
                }
            }
        }
    }
}

$branchLookupId = $userBranchId ?? 0;
$branchLocality = '';
if ($branchLookupId > 0) {
    try {
        $mainPdo = db();
        $bs = $mainPdo->prepare('SELECT locality FROM allureone_branch WHERE id = :id AND isActive = 1 LIMIT 1');
        $bs->execute(['id' => $branchLookupId]);
        $branchRow = $bs->fetch();
        if ($branchRow !== false) {
            $branchLocality = trim((string) ($branchRow['locality'] ?? ''));
        }
    } catch (PDOException $e) {
        error_log('AllureOne dashboard branch lookup: ' . $e->getMessage());
    }
}

$pendingCancellationRows = [];
$selectedReviewRow = null;
if ($canReviewCancellations) {
    try {
        $pendingCancellationRows = allureone_fetch_pending_cancellation_reviews($userBranchId);
        if ($selectedReviewId > 0) {
            foreach ($pendingCancellationRows as $r) {
                if ((int) ($r['id'] ?? 0) === $selectedReviewId) {
                    $selectedReviewRow = $r;
                    break;
                }
            }
        }
    } catch (PDOException $e) {
        error_log('AllureOne cancellation review fetch failed: ' . $e->getMessage());
        if ($invoiceReviewFlash === null) {
            $invoiceReviewFlash = ['type' => 'error', 'text' => 'Could not load cancellation review requests.'];
        }
    }
}

$selectedItemId = isset($_GET['gift']) ? (int) $_GET['gift'] : 0;

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

<?php ob_start(); ?>
<details class="card<?= $canReviewCancellations ? ' card--spaced-top' : '' ?>" open>
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
        <form id="invoice-search-form" class="form form--invoice-search" method="get" action="dashboard.php">
            <div class="form__row">
                <label for="invoice_no">Invoice number</label>
                <input id="invoice_no" name="invoice_no" type="text" maxlength="64" autocomplete="off"
                       placeholder="Enter invoice number"
                       title="Not allowed: quotes, %, &amp;, ( ), #, :, &lt; &gt;, ?, [ ]"
                       pattern="<?= e($invoice_input_pattern) ?>"
                       value="">
            </div>
            <div class="form__row form__row--submit">
                <button class="btn btn--primary" type="submit">Search</button>
            </div>
        </form>
        <p id="invoice-search-status" class="main__meta" style="margin-top:0.75rem;display:none" aria-live="polite"></p>
        <div id="invoice-search-results"></div>
    </div>
</details>
<?php $invoiceCancellationRequestMarkup = (string) ob_get_clean(); ?>

<?php if ($canReviewCancellations): ?>
<details class="card" open>
    <summary class="card__head card__toggle">
        <span class="card__toggle-inner">
            <span>Cancellation Review</span>
            <span class="card__chevron" aria-hidden="true">▼</span>
        </span>
    </summary>
    <div class="card__body" style="padding:1.25rem">
        <?php if ($invoiceReviewFlash !== null): ?>
            <p class="alert alert--<?= ($invoiceReviewFlash['type'] ?? '') === 'ok' ? 'ok' : 'error' ?>" style="margin-top:0;margin-bottom:1rem"><?= e((string) ($invoiceReviewFlash['text'] ?? '')) ?></p>
        <?php endif; ?>
        <?php if (count($pendingCancellationRows) === 0): ?>
            <p class="empty" style="margin:0">No pending cancellation requests.</p>
        <?php else: ?>
            <?php if ($selectedReviewRow === null): ?>
                <div class="table-wrap" id="cancellation-review-list">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Branch name</th>
                                <th>Invoice number</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingCancellationRows as $row): ?>
                                <tr>
                                    <td><?= e((string) ($row['branch_name'] ?? '')) ?></td>
                                    <td>
                                        <a class="link--underlined" href="dashboard.php?review=<?= (int) ($row['id'] ?? 0) ?>#cancellation-review-list">
                                            <?= e((string) ($row['invoice_number'] ?? '')) ?>
                                        </a>
                                    </td>
                                    <td><?= e((string) ($row['invoice_amount'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="invoice-detail" style="margin-top:1rem;padding:1rem;border:1px solid var(--border);border-radius:8px">
                    <table class="data">
                        <tbody>
                            <tr><th>Invoice number</th><td><?= e((string) ($selectedReviewRow['invoice_number'] ?? '')) ?></td></tr>
                            <tr><th>Invoice ID</th><td><?= (int) ($selectedReviewRow['invoice_id'] ?? 0) ?></td></tr>
                            <tr><th>Branch</th><td><?= e((string) ($selectedReviewRow['branch_name'] ?? '')) ?></td></tr>
                            <tr><th>Branch ID</th><td><?= (int) ($selectedReviewRow['branch_id'] ?? 0) ?></td></tr>
                            <tr><th>Invoice date</th><td><?= e((string) ($selectedReviewRow['invoice_date'] ?? '')) ?></td></tr>
                            <tr><th>Client name</th><td><?= e((string) ($selectedReviewRow['client_name'] ?? '')) ?></td></tr>
                            <tr><th>Invoice amount</th><td><?= e((string) ($selectedReviewRow['invoice_amount'] ?? '')) ?></td></tr>
                            <tr><th>Invoice status</th><td><?= e((string) ($selectedReviewRow['invoice_status'] ?? '')) ?></td></tr>
                            <tr><th>Cancellation reason</th><td><?= e((string) ($selectedReviewRow['cancellation_remark'] ?? '')) ?></td></tr>
                            <tr><th>Request user</th><td><?= e((string) ($selectedReviewRow['request_user_name'] ?? '')) ?> (ID: <?= (int) ($selectedReviewRow['request_user_id'] ?? 0) ?>)</td></tr>
                            <tr><th>Request date</th><td><?= e(allureone_format_datetime_ist((string) ($selectedReviewRow['cancellation_request_date'] ?? ''))) ?></td></tr>
                        </tbody>
                    </table>
                    <form method="post" action="dashboard.php" style="margin-top:0.75rem"
                          onsubmit="return confirm(this.dataset.confirmText || 'Are you sure?');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="invoice_review_id" value="<?= (int) ($selectedReviewRow['id'] ?? 0) ?>">
                        <div class="form__row" style="margin-bottom:0.5rem">
                            <label for="admin_remark_<?= (int) ($selectedReviewRow['id'] ?? 0) ?>">Admin Remark <span class="required-mark" aria-hidden="true">*</span></label>
                            <textarea id="admin_remark_<?= (int) ($selectedReviewRow['id'] ?? 0) ?>" name="admin_remark" maxlength="100" rows="3"
                                      required aria-required="true"
                                      placeholder="Enter admin remark (required, max 100 characters)"
                                      style="width:100%;height:80px;box-sizing:border-box;resize:vertical"></textarea>
                        </div>
                        <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap">
                            <button type="submit" name="invoice_review_action" value="approve" class="btn btn--primary"
                                    onclick="this.form.dataset.confirmText = this.getAttribute('data-confirm-text');"
                                    data-confirm-text="Are you sure you want to approve cancellation?">Approve Cancellation</button>
                            <button type="submit" name="invoice_review_action" value="reject" class="btn btn--danger"
                                    onclick="this.form.dataset.confirmText = this.getAttribute('data-confirm-text');"
                                    data-confirm-text="Are you sure you want to reject cancellation?">Reject Cancellation</button>
                            <a class="btn btn--ghost" href="dashboard.php#cancellation-review-list">Back to list</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</details>
<?php endif; ?>

<?= $invoiceCancellationRequestMarkup ?>

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
    if (el) {
        el.addEventListener('input', function () {
            this.value = this.value.replace(/['"%&()#:<>?\[\]]/g, '');
        });
    }

    var lsKey = <?= json_encode(ALLUREONE_LS_DINGG_BEARER) ?>;
    var csrf = <?= json_encode(csrf_token()) ?>;
    var form = document.getElementById('invoice-search-form');
    var out = document.getElementById('invoice-search-results');
    var statusEl = document.getElementById('invoice-search-status');
    var termEl = el;
    if (!form || !out) return;

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var term = termEl ? String(termEl.value || '').trim() : '';
        if (!term) {
            out.innerHTML = '<p class="alert alert--error" style="margin-top:1rem;margin-bottom:0">Please enter an invoice number.</p>';
            return;
        }
        var tok = '';
        try {
            tok = localStorage.getItem(lsKey) || '';
        } catch (err) {}
        if (!tok) {
            out.innerHTML = '<p class="alert alert--error" style="margin-top:1rem;margin-bottom:0">No Dingg token in this browser. Sign out and sign in again.</p>';
            return;
        }
        if (statusEl) {
            statusEl.style.display = '';
            statusEl.textContent = 'Searching…';
        }
        out.innerHTML = '';
        fetch('invoice_search_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-AllureOne-Dingg-Token': tok
            },
            body: JSON.stringify({ term: term, _csrf: csrf }),
            credentials: 'same-origin'
        })
            .then(function (r) {
                return r.text().then(function (text) {
                    var j = null;
                    try {
                        j = JSON.parse(text);
                    } catch (e) {}
                    return { httpOk: r.ok, j: j, raw: text };
                });
            })
            .then(function (x) {
                if (statusEl) {
                    statusEl.style.display = 'none';
                    statusEl.textContent = '';
                }
                if (!x.j || x.j.ok !== true) {
                    var msg = (x.j && x.j.error) ? String(x.j.error) : 'Could not search invoices.';
                    var det = (x.j && x.j.error_detail) ? String(x.j.error_detail) : '';
                    var logoutHint =
                        x.j && x.j.auth_expired
                            ? ' <a class="link--underlined" href="logout.php">Log out</a>'
                            : '';
                    out.innerHTML =
                        '<p class="alert alert--error" style="margin-top:1rem;margin-bottom:0">' +
                        escapeHtml(msg) +
                        logoutHint +
                        '</p>' +
                        (det !== ''
                            ? '<p class="main__meta" style="margin-top:0.5rem;font-size:0.85rem">' + escapeHtml(det) + '</p>'
                            : '');
                    return;
                }
                out.innerHTML = x.j.html || '';
            })
            .catch(function () {
                if (statusEl) {
                    statusEl.style.display = 'none';
                    statusEl.textContent = '';
                }
                out.innerHTML = '<p class="alert alert--error" style="margin-top:1rem;margin-bottom:0">Network error.</p>';
            });
    });

    out.addEventListener('click', function (ev) {
        var t = ev.target;
        if (t && t.classList && t.classList.contains('invoice-search-reset')) {
            out.innerHTML = '';
            if (termEl) termEl.value = '';
        }
    });

    out.addEventListener(
        'submit',
        function (ev) {
            var f = ev.target;
            if (f && f.classList && f.classList.contains('invoice-cancel-form')) {
                if (f.dataset.submitting === '1') {
                    ev.preventDefault();
                    return;
                }
                f.dataset.submitting = '1';

                var inp = f.querySelector('.invoice-cancel-dingg-bearer');
                if (inp) {
                    try {
                        inp.value = localStorage.getItem(lsKey) || '';
                    } catch (err) {}
                }
                var submitBtn = f.querySelector('.invoice-cancel-submit');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Submitting...';
                }
            }
        },
        true
    );
})();
</script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
