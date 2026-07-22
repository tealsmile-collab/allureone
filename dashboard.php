<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/app_client.php';
require_once __DIR__ . '/includes/gift_helpers.php';
require_login();
require_not_accounts_role();
require_not_franchise_officer_role();

$userEarly = current_user();
if (is_array($userEarly) && in_array((int) ($userEarly['role_id'] ?? 0), [ROLE_THERAPIST, ROLE_HOUSEKEEPING], true)) {
    allureone_redirect('appointment.php');
    exit;
}

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

function allureone_auto_reject_cancellation_request(int $requestId, ?int $branchId = null): bool
{
    if ($requestId <= 0) {
        return false;
    }

    $sql = 'UPDATE allurepro_InvoiceCancellation
            SET CancellationStatus = 2
            WHERE id = :id
              AND CancellationStatus = 0';
    $params = ['id' => $requestId];
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

function allureone_session_key_for_branch_id(int $branchId): string
{
    if ($branchId <= 0) {
        return '';
    }
    try {
        $st = db()->prepare(
            'SELECT session_key
             FROM allureone_session_data
             WHERE branch_id = :branch_id
             ORDER BY updated_date DESC
             LIMIT 1'
        );
        $st->execute(['branch_id' => $branchId]);

        return trim((string) ($st->fetchColumn() ?: ''));
    } catch (PDOException $e) {
        error_log('AllureOne branch session key lookup failed: ' . $e->getMessage());
    }

    return '';
}

/**
 * Resolve live invoice status from Dingg using invoice search API (term=invoice number)
 * with branch-scoped token.
 *
 * @param array<string, mixed> $reviewRow
 *
 * @return array{status_label:string,is_cancelled:bool}
 */
function allureone_fetch_live_invoice_status_for_review(array $reviewRow): array
{
    $fallback = trim((string) ($reviewRow['invoice_status'] ?? ''));
    $invoiceId = (int) ($reviewRow['invoice_id'] ?? 0);
    $invoiceNumber = trim((string) ($reviewRow['invoice_number'] ?? ''));
    $branchId = (int) ($reviewRow['branch_id'] ?? 0);
    if ($invoiceNumber === '' || $branchId <= 0) {
        return ['status_label' => $fallback, 'is_cancelled' => false];
    }

    $token = allureone_session_key_for_branch_id($branchId);
    if ($token === '') {
        return ['status_label' => $fallback, 'is_cancelled' => false];
    }

    $term = sanitize_invoice_search_term($invoiceNumber);
    if ($term === '') {
        return ['status_label' => $fallback, 'is_cancelled' => false];
    }
    $url = 'https://api.dingg.app/api/v1/vendor/bills?' . http_build_query(
        [
            'web' => 'true',
            'page' => '1',
            'limit' => '1000',
            'term' => $term,
            'is_product_only' => '',
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
    $resp = dingg_http_request_authenticated('GET', $url, $token, null);
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');
    $GLOBALS['allureone_review_invoice_api_debug'] = [
        'url' => $url,
        'term' => $term,
        'http' => $http,
        'body' => $body,
        'invoice_id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'branch_id' => $branchId,
    ];
    if ($http < 200 || $http >= 300 || $body === '' || dingg_response_looks_unauthorized($http, $body)) {
        return ['status_label' => $fallback, 'is_cancelled' => false];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['status_label' => $fallback, 'is_cancelled' => false];
    }

    $data = $json['data'] ?? null;
    $bill = null;
    if (is_array($data)) {
        if (isset($data[0]) && is_array($data[0])) {
            // Pick the exact row by id first, then by invoice number.
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ((int) ($row['id'] ?? 0) === $invoiceId) {
                    $bill = $row;
                    break;
                }
            }
            if (!is_array($bill)) {
                $invoiceNoMatch = strtolower($invoiceNumber);
                foreach ($data as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $candidate = strtolower(trim((string) (
                        $row['bill_no_with_prefix']
                        ?? $row['bill_no']
                        ?? $row['invoice_number']
                        ?? ''
                    )));
                    if ($candidate !== '' && $candidate === $invoiceNoMatch) {
                        $bill = $row;
                        break;
                    }
                }
            }
            // Fallback to first row only if exact id row is missing.
            if (!is_array($bill) && is_array($data[0])) {
                $bill = $data[0];
            }
        } elseif (isset($data['id']) || isset($data['payment_status']) || isset($data['status'])) {
            $bill = $data;
        }
    }
    if (!is_array($bill)) {
        return ['status_label' => $fallback, 'is_cancelled' => false];
    }

    // Normalize status values like "false"/"0" to boolean false before formatting.
    if (array_key_exists('status', $bill)) {
        $rawStatus = $bill['status'];
        if (is_string($rawStatus)) {
            $normalized = strtolower(trim($rawStatus));
            if ($normalized === 'false' || $normalized === '0' || $normalized === 'inactive') {
                $bill['status'] = false;
            } elseif ($normalized === 'true' || $normalized === '1' || $normalized === 'active') {
                $bill['status'] = true;
            }
        } elseif (is_int($rawStatus)) {
            $bill['status'] = ($rawStatus === 1);
        }
    }
    $hasStatus = array_key_exists('status', $bill) && is_bool($bill['status']);
    $paymentStatus = trim((string) ($bill['payment_status'] ?? ''));
    $paymentStatusLabel = $paymentStatus !== '' ? ucwords(str_replace('_', ' ', strtolower($paymentStatus))) : '';

    if ($hasStatus && $bill['status'] === false) {
        return ['status_label' => 'Cancelled', 'is_cancelled' => true];
    }
    if ($hasStatus && $bill['status'] === true) {
        return [
            'status_label' => $paymentStatusLabel !== '' ? ('Active - ' . $paymentStatusLabel) : 'Active',
            'is_cancelled' => false,
        ];
    }

    return ['status_label' => $fallback, 'is_cancelled' => false];
}

$invoiceCancelFlash = null;
$invoiceReviewFlash = null;
$user = current_user();
$userRoleId = (int) ($user['role_id'] ?? 0);
$userBranchId = isset($user['branch_id']) && (int) $user['branch_id'] > 0 ? (int) $user['branch_id'] : null;
$invoiceCancellationEnabled = is_invoice_cancellation_enabled($user);
$reviewScopeBranchId = $userRoleId === ROLE_ADMIN ? null : $userBranchId;
$selectedReviewId = isset($_GET['review']) ? (int) $_GET['review'] : 0;
$canReviewCancellations = $invoiceCancellationEnabled && ($userRoleId === ROLE_ADMIN || $userRoleId === ROLE_SUPERADMIN);
$canShowInvoiceCancellationRequest = $invoiceCancellationEnabled
    && $userRoleId !== ROLE_ADMIN
    && $userRoleId !== ROLE_SUPERADMIN;
$canDailySale = ($userRoleId === ROLE_ADMIN || $userRoleId === ROLE_SUPERADMIN);

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
                    $reviewScopeBranchId
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
                $reviewRow = allureone_fetch_pending_cancellation_review_by_id($reviewId, $reviewScopeBranchId);
                if ($reviewRow === null) {
                    $invoiceReviewFlash = ['type' => 'error', 'text' => 'Request not found, already reviewed, or not in your branch scope.'];
                    $selectedReviewId = 0;
                } else {
                    $apiToken = allureone_session_key_for_branch_id((int) ($reviewRow['branch_id'] ?? 0));
                    if ($apiToken === '') {
                        $invoiceReviewFlash = ['type' => 'error', 'text' => 'Dingg session key not found for invoice branch.'];
                        $selectedReviewId = $reviewId;
                    } else {
                        $approveResp = dingg_delete_vendor_bill(
                            (int) ($reviewRow['invoice_id'] ?? 0),
                            (string) ($reviewRow['cancellation_remark'] ?? ''),
                            $apiToken
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
                                    $reviewScopeBranchId
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
        $pendingCancellationRows = allureone_fetch_pending_cancellation_reviews($reviewScopeBranchId);
        if ($selectedReviewId > 0) {
            foreach ($pendingCancellationRows as $r) {
                if ((int) ($r['id'] ?? 0) === $selectedReviewId) {
                    $selectedReviewRow = $r;
                    $liveStatus = allureone_fetch_live_invoice_status_for_review($selectedReviewRow);
                    $selectedReviewRow['invoice_status'] = (string) ($liveStatus['status_label'] ?? ($selectedReviewRow['invoice_status'] ?? ''));
                    $selectedReviewRow['is_live_cancelled'] = !empty($liveStatus['is_cancelled']);
                    if (!empty($selectedReviewRow['is_live_cancelled'])) {
                        try {
                            if (allureone_auto_reject_cancellation_request((int) ($selectedReviewRow['id'] ?? 0), $reviewScopeBranchId)) {
                                $invoiceReviewFlash = [
                                    'type' => 'ok',
                                    'text' => 'Invoice is already cancelled in Dingg. Request auto-marked as Rejected.',
                                ];
                                $selectedReviewRow = null;
                                $selectedReviewId = 0;
                            }
                        } catch (PDOException $e) {
                            error_log('AllureOne auto reject cancellation review failed: ' . $e->getMessage());
                        }
                    }
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
    $wpPrefix = wp_table_prefix();
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
            MAX(CASE WHEN oim.meta_key = 'Recipient Mobile' THEN oim.meta_value END) AS recipient_mobile,
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
    $baseSelect = str_replace('wp_', $wpPrefix, $baseSelect);

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

            // Fallback: some setups store _ywgc_recipient on gift-card post (post_title = code)
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

<?php if ($canDailySale): ?>
<?php
$dailySaleTodayYmd = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
?>
<details class="card" id="daily-sale-card">
    <summary class="card__head card__toggle">
        <span class="card__toggle-inner">
            <span>Daily Sale</span>
            <span class="card__chevron" aria-hidden="true">▼</span>
        </span>
    </summary>
    <div class="card__body daily-sale-card-body">
        <div class="daily-sale-toolbar">
            <div class="daily-sale-date-row">
                <label for="daily-sale-date" class="daily-sale-date-label">Date</label>
                <input type="date" id="daily-sale-date" class="daily-sale-date-input" value="<?= e($dailySaleTodayYmd) ?>" max="<?= e($dailySaleTodayYmd) ?>">
                <button type="button" class="btn btn--primary daily-sale-view-btn" id="daily-sale-view" title="View">
                    <svg class="daily-sale-view-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path fill="currentColor" d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/>
                    </svg>
                    <span>View</span>
                </button>
            </div>
        </div>
        <div id="daily-sale-status" class="daily-sale-status" aria-live="polite"></div>
        <div class="table-wrap" id="daily-sale-table-wrap" hidden>
            <table class="data" id="daily-sale-table">
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th>Total Sale (₹)</th>
                        <th>Membership (₹)</th>
                        <th>Services (₹)</th>
                    </tr>
                </thead>
                <tbody id="daily-sale-body"></tbody>
            </table>
        </div>
    </div>
</details>
<style>
.daily-sale-card-body {
    padding: 0.85rem 0.65rem 1rem;
}
.daily-sale-toolbar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin: 0 0 0.75rem;
}
.daily-sale-date-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.daily-sale-date-label {
    font-size: 0.9rem;
    color: #475569;
    margin: 0;
}
.daily-sale-date-input {
    padding: 0.4rem 0.55rem;
    border: 1px solid var(--border, #d0d7de);
    border-radius: 6px;
    font: inherit;
    min-width: 10.5rem;
}
.daily-sale-view-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.daily-sale-view-icon {
    display: block;
}
.daily-sale-status {
    display: none;
    align-items: center;
    gap: 0.55rem;
    margin: 0 0 0.85rem;
    min-height: 1.75rem;
    color: #334155;
    font-size: 0.9rem;
}
.daily-sale-status.is-visible {
    display: flex;
}
.daily-sale-spinner {
    display: inline-block;
    width: 22px;
    height: 22px;
    border: 3px solid #c9d8ea;
    border-top-color: #2f5f90;
    border-radius: 50%;
    animation: dailySaleSpin 0.8s linear infinite;
    flex: 0 0 auto;
}
@keyframes dailySaleSpin {
    to { transform: rotate(360deg); }
}
#daily-sale-table-wrap {
    overflow-x: auto;
    max-width: 100%;
    margin-left: -0.2rem;
}
#daily-sale-table {
    width: 100%;
    border-collapse: collapse;
}
#daily-sale-table th,
#daily-sale-table td {
    white-space: nowrap;
    padding: 0.35rem 0.45rem;
    font-size: 0.88rem;
}
#daily-sale-table th:first-child,
#daily-sale-table td:first-child {
    padding-left: 0.35rem;
}
#daily-sale-table th {
    letter-spacing: 0.02em;
}
</style>
<?php endif; ?>

<?php if ($canReviewCancellations): ?>
<?php
$cancellationReviewOpen = count($pendingCancellationRows) > 0
    || $selectedReviewRow !== null
    || $invoiceReviewFlash !== null;
?>
<details class="card<?= $canDailySale ? ' card--spaced-top' : '' ?>"<?= $cancellationReviewOpen ? ' open' : '' ?>>
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
                            <tr><th>Branch</th><td><?= e((string) ($selectedReviewRow['branch_name'] ?? '')) ?></td></tr>
                            <tr><th>Invoice date</th><td><?= e((string) ($selectedReviewRow['invoice_date'] ?? '')) ?></td></tr>
                            <tr><th>Client name</th><td><?= e((string) ($selectedReviewRow['client_name'] ?? '')) ?></td></tr>
                            <tr><th>Invoice amount</th><td><?= e((string) ($selectedReviewRow['invoice_amount'] ?? '')) ?></td></tr>
                            <tr><th>Invoice status</th><td><?= e((string) ($selectedReviewRow['invoice_status'] ?? '')) ?></td></tr>
                            <tr><th>Cancellation reason</th><td><?= e((string) ($selectedReviewRow['cancellation_remark'] ?? '')) ?></td></tr>
                            <tr><th>Request user</th><td><?= e((string) ($selectedReviewRow['request_user_name'] ?? '')) ?> (ID: <?= (int) ($selectedReviewRow['request_user_id'] ?? 0) ?>)</td></tr>
                            <tr><th>Request date</th><td><?= e(allureone_format_datetime_ist((string) ($selectedReviewRow['cancellation_request_date'] ?? ''))) ?></td></tr>
                        </tbody>
                    </table>
                    <?php if (!empty($selectedReviewRow['is_live_cancelled'])): ?>
                        <div class="alert alert--warn" style="margin-top:0.75rem;margin-bottom:0">
                            Invoice is already cancelled in Dingg. Approve/Reject actions are hidden.
                        </div>
                        <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;margin-top:0.75rem">
                            <a class="btn btn--ghost" href="dashboard.php#cancellation-review-list">Back to list</a>
                        </div>
                    <?php else: ?>
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
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</details>
<?php endif; ?>

<?php if ($selectedItemId <= 0 && $canShowInvoiceCancellationRequest): ?>
<?= $invoiceCancellationRequestMarkup ?>
<?php endif; ?>

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
                            <tr><th>Recipient Mobile</th><td><?= e((string) ($giftDetail['recipient_mobile'] ?? '')) ?></td></tr>
                            <tr><th>Recipient Email</th><td><?= e((string) ($giftDetail['recipient_email'] ?? '')) ?></td></tr>
                            <tr><th>Message</th><td><?= e((string) ($giftDetail['message'] ?? '')) ?></td></tr>
                            <tr><th>Buyer Name</th><td><?= e((string) ($giftDetail['sender_name'] ?? '')) ?></td></tr>
                            <tr><th>Buyer Phone</th><td><?= e((string) ($giftDetail['buyer_phone'] ?? '')) ?></td></tr>
                            <tr><th>Location</th><td><?= e((string) ($giftDetail['location'] ?? '')) ?></td></tr>
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
                        </tbody>
                    </table>
                    <p style="margin-top:0.75rem;margin-bottom:0"><a class="btn btn--ghost" href="dashboard.php">Back</a></p>
                </div>
            <?php endif; ?>
        <?php elseif (count($giftRows) === 0): ?>
            <p class="empty">No gift cards yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data data--dashboard-gift-codes">
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
                                <td><?= e((string) ($gr['location'] ?? '')) ?></td>
                                <td>
                                    <a class="link--underlined" href="dashboard.php?gift=<?= (int) ($gr['order_item_id'] ?? 0) ?>">
                                        <?= e((string) ($gr['sender_name'] ?? '')) ?>
                                    </a>
                                </td>
                                <td><?= e(format_amount($gr['amount'] ?? null)) ?></td>
                                <td><?= e(format_purchase_date($gr['post_date'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="main__meta" style="padding:0 1.25rem 1rem;margin:0">(Showing latest 5 sale data.)</p>
        <?php endif; ?>
    </div>
</details>

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

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    if (form && out) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var term = termEl ? String(termEl.value || '').trim() : '';
            if (!term) {
                out.innerHTML = '<p class="alert alert--error" style="margin-top:1rem;margin-bottom:0">Please enter an invoice number.</p>';
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
                    'Content-Type': 'application/json'
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
                    if (ev.defaultPrevented) {
                        return;
                    }
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
            false
        );
    }

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
<?php if ($canDailySale): ?>
<script>
(function () {
    var card = document.getElementById('daily-sale-card');
    if (!card) {
        return;
    }
    var statusEl = document.getElementById('daily-sale-status');
    var wrapEl = document.getElementById('daily-sale-table-wrap');
    var bodyEl = document.getElementById('daily-sale-body');
    var dateEl = document.getElementById('daily-sale-date');
    var viewBtn = document.getElementById('daily-sale-view');
    var apiUrl = <?= json_encode(allureone_url('daily_sale_api.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var loading = false;
    var loadedDate = '';

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    function selectedDate() {
        var v = dateEl ? String(dateEl.value || '').trim() : '';
        if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) {
            return '';
        }
        return v;
    }

    function formatAmount(val) {
        if (val === null || val === undefined || val === '') {
            return '—';
        }
        var n = Number(val);
        if (!isFinite(n)) {
            return '—';
        }
        return Math.round(n).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    }

    function setStatus(html, withSpinner) {
        if (!statusEl) {
            return;
        }
        if (!html) {
            statusEl.classList.remove('is-visible');
            statusEl.innerHTML = '';
            return;
        }
        statusEl.classList.add('is-visible');
        statusEl.innerHTML = (withSpinner ? '<span class="daily-sale-spinner" aria-hidden="true"></span>' : '') + '<span>' + html + '</span>';
    }

    function hideTable() {
        if (bodyEl) {
            bodyEl.innerHTML = '';
        }
        if (wrapEl) {
            wrapEl.hidden = true;
        }
        loadedDate = '';
        setStatus('', false);
    }

    function appendRow(row) {
        if (!bodyEl) {
            return;
        }
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + esc(row.branch_name || '') + '</td>' +
            '<td>' + esc(formatAmount(row.total_sale)) + '</td>' +
            '<td>' + esc(formatAmount(row.membership)) + '</td>' +
            '<td>' + esc(formatAmount(row.services)) + '</td>';
        bodyEl.appendChild(tr);
        if (wrapEl) {
            wrapEl.hidden = false;
        }
    }

    function fetchJson(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) {
            return r.text().then(function (text) {
                var j = null;
                try {
                    j = text ? JSON.parse(text) : null;
                } catch (e) {
                    throw new Error('Invalid response' + (r.status ? ' (HTTP ' + r.status + ')' : ''));
                }
                if (!r.ok || !j || j.ok !== true) {
                    throw new Error((j && j.error) ? String(j.error) : ('Request failed' + (r.status ? ' (HTTP ' + r.status + ')' : '')));
                }
                return j;
            });
        });
    }

    function loadDailySale() {
        if (loading) {
            return;
        }
        var date = selectedDate();
        if (!date) {
            setStatus('Please select a date.', false);
            return;
        }
        loading = true;
        if (viewBtn) {
            viewBtn.disabled = true;
        }
        if (bodyEl) {
            bodyEl.innerHTML = '';
        }
        if (wrapEl) {
            wrapEl.hidden = true;
        }
        setStatus('Loading branches…', true);

        fetchJson(apiUrl + '?action=branches&date=' + encodeURIComponent(date))
            .then(function (j) {
                var branches = Array.isArray(j.branches) ? j.branches : [];
                var fetchDate = j.date || date;
                if (branches.length === 0) {
                    setStatus('No branches to display.', false);
                    return Promise.resolve();
                }

                var i = 0;
                function next() {
                    if (i >= branches.length) {
                        setStatus('', false);
                        loadedDate = fetchDate;
                        return Promise.resolve();
                    }
                    var b = branches[i] || {};
                    var idx = i + 1;
                    i += 1;
                    setStatus('Loading ' + esc(String(idx)) + ' of ' + esc(String(branches.length)) + ': ' + esc(String(b.branch_name || '')), true);
                    var url = apiUrl + '?action=fetch&branch_id=' + encodeURIComponent(String(b.branch_id || '')) + '&date=' + encodeURIComponent(fetchDate);
                    return fetchJson(url).then(function (res) {
                        appendRow(res.row || {
                            branch_name: b.branch_name || '',
                            total_sale: null,
                            services: null,
                            membership: null
                        });
                        return next();
                    }).catch(function () {
                        appendRow({
                            branch_name: b.branch_name || '',
                            total_sale: null,
                            services: null,
                            membership: null
                        });
                        return next();
                    });
                }
                return next();
            })
            .catch(function (err) {
                setStatus(esc((err && err.message) ? String(err.message) : 'Could not load daily sale data.'), false);
            })
            .finally(function () {
                loading = false;
                if (viewBtn) {
                    viewBtn.disabled = false;
                }
            });
    }

    if (dateEl) {
        dateEl.addEventListener('change', function () {
            hideTable();
        });
        dateEl.addEventListener('input', function () {
            hideTable();
        });
    }
    if (viewBtn) {
        viewBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!card.open) {
                card.open = true;
            }
            loadDailySale();
        });
    }
    card.addEventListener('toggle', function () {
        if (!card.open) {
            return;
        }
        var date = selectedDate();
        if (!date) {
            return;
        }
        // First expand (or after date change): load selected date (defaults to today).
        if (loadedDate !== date && !loading) {
            loadDailySale();
        }
    });
})();
</script>
<?php endif; ?>
<?php
$invoiceReviewApiDebug = $GLOBALS['allureone_review_invoice_api_debug'] ?? null;
if (is_array($invoiceReviewApiDebug) && $selectedReviewRow !== null):
?>
<script>
console.log('Cancellation review invoice API response', <?= json_encode($invoiceReviewApiDebug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
