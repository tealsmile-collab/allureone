<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_not_accounts_role();
require_not_franchise_officer_role();

$user = current_user();
$roleId = (int) ($user['role_id'] ?? 0);
if ($roleId !== ROLE_SUPERADMIN && $roleId !== ROLE_ADMIN && $roleId !== 3) {
    http_response_code(403);
    exit('Forbidden');
}

function crm_format_last_visit(?string $date): string
{
    $raw = trim((string) ($date ?? ''));
    if ($raw === '') {
        return '—';
    }
    try {
        $dt = new DateTime($raw);

        return $dt->format('d-M-y');
    } catch (Exception $e) {
        return $raw;
    }
}

function crm_format_utc_to_datetime_local_input(?string $utcDateTime): string
{
    $raw = trim((string) ($utcDateTime ?? ''));
    if ($raw === '') {
        return '';
    }
    try {
        $dt = new DateTime($raw, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));

        return $dt->format('Y-m-d\TH:i');
    } catch (Exception $e) {
        return '';
    }
}

function crm_parse_datetime_local_to_mysql_utc(string $value): ?string
{
    $raw = trim($value);
    if ($raw === '') {
        return null;
    }
    try {
        $dt = new DateTime($raw, new DateTimeZone('Asia/Kolkata'));
        $dt->setTimezone(new DateTimeZone('UTC'));

        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function crm_branch_session_key(int $branchId): string
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
        error_log('AllureOne CRM branch session key lookup failed: ' . $e->getMessage());
    }

    return '';
}

function crm_api_value_or_dash($value): string
{
    $v = trim((string) ($value ?? ''));

    return $v !== '' ? $v : '—';
}

function crm_amount_display($value): string
{
    if (!is_numeric($value)) {
        return '0';
    }
    $n = (float) $value;
    $s = number_format($n, 2, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');

    return $s === '' ? '0' : $s;
}

function crm_format_date_dd_mmm_yy(?string $rawDate): string
{
    $raw = trim((string) ($rawDate ?? ''));
    if ($raw === '') {
        return '—';
    }
    try {
        $dt = new DateTime($raw);

        return $dt->format('d-M-y');
    } catch (Exception $e) {
        return $raw;
    }
}

function crm_whatsapp_wa_me_url(?string $mobile): string
{
    $digits = preg_replace('/\D+/', '', (string) ($mobile ?? '')) ?? '';
    if ($digits === '') {
        return '';
    }

    return 'https://wa.me/' . $digits;
}

/**
 * @return array{0:?string,1:?string} UTC range [start, endExclusive]
 */
function crm_followup_range_utc_bounds(string $rangeKey): array
{
    $key = strtolower(trim($rangeKey));
    $tz = new DateTimeZone('Asia/Kolkata');
    $utc = new DateTimeZone('UTC');
    $now = new DateTime('now', $tz);

    $start = null;
    $endExclusive = null;
    if ($key === 'today') {
        $start = (clone $now)->setTime(0, 0, 0);
        $endExclusive = (clone $start)->modify('+1 day');
    } elseif ($key === 'tomorrow') {
        $start = (clone $now)->setTime(0, 0, 0)->modify('+1 day');
        $endExclusive = (clone $start)->modify('+1 day');
    } elseif ($key === 'this_week') {
        $start = (clone $now)->setISODate((int) $now->format('o'), (int) $now->format('W'))->setTime(0, 0, 0);
        $endExclusive = (clone $start)->modify('+7 day');
    } elseif ($key === 'this_month') {
        $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $endExclusive = (clone $start)->modify('first day of next month');
    }
    if ($start === null || $endExclusive === null) {
        return [null, null];
    }
    $start->setTimezone($utc);
    $endExclusive->setTimezone($utc);

    return [$start->format('Y-m-d H:i:s'), $endExclusive->format('Y-m-d H:i:s')];
}

$flash = ['type' => '', 'text' => ''];
$loadError = '';
$rows = [];
$detailRow = null;
$crmApiSummary = ['dob' => '—', 'first_visit' => '—', 'avg_spend' => '—'];
$crmInvoiceRows = [];
$crmApiError = '';
$statusOptions = [];
$statusIdToKey = [];
$statusIdToLabel = [];
$branchOptions = [];
$isAdminRole = ($roleId === ROLE_SUPERADMIN || $roleId === ROLE_ADMIN);
$userBranchId = isset($user['branch_id']) && (int) $user['branch_id'] > 0 ? (int) $user['branch_id'] : null;
$fBranchSel = isset($_GET['f_branch']) ? (int) $_GET['f_branch'] : 0;
$showRequested = isset($_GET['show']) && (string) $_GET['show'] === '1';
$fStatusSel = isset($_GET['f_status']) ? (int) $_GET['f_status'] : 0; // 0 => All
$fFollowupRange = isset($_GET['f_followup_range']) ? strtolower(trim((string) $_GET['f_followup_range'])) : '';
$followupRangeOptions = [
    'today' => 'Today',
    'tomorrow' => 'Tomorrow',
    'this_week' => 'This week',
    'this_month' => 'This month',
];
$sortBy = isset($_GET['sort_by']) ? strtolower(trim((string) $_GET['sort_by'])) : 'last_visit';
$sortDir = isset($_GET['sort_dir']) ? strtolower(trim((string) $_GET['sort_dir'])) : 'desc';
$sortAllowed = ['name', 'last_visit'];
if (!in_array($sortBy, $sortAllowed, true)) {
    $sortBy = 'last_visit';
}
if ($sortDir !== 'asc' && $sortDir !== 'desc') {
    $sortDir = 'desc';
}
$listPerPage = 20;
$listPage = max(1, (int) ($_GET['page'] ?? 1));
$listTotal = 0;
$listTotalPages = 1;
$detailId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$followUpFilterStatusId = null;

try {
    $statusStmt = db()->prepare(
        "SELECT id, status_key, status_label
         FROM allureone_leads_status
         WHERE is_active = 1
           AND applies_to IN ('all', 'meta')
         ORDER BY sort_order ASC, id ASC"
    );
    $statusStmt->execute();
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
        $sid = (int) ($sr['id'] ?? 0);
        $skey = trim((string) ($sr['status_key'] ?? ''));
        $slabel = trim((string) ($sr['status_label'] ?? ''));
        if ($sid <= 0 || $slabel === '') {
            continue;
        }
        $statusOptions[] = ['id' => $sid, 'key' => $skey, 'label' => $slabel];
        $statusIdToKey[$sid] = $skey;
        $statusIdToLabel[$sid] = $slabel;
        if ($skey === 'follow_up') {
            $followUpFilterStatusId = $sid;
        }
    }
} catch (PDOException $e) {
    error_log('AllureOne CRM status load failed: ' . $e->getMessage());
}

if ($isAdminRole) {
    try {
        $bs = db()->query(
            'SELECT id, locality, business_name
             FROM allureone_branch
             WHERE isActive = 1
             ORDER BY locality ASC, business_name ASC, id ASC'
        );
        foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $br) {
            $bid = (int) ($br['id'] ?? 0);
            if ($bid <= 0) {
                continue;
            }
            $lbl = trim((string) ($br['locality'] ?? ''));
            if ($lbl === '') {
                $lbl = trim((string) ($br['business_name'] ?? ''));
            }
            if ($lbl === '') {
                $lbl = 'Branch #' . $bid;
            }
            $branchOptions[$bid] = $lbl;
        }
        if ($fBranchSel > 0 && !isset($branchOptions[$fBranchSel])) {
            $fBranchSel = 0;
        }
    } catch (PDOException $e) {
        error_log('AllureOne CRM branch options load failed: ' . $e->getMessage());
    }
}
if ($fStatusSel > 0 && !isset($statusIdToKey[$fStatusSel])) {
    $fStatusSel = 0;
}
if ($fFollowupRange !== '' && !isset($followupRangeOptions[$fFollowupRange])) {
    $fFollowupRange = '';
}
if ($fStatusSel <= 0 || $followUpFilterStatusId === null || $fStatusSel !== $followUpFilterStatusId) {
    $fFollowupRange = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_crm'])) {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Invalid session. Please refresh and try again.'];
    } else {
        $saveId = isset($_POST['crm_id']) ? (int) $_POST['crm_id'] : 0;
        $statusId = isset($_POST['crm_status']) ? (int) $_POST['crm_status'] : 0;
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        $followupInput = trim((string) ($_POST['followup_datetime'] ?? ''));
        $statusKey = $statusIdToKey[$statusId] ?? '';
        $followupUtc = null;

        if ($saveId <= 0) {
            $flash = ['type' => 'error', 'text' => 'Invalid CRM client selected.'];
        } elseif (!isset($statusIdToKey[$statusId])) {
            $flash = ['type' => 'error', 'text' => 'Please select valid status.'];
        } elseif ($remarks === '') {
            $flash = ['type' => 'error', 'text' => 'Remarks is required.'];
        } elseif ((function_exists('mb_strlen') ? mb_strlen($remarks) : strlen($remarks)) > 500) {
            $flash = ['type' => 'error', 'text' => 'Remarks can be maximum 500 characters.'];
        } else {
            if ($statusKey === 'follow_up') {
                $followupUtc = crm_parse_datetime_local_to_mysql_utc($followupInput);
                if ($followupUtc === null) {
                    $flash = ['type' => 'error', 'text' => 'Please select valid follow-up date/time.'];
                }
            }
        }

        if ($flash['text'] === '') {
            try {
                $params = [
                    'id' => $saveId,
                    'crm_status' => $statusId,
                    'remarks' => $remarks,
                    'followup_datetime' => ($statusKey === 'follow_up') ? $followupUtc : null,
                ];
                $sql = "UPDATE allureone_crm
                        SET crm_status = :crm_status,
                            remarks = :remarks,
                            followup_datetime = :followup_datetime,
                            last_contacted_datetime = NOW(),
                            update_datetime = NOW()
                        WHERE id = :id";
                if ($isAdminRole) {
                    if ($fBranchSel > 0) {
                        $sql .= ' AND branch_id = :branch_id';
                        $params['branch_id'] = $fBranchSel;
                    }
                } else {
                    if ($userBranchId === null) {
                        $sql .= ' AND 0=1';
                    } else {
                        $sql .= ' AND branch_id = :branch_id';
                        $params['branch_id'] = $userBranchId;
                    }
                }
                $st = db()->prepare($sql);
                $st->execute($params);
                $flash = ($st->rowCount() > 0)
                    ? ['type' => 'ok', 'text' => 'CRM client updated successfully.']
                    : ['type' => 'error', 'text' => 'No updates made (record not found/in scope or values unchanged).'];
            } catch (PDOException $e) {
                error_log('AllureOne CRM save failed: ' . $e->getMessage());
                $flash = ['type' => 'error', 'text' => 'Could not save CRM client details.'];
            }
        }
        $detailId = $saveId;
    }
}

try {
    if ($detailId > 0) {
        $params = ['id' => $detailId];
        $sql = "SELECT id, user_id, `Mobile` AS mobile, fname, `Gender` AS gender, client_code, last_visit,
                       branch_id, crm_status, remarks, followup_datetime, last_contacted_datetime
                FROM allureone_crm
                WHERE id = :id";
        if ($isAdminRole) {
            if ($fBranchSel > 0) {
                $sql .= ' AND branch_id = :branch_id';
                $params['branch_id'] = $fBranchSel;
            }
        } else {
            if ($userBranchId === null) {
                $sql .= ' AND 0=1';
            } else {
                $sql .= ' AND branch_id = :branch_id';
                $params['branch_id'] = $userBranchId;
            }
        }
        $sql .= ' LIMIT 1';
        $st = db()->prepare($sql);
        $st->execute($params);
        $detailRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (is_array($detailRow)) {
            $detailUserId = (int) ($detailRow['user_id'] ?? 0);
            $detailBranchId = (int) ($detailRow['branch_id'] ?? 0);
            $sessionKey = crm_branch_session_key($detailBranchId);
            if ($detailUserId > 0 && $sessionKey !== '') {
                $detailUrl = 'https://api.dingg.app/api/v1/vendor/customer/detail?' . http_build_query(
                    ['id' => (string) $detailUserId, 'is_multi_location' => 'false'],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
                $detailResp = dingg_http_request_authenticated('GET', $detailUrl, $sessionKey, null);
                $detailHttp = (int) ($detailResp['http'] ?? 0);
                $detailBody = (string) ($detailResp['body'] ?? '');
                if ($detailHttp >= 200 && $detailHttp < 300 && $detailBody !== '' && !dingg_response_looks_unauthorized($detailHttp, $detailBody)) {
                    $detailJson = json_decode($detailBody, true);
                    $detailData = is_array($detailJson) && isset($detailJson['data']) && is_array($detailJson['data']) ? $detailJson['data'] : [];
                    if ($detailData !== []) {
                        $crmApiSummary['dob'] = crm_api_value_or_dash($detailData['dob'] ?? '');
                        $crmApiSummary['first_visit'] = crm_api_value_or_dash($detailData['joined_date'] ?? '');
                        $userHistories = isset($detailData['user_histories']) && is_array($detailData['user_histories']) ? $detailData['user_histories'] : [];
                        $firstHistory = isset($userHistories[0]) && is_array($userHistories[0]) ? $userHistories[0] : [];
                        $avgSpendRaw = $firstHistory['avg_spend'] ?? '';
                        $crmApiSummary['avg_spend'] = is_numeric($avgSpendRaw) ? ('Rs. ' . crm_amount_display($avgSpendRaw)) : crm_api_value_or_dash($avgSpendRaw);
                    }
                }

                $invoiceUrl = 'https://api.dingg.app/api/v1/vendor/customer/bill?' . http_build_query(
                    ['id' => (string) $detailUserId],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
                $invoiceResp = dingg_http_request_authenticated('GET', $invoiceUrl, $sessionKey, null);
                $invoiceHttp = (int) ($invoiceResp['http'] ?? 0);
                $invoiceBody = (string) ($invoiceResp['body'] ?? '');
                if ($invoiceHttp >= 200 && $invoiceHttp < 300 && $invoiceBody !== '' && !dingg_response_looks_unauthorized($invoiceHttp, $invoiceBody)) {
                    $invoiceJson = json_decode($invoiceBody, true);
                    $invoiceData = is_array($invoiceJson) && isset($invoiceJson['data']) && is_array($invoiceJson['data']) ? $invoiceJson['data'] : [];
                    foreach ($invoiceData as $invoiceRow) {
                        if (!is_array($invoiceRow)) {
                            continue;
                        }
                        $crmInvoiceRows[] = [
                            'bill_number' => trim((string) ($invoiceRow['bill_number'] ?? '')),
                            'selected_date' => trim((string) ($invoiceRow['selected_date'] ?? '')),
                            'paid' => $invoiceRow['paid'] ?? null,
                            'payment_status' => trim((string) ($invoiceRow['payment_status'] ?? '')),
                        ];
                    }
                }
            } elseif ($detailUserId > 0 && $sessionKey === '') {
                $crmApiError = 'Dingg session key is not configured for this branch.';
            }
        }
    } else {
        $allowListFetch = true;
        $params = [];
        $where = ' WHERE 1=1';
        if ($fStatusSel > 0) {
            $where .= ' AND c.crm_status = :crm_status';
            $params['crm_status'] = $fStatusSel;
        }
        if ($fFollowupRange !== '' && $followUpFilterStatusId !== null && $fStatusSel === $followUpFilterStatusId) {
            [$followupStartUtc, $followupEndUtc] = crm_followup_range_utc_bounds($fFollowupRange);
            if ($followupStartUtc !== null && $followupEndUtc !== null) {
                $where .= ' AND c.followup_datetime IS NOT NULL AND c.followup_datetime >= :followup_start_utc AND c.followup_datetime < :followup_end_utc';
                $params['followup_start_utc'] = $followupStartUtc;
                $params['followup_end_utc'] = $followupEndUtc;
            }
        }
        if ($isAdminRole) {
            if (!$showRequested) {
                $allowListFetch = false;
            } elseif ($fBranchSel > 0) {
                $where .= ' AND c.branch_id = :branch_id';
                $params['branch_id'] = $fBranchSel;
            } else {
                $allowListFetch = false;
            }
        } else {
            if ($userBranchId === null) {
                $where .= ' AND 0=1';
            } else {
                $where .= ' AND c.branch_id = :branch_id';
                $params['branch_id'] = $userBranchId;
            }
        }
        if ($allowListFetch) {
            $countSql = 'SELECT COUNT(*) FROM allureone_crm c' . $where;
            $countStmt = db()->prepare($countSql);
            $countStmt->execute($params);
            $listTotal = (int) ($countStmt->fetchColumn() ?: 0);
            $listTotalPages = max(1, (int) ceil($listTotal / $listPerPage));
            $listPage = min($listPage, $listTotalPages);
            $offset = ($listPage - 1) * $listPerPage;

            $nameDir = $sortBy === 'name' ? $sortDir : 'asc';
            $visitDir = $sortBy === 'last_visit' ? $sortDir : 'desc';
            $orderBy = ($sortBy === 'name')
                ? (' ORDER BY c.fname ' . ($nameDir === 'asc' ? 'ASC' : 'DESC') . ', c.id DESC')
                : (' ORDER BY c.last_visit ' . ($visitDir === 'asc' ? 'ASC' : 'DESC') . ', c.id DESC');
            $sql = "SELECT c.id, c.fname, c.`Mobile` AS mobile, c.last_visit, c.crm_status
                    FROM allureone_crm c" . $where . "
                    " . $orderBy . "
                    LIMIT " . $listPerPage . " OFFSET " . $offset;
            $st = db()->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = [];
            $listTotal = 0;
            $listTotalPages = 1;
            $listPage = 1;
        }
    }
} catch (PDOException $e) {
    error_log('AllureOne CRM page load failed: ' . $e->getMessage());
    $loadError = 'Could not load CRM data.';
}

$listFilterParams = [];
if ($isAdminRole && $fBranchSel > 0) {
    $listFilterParams['f_branch'] = $fBranchSel;
}
if ($isAdminRole && $showRequested) {
    $listFilterParams['show'] = 1;
}
if ($fStatusSel > 0) {
    $listFilterParams['f_status'] = $fStatusSel;
}
if ($fFollowupRange !== '') {
    $listFilterParams['f_followup_range'] = $fFollowupRange;
}
if ($sortBy !== 'last_visit' || $sortDir !== 'desc') {
    $listFilterParams['sort_by'] = $sortBy;
    $listFilterParams['sort_dir'] = $sortDir;
}

$pageTitle = 'CRM';
$activeNav = 'crm';
require __DIR__ . '/includes/layout_start.php';
?>

<?php
$nameNextDir = ($sortBy === 'name' && $sortDir === 'desc') ? 'asc' : 'desc';
$visitNextDir = ($sortBy === 'last_visit' && $sortDir === 'desc') ? 'asc' : 'desc';
$nameArrow = $sortBy === 'name' ? ($sortDir === 'desc' ? ' ↓' : ' ↑') : '';
$visitArrow = $sortBy === 'last_visit' ? ($sortDir === 'desc' ? ' ↓' : ' ↑') : '';
$nameSortUrl = 'crm.php?' . http_build_query(array_merge($listFilterParams, ['sort_by' => 'name', 'sort_dir' => $nameNextDir, 'page' => 1]));
$visitSortUrl = 'crm.php?' . http_build_query(array_merge($listFilterParams, ['sort_by' => 'last_visit', 'sort_dir' => $visitNextDir, 'page' => 1]));
$showTotalRecordsLabel = !$isAdminRole || ($showRequested && $fBranchSel > 0);
?>

<div class="card">
    <div class="card__head">
        <span>CRM</span>
    </div>
    <div class="card__body">
        <?php if ($flash['text'] !== ''): ?>
            <p class="alert alert--<?= $flash['type'] === 'ok' ? 'ok' : 'error' ?>" style="margin:1rem 1.25rem 0"><?= e($flash['text']) ?></p>
        <?php endif; ?>
        <?php if ($loadError !== ''): ?>
            <p class="alert alert--error" style="margin:1rem 1.25rem"><?= e($loadError) ?></p>
        <?php elseif ($detailId > 0): ?>
            <?php if (!is_array($detailRow)): ?>
                <p class="empty">Client not found.</p>
                <p style="padding:0 1.25rem 1.25rem;margin:0"><a class="btn btn--ghost" href="crm.php?<?= e(http_build_query($listFilterParams)) ?>">Back</a></p>
            <?php else: ?>
                <?php
                $currStatusId = (int) ($detailRow['crm_status'] ?? 0);
                $currStatusKey = (string) ($statusIdToKey[$currStatusId] ?? '');
                $detailMobile = (string) ($detailRow['mobile'] ?? '');
                $detailWhatsappUrl = crm_whatsapp_wa_me_url($detailMobile);
                ?>
                <style>
                .crm-detail-compact .data th,
                .crm-detail-compact .data td { padding: .4rem .55rem; }
                .crm-detail-compact .form__row { margin-bottom: .45rem !important; }
                .crm-detail-compact input[type="text"],
                .crm-detail-compact input[type="datetime-local"],
                .crm-detail-compact select,
                .crm-detail-compact textarea { padding: .45rem .55rem; }
                .crm-detail-compact .btn { padding: .45rem .8rem; }
                </style>
                <div class="crm-detail-compact" style="padding:0.85rem 1rem">
                    <table class="data">
                        <tbody>
                            <tr><th>Full Name</th><td><?= e((string) ($detailRow['fname'] ?? '')) ?></td></tr>
                            <tr>
                                <th>Number</th>
                                <td>
                                    <?= e($detailMobile) ?>
                                    <?php if ($detailWhatsappUrl !== ''): ?>
                                        &nbsp;<a class="link--underlined" href="<?= e($detailWhatsappUrl) ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr><th>Gender</th><td><?= e((string) ($detailRow['gender'] ?? '')) ?></td></tr>
                            <tr><th>Client Code</th><td><?= e((string) ($detailRow['client_code'] ?? '')) ?></td></tr>
                            <tr><th>Last Visit</th><td><?= e(crm_format_date_dd_mmm_yy((string) ($detailRow['last_visit'] ?? ''))) ?></td></tr>
                        </tbody>
                    </table>
                    <div class="card" style="margin-top:0.55rem">
                        <div class="card__head"><span>Summary & Invoices</span></div>
                        <div class="card__body" style="padding:0.55rem 0.75rem">
                            <table class="data" style="margin:0">
                                <tbody>
                                    <tr><th>DOB</th><td><?= e($crmApiSummary['dob']) ?></td></tr>
                                    <tr><th>First Visit</th><td><?= e($crmApiSummary['first_visit']) ?></td></tr>
                                    <tr><th>Average Spend</th><td><?= e($crmApiSummary['avg_spend']) ?></td></tr>
                                </tbody>
                            </table>
                            <div style="margin-top:0.4rem;padding-top:0.35rem;border-top:1px solid #e2e8f0">
                                <?php if ($crmApiError !== ''): ?>
                                    <p class="alert alert--error" style="margin:0"><?= e($crmApiError) ?></p>
                                <?php elseif ($crmInvoiceRows === []): ?>
                                    <p class="empty" style="margin:0">No invoices found.</p>
                                <?php else: ?>
                                    <?php foreach ($crmInvoiceRows as $inv): ?>
                                        <?php
                                        $invBill = trim((string) ($inv['bill_number'] ?? ''));
                                        $invDate = trim((string) ($inv['selected_date'] ?? ''));
                                        $invPaid = crm_amount_display($inv['paid'] ?? null);
                                        $invPaymentStatus = strtolower(trim((string) ($inv['payment_status'] ?? '')));
                                        $isPaid = ($invPaymentStatus === 'is_paid');
                                        ?>
                                        <p style="margin:0 0 0.2rem 0;font-size:0.92rem">
                                            Invoices: <?= e($invBill !== '' ? $invBill : '—') ?>,
                                            Date: <?= e(crm_format_date_dd_mmm_yy($invDate)) ?>,
                                            Amount:
                                            <span<?= $isPaid ? ' style="color:#16a34a;font-weight:600"' : '' ?>>Rs. <?= e($invPaid) ?></span>
                                        </p>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <form method="post" action="crm.php?<?= e(http_build_query(array_merge($listFilterParams, ['id' => (int) ($detailRow['id'] ?? 0)])) ) ?>" style="margin-top:0.55rem">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="crm_id" value="<?= (int) ($detailRow['id'] ?? 0) ?>">
                        <div class="form__row" style="margin-bottom:0.75rem">
                            <label for="crm_status">Status</label>
                            <select id="crm_status" name="crm_status">
                                <?php foreach ($statusOptions as $sopt): ?>
                                    <option value="<?= (int) ($sopt['id'] ?? 0) ?>" data-status-key="<?= e((string) ($sopt['key'] ?? '')) ?>"<?= $currStatusId === (int) ($sopt['id'] ?? 0) ? ' selected' : '' ?>><?= e((string) ($sopt['label'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form__row" id="crm_followup_wrap" style="margin-bottom:0.75rem;<?= $currStatusKey === 'follow_up' ? '' : 'display:none;' ?>">
                            <label for="followup_datetime">Follow-up date/time</label>
                            <input type="datetime-local" id="followup_datetime" name="followup_datetime" value="<?= e(crm_format_utc_to_datetime_local_input((string) ($detailRow['followup_datetime'] ?? ''))) ?>">
                        </div>
                        <div class="form__row" style="margin-bottom:0.75rem">
                            <label for="remarks">Remarks <span class="required-mark" aria-hidden="true">*</span></label>
                            <textarea id="remarks" name="remarks" rows="3" maxlength="500" required><?= e((string) ($detailRow['remarks'] ?? '')) ?></textarea>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem 1rem">
                            <button class="btn btn--primary" type="submit" name="save_crm" value="1">Save</button>
                            <a class="btn btn--ghost" href="crm.php?<?= e(http_build_query($listFilterParams)) ?>">Back</a>
                        </div>
                    </form>
                </div>
                <script>
                (function () {
                    var statusEl = document.getElementById('crm_status');
                    var followupWrap = document.getElementById('crm_followup_wrap');
                    if (!statusEl || !followupWrap) return;
                    function selectedStatusKey() {
                        var opt = statusEl.options[statusEl.selectedIndex];
                        return opt ? String(opt.getAttribute('data-status-key') || '').toLowerCase() : '';
                    }
                    function sync() {
                        followupWrap.style.display = selectedStatusKey() === 'follow_up' ? '' : 'none';
                    }
                    statusEl.addEventListener('change', sync);
                    sync();
                })();
                </script>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($isAdminRole): ?>
                <form method="get" action="crm.php" class="leads-filters">
                    <div class="form__row">
                        <label for="f_branch">Branch</label>
                        <select id="f_branch" name="f_branch">
                            <option value="0">Select branch</option>
                            <?php foreach ($branchOptions as $bId => $bLabel): ?>
                                <option value="<?= (int) $bId ?>"<?= $fBranchSel === (int) $bId ? ' selected' : '' ?>><?= e($bLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form__row">
                        <label for="f_status">Status</label>
                        <select id="f_status" name="f_status">
                            <option value="0">All</option>
                            <?php foreach ($statusOptions as $sopt): ?>
                                <option value="<?= (int) ($sopt['id'] ?? 0) ?>"<?= $fStatusSel === (int) ($sopt['id'] ?? 0) ? ' selected' : '' ?>><?= e((string) ($sopt['label'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form__row" id="crm_followup_range_filter_wrap" style="<?= ($fStatusSel > 0 && $followUpFilterStatusId !== null && $fStatusSel === $followUpFilterStatusId) ? '' : 'display:none;' ?>">
                        <label for="f_followup_range">Follow-up date range</label>
                        <select id="f_followup_range" name="f_followup_range">
                            <option value="">All</option>
                            <?php foreach ($followupRangeOptions as $frKey => $frLabel): ?>
                                <option value="<?= e($frKey) ?>"<?= $fFollowupRange === $frKey ? ' selected' : '' ?>><?= e($frLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex;align-items:flex-end;gap:0.85rem;margin-left:auto;min-width:260px;justify-content:space-between">
                        <button type="submit" name="show" value="1" class="btn btn--primary">Show</button>
                        <?php if ($showTotalRecordsLabel): ?>
                            <span style="font-size:.9rem;color:var(--muted, #64748b);white-space:nowrap">Total Records: <?= (int) $listTotal ?></span>
                        <?php endif; ?>
                    </div>
                </form>
            <?php else: ?>
                <form method="get" action="crm.php" class="leads-filters">
                    <div class="form__row">
                        <label for="f_status">Status</label>
                        <select id="f_status" name="f_status">
                            <option value="0">All</option>
                            <?php foreach ($statusOptions as $sopt): ?>
                                <option value="<?= (int) ($sopt['id'] ?? 0) ?>"<?= $fStatusSel === (int) ($sopt['id'] ?? 0) ? ' selected' : '' ?>><?= e((string) ($sopt['label'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form__row" id="crm_followup_range_filter_wrap" style="<?= ($fStatusSel > 0 && $followUpFilterStatusId !== null && $fStatusSel === $followUpFilterStatusId) ? '' : 'display:none;' ?>">
                        <label for="f_followup_range">Follow-up date range</label>
                        <select id="f_followup_range" name="f_followup_range">
                            <option value="">All</option>
                            <?php foreach ($followupRangeOptions as $frKey => $frLabel): ?>
                                <option value="<?= e($frKey) ?>"<?= $fFollowupRange === $frKey ? ' selected' : '' ?>><?= e($frLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex;align-items:flex-end;gap:0.85rem;margin-left:auto;min-width:260px;justify-content:space-between">
                        <button type="submit" name="show" value="1" class="btn btn--primary">Show</button>
                        <?php if ($showTotalRecordsLabel): ?>
                            <span style="font-size:.9rem;color:var(--muted, #64748b);white-space:nowrap">Total Records: <?= (int) $listTotal ?></span>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
            <?php if ($detailId <= 0): ?>
                <script>
                (function () {
                    var statusEl = document.getElementById('f_status');
                    var wrap = document.getElementById('crm_followup_range_filter_wrap');
                    var rangeEl = document.getElementById('f_followup_range');
                    var followUpStatusId = <?= (int) ($followUpFilterStatusId ?? 0) ?>;
                    if (!statusEl || !wrap || !rangeEl || followUpStatusId <= 0) return;
                    function syncFollowupRangeFilter() {
                        var selected = parseInt(statusEl.value || '0', 10);
                        var show = selected === followUpStatusId;
                        wrap.style.display = show ? '' : 'none';
                        if (!show) {
                            rangeEl.value = '';
                        }
                    }
                    statusEl.addEventListener('change', syncFollowupRangeFilter);
                    syncFollowupRangeFilter();
                })();
                </script>
            <?php endif; ?>
            <?php if ($isAdminRole && !$showRequested): ?>
                <p class="empty">Select a branch and click Show to load CRM clients.</p>
            <?php elseif ($isAdminRole && $showRequested && $fBranchSel <= 0): ?>
                <p class="empty">Please select a branch and click Show.</p>
            <?php elseif ($rows === []): ?>
                <p class="empty">No CRM clients found.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th><a class="link--underlined" href="<?= e($nameSortUrl) ?>">Name<?= e($nameArrow) ?></a></th>
                                <th>Number</th>
                                <th><a class="link--underlined" href="<?= e($visitSortUrl) ?>">Last Visit<?= e($visitArrow) ?></a></th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                $sid = (int) ($r['crm_status'] ?? 0);
                                $slabel = $statusIdToLabel[$sid] ?? ($sid > 0 ? 'Status #' . $sid : '—');
                            ?>
                                <tr>
                                    <td><a class="link--underlined" href="crm.php?<?= e(http_build_query(array_merge($listFilterParams, ['id' => (int) ($r['id'] ?? 0)]))) ?>"><?= e((string) ($r['fname'] ?? '')) ?></a></td>
                                    <td><?= e((string) ($r['mobile'] ?? '')) ?></td>
                                    <td><?= e(crm_format_last_visit((string) ($r['last_visit'] ?? ''))) ?></td>
                                    <td><?= e($slabel) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($listTotal > $listPerPage): ?>
                    <nav class="leads-pagination" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem 0.85rem;padding:1rem 1.25rem 1.15rem;margin:0;justify-content:center">
                        <?php if ($listPage > 1): ?>
                            <a class="btn btn--ghost" href="crm.php?<?= e(http_build_query(array_merge($listFilterParams, ['page' => $listPage - 1]))) ?>">Previous</a>
                        <?php endif; ?>
                        <span style="font-size:.9rem;color:var(--muted, #64748b)">Page <?= (int) $listPage ?> of <?= (int) $listTotalPages ?></span>
                        <?php if ($listPage < $listTotalPages): ?>
                            <a class="btn btn--ghost" href="crm.php?<?= e(http_build_query(array_merge($listFilterParams, ['page' => $listPage + 1]))) ?>">Next</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
