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

function leads_format_date_ist_dm(?string $utcDateTime): string
{
    $raw = trim((string) ($utcDateTime ?? ''));
    if ($raw === '') {
        return '—';
    }
    try {
        $dt = new DateTime($raw, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));

        return $dt->format('d-M-y h:i');
    } catch (Exception $e) {
        return $raw;
    }
}

/** IST date for mobile list cells: dd-MMM (short month label, no time). */
function leads_format_date_ist_dd_mmm(?string $utcDateTime): string
{
    $raw = trim((string) ($utcDateTime ?? ''));
    if ($raw === '') {
        return '—';
    }
    try {
        $dt = new DateTime($raw, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));

        return $dt->format('d-M');
    } catch (Exception $e) {
        return $raw;
    }
}

function leads_format_datetime_ist_full(?string $utcDateTime): string
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

function leads_parse_datetime_local_to_mysql_utc(string $value): ?string
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

function leads_format_utc_to_datetime_local_input(?string $utcDateTime): string
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

/** Normalise DECIMAL string from aggregates (strip trailing fractional zeros). */
function leads_format_aggregate_amount_scalar(string|int|float|null $value): string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return '0';
    }
    if (str_contains($raw, '.')) {
        $trim = rtrim(rtrim($raw, '0'), '.');

        return $trim === '' ? '0' : $trim;
    }

    return $raw;
}

/** Integer digits for lead detail Amount input (matches POST validation and pattern="[0-9]{1,20}"). */
function leads_amount_detail_input_string(?string $dbAmount): string
{
    $raw = trim((string) ($dbAmount ?? ''));
    if ($raw === '') {
        return '';
    }
    $whole = strstr($raw, '.', true);
    if ($whole === false) {
        $whole = $raw;
    }
    $digits = preg_replace('/\D+/', '', $whole);
    if ($digits === '') {
        return '';
    }
    $digits = ltrim($digits, '0');
    $digits = $digits === '' ? '0' : $digits;
    if (strlen($digits) > 20) {
        return substr($digits, 0, 20);
    }

    return $digits;
}

function leads_whatsapp_chat_url(?string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', (string) ($phone ?? ''));
    if ($digits === '') {
        return null;
    }
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    }
    if (strlen($digits) < 10) {
        return null;
    }

    return 'https://wa.me/' . $digits;
}

/**
 * @return array{where:string,params:array<string,mixed>}
 */
function leads_scope_clause(bool $isBranchScopedRole, ?int $branchId): array
{
    if (!$isBranchScopedRole) {
        return ['where' => '', 'params' => []];
    }
    if ($branchId === null) {
        return ['where' => ' WHERE 1=0', 'params' => []];
    }

    return ['where' => ' WHERE branch_id = :branch_id', 'params' => ['branch_id' => $branchId]];
}

/**
 * IST calendar range for follow-up filter presets; returns inclusive UTC bounds for DB comparison.
 *
 * @return array{0:string,1:string}|null UTC 'Y-m-d H:i:s' start/end, or null when no effective filter.
 */
function leads_followup_boundaries_utc(string $preset, string $customYmd): ?array
{
    $allowed = ['today', 'tomorrow', 'week', 'month', 'custom'];
    if (!in_array($preset, $allowed, true)) {
        return null;
    }

    $tz = new DateTimeZone('Asia/Kolkata');
    $utc = new DateTimeZone('UTC');
    $now = new DateTime('now', $tz);

    if ($preset === 'custom') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $customYmd)) {
            return null;
        }
        try {
            $start = new DateTime($customYmd . ' 00:00:00', $tz);
            $end = new DateTime($customYmd . ' 23:59:59', $tz);
        } catch (Exception $e) {
            return null;
        }
    } elseif ($preset === 'today') {
        $start = (clone $now)->setTime(0, 0, 0);
        $end = (clone $now)->setTime(23, 59, 59);
    } elseif ($preset === 'tomorrow') {
        $start = (clone $now)->modify('+1 day')->setTime(0, 0, 0);
        $end = (clone $start)->setTime(23, 59, 59);
    } elseif ($preset === 'week') {
        $dow = (int) $now->format('N');
        $start = (clone $now)->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
        $end = (clone $start)->modify('+6 days')->setTime(23, 59, 59);
    } else {
        $start = new DateTime($now->format('Y-m-01') . ' 00:00:00', $tz);
        $end = new DateTime($now->format('Y-m-t') . ' 23:59:59', $tz);
    }

    $start->setTimezone($utc);
    $end->setTimezone($utc);

    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

/** Qualify branch_id in scope WHERE for queries using alias `ml`. */
function leads_scope_where_ml(string $scopeWhere): string
{
    if ($scopeWhere === '') {
        return '';
    }

    return str_replace('branch_id', 'ml.branch_id', $scopeWhere);
}

const META_LEADS_TABLE_SQL = '`allureone_meta_leads`';

/**
 * lowercase column key => exact column name on server (for quoting).
 *
 * @return array<string, string>|null
 */
function leads_meta_leads_column_map(): ?array
{
    static $loaded = false;
    static $map = null;
    if ($loaded) {
        return $map;
    }
    $loaded = true;
    try {
        $pdo = db();
        $stmt = $pdo->query('SHOW COLUMNS FROM ' . META_LEADS_TABLE_SQL);
        if (!$stmt instanceof PDOStatement) {
            return null;
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $f = isset($row['Field']) ? (string) $row['Field'] : '';
            if ($f !== '') {
                $out[strtolower($f)] = $f;
            }
        }

        return $map = ($out !== [] ? $out : null);
    } catch (Throwable $e) {
        error_log('AllureOne leads SHOW COLUMNS ' . META_LEADS_TABLE_SQL . ': ' . $e->getMessage());

        return $map = null;
    }
}

/**
 * @param array<string, string> $map
 *
 * @return string|null Fragment like ml.`ActualCol`
 */
function leads_ml_qualify_ml(array $map, array $preferNames): ?string
{
    foreach ($preferNames as $p) {
        $lk = strtolower($p);
        if (isset($map[$lk])) {
            return 'ml.`' . str_replace('`', '``', $map[$lk]) . '`';
        }
    }

    return null;
}

/**
 * Backtick identifier only (UPDATE ... SET uses no ml. alias).
 *
 * @param array<string, string> $map
 */
function leads_ml_ident_bare(array $map, array $preferNames): ?string
{
    foreach ($preferNames as $p) {
        $lk = strtolower($p);
        if (isset($map[$lk])) {
            return '`' . str_replace('`', '``', $map[$lk]) . '`';
        }
    }

    return null;
}

/**
 * @param array<string, string> $map
 *
 * @return array{sql:string,params:array<string,mixed>}
 */
function leads_ml_branch_scope_where(array $map, bool $isBranchScopedRole, ?int $branchId): array
{
    if (!$isBranchScopedRole) {
        return ['sql' => ' WHERE 1=1', 'params' => []];
    }
    if ($branchId === null || $branchId <= 0) {
        return ['sql' => ' WHERE 1=0', 'params' => []];
    }
    $b = leads_ml_qualify_ml($map, ['branch_id', 'BranchId']);
    if ($b === null) {
        return ['sql' => ' WHERE 1=0', 'params' => []];
    }

    return ['sql' => ' WHERE ' . $b . ' = :branch_id', 'params' => ['branch_id' => $branchId]];
}

$rows = [];
$detailRow = null;
$totalLeads = 0;
/**
 * Branch summary rows (admin). Includes amount_conv_raw (float, internal) for footer totals.
 *
 * @var list<array{location:string,count:int,converted:int,amount_converted:string,amount_conv_raw:float}> $leadsBranchSummary
 */
$leadsBranchSummary = [];
/** @var array{received:int,converted:int,amount_total:string}|null Branch-scoped list conversion row (role 3); null if not loaded. */
$leadsConversionSummary = null;
/** Set in data try: meta leads has a campaign column (Campaiign / campaign). */
$campaignFilterColumnAvailable = false;
/** @var array<string,string> Active branch choices for admin branch filter (id => label). */
$branchFilterOptions = [];
$loadError = '';
$flash = ['type' => '', 'text' => ''];
$branchId = isset($user['branch_id']) && (int) $user['branch_id'] > 0 ? (int) $user['branch_id'] : null;
$isBranchScopedRole = $roleId === 3;
/** Executive roles: dashboard-style branch summary only; no lead list/detail card or conversion summary card. */
$hideLeadsListAndConversionCards = false;
$detailId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($hideLeadsListAndConversionCards && $detailId > 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: leads.php', true, 302);
    exit;
}
$statusOptions = [];
$statusOptionIds = [];
$statusIdToKey = [];
$statusIdToLabel = [];

try {
    $statusStmt = db()->prepare(
        "SELECT id, status_key, status_label
         FROM allureone_leads_status
         WHERE is_active = 1
           AND applies_to IN ('all', 'meta')
         ORDER BY sort_order ASC, id ASC"
    );
    $statusStmt->execute();
    $statusRows = $statusStmt->fetchAll();
    foreach ($statusRows as $statusRow) {
        $sid = (int) ($statusRow['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $skey = trim((string) ($statusRow['status_key'] ?? ''));
        $slabel = trim((string) ($statusRow['status_label'] ?? ''));
        if ($slabel === '') {
            continue;
        }
        $statusOptions[] = ['id' => $sid, 'key' => $skey, 'label' => $slabel];
        $statusOptionIds[$sid] = true;
        $statusIdToKey[$sid] = $skey;
        $statusIdToLabel[$sid] = $slabel;
    }
} catch (PDOException $e) {
    error_log('AllureOne leads status master load failed: ' . $e->getMessage());
}

$followUpFilterStatusId = null;
foreach ($statusIdToKey as $sidKey => $k) {
    if ($k === 'follow_up') {
        $followUpFilterStatusId = (int) $sidKey;
        break;
    }
}

$listPerPage = 10;
$listPage = max(1, (int) ($_GET['page'] ?? 1));
$listTotalFiltered = 0;
$listTotalPages = 1;
$followupPresetAllowed = ['all', 'today', 'tomorrow', 'week', 'month', 'custom'];

if ($roleId === ROLE_SUPERADMIN || $roleId === ROLE_ADMIN) {
    try {
        $branchFilterStmt = db()->query(
            'SELECT id, locality, business_name
             FROM allureone_branch
             WHERE isActive = 1
             ORDER BY locality ASC, business_name ASC, id ASC'
        );
        foreach ($branchFilterStmt->fetchAll(PDO::FETCH_ASSOC) as $bRow) {
            $bId = (int) ($bRow['id'] ?? 0);
            if ($bId <= 0) {
                continue;
            }
            $label = trim((string) ($bRow['locality'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($bRow['business_name'] ?? ''));
            }
            if ($label === '') {
                $label = 'Branch #' . $bId;
            }
            $branchFilterOptions[(string) $bId] = $label;
        }
    } catch (Throwable $e) {
        error_log('AllureOne leads branch filter options load failed: ' . $e->getMessage());
    }
}

$fStatusSel = isset($_GET['f_status']) ? trim((string) $_GET['f_status']) : 'all';
if ($fStatusSel === '') {
    $fStatusSel = 'all';
}
if ($fStatusSel !== 'all') {
    $tmpSid = (int) $fStatusSel;
    if ($tmpSid <= 0 || !isset($statusOptionIds[$tmpSid])) {
        $fStatusSel = 'all';
    }
}

$fFuSel = isset($_GET['f_fu']) ? strtolower(trim((string) $_GET['f_fu'])) : 'all';
if (!in_array($fFuSel, $followupPresetAllowed, true)) {
    $fFuSel = 'all';
}

$fFuDateSel = isset($_GET['f_fu_date']) ? trim((string) $_GET['f_fu_date']) : '';
if ($fFuSel !== 'custom') {
    $fFuDateSel = '';
}

$statusIsFollowUpForFilter =
    $followUpFilterStatusId !== null
    && $followUpFilterStatusId > 0
    && $fStatusSel !== 'all'
    && (int) $fStatusSel === $followUpFilterStatusId;

if (!$statusIsFollowUpForFilter) {
    $fFuSel = 'all';
    $fFuDateSel = '';
}

/** GET `f_campaign` slug => exact value stored in meta leads campaign column (see Meta/index.php inserts). */
$leadsCampaignFilterDbValue = [
    'mothers_day' => 'Mothers Day Campaign',
    'fathers_day' => 'Fathers Day Campaign',
];
$leadsCampaignDefault = 'fathers_day';
$fCampaignSel = isset($_GET['f_campaign']) ? trim((string) $_GET['f_campaign']) : $leadsCampaignDefault;
if ($fCampaignSel === '') {
    $fCampaignSel = $leadsCampaignDefault;
}
if ($fCampaignSel !== 'all' && !isset($leadsCampaignFilterDbValue[$fCampaignSel])) {
    $fCampaignSel = $leadsCampaignDefault;
}
$fBranchSel = isset($_GET['f_branch']) ? trim((string) $_GET['f_branch']) : 'all';
if ($fBranchSel === '') {
    $fBranchSel = 'all';
}
if ($fBranchSel !== 'all' && !isset($branchFilterOptions[$fBranchSel])) {
    $fBranchSel = 'all';
}
$leadsSummaryCollapsedByPageNav = isset($_GET['ls_collapsed']) && (string) $_GET['ls_collapsed'] === '1';

$listFilterParams = ['f_status' => $fStatusSel];
if ($statusIsFollowUpForFilter) {
    $listFilterParams['f_fu'] = $fFuSel;
    if ($fFuSel === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFuDateSel) === 1) {
        $listFilterParams['f_fu_date'] = $fFuDateSel;
    }
}
if ($fCampaignSel !== 'all') {
    $listFilterParams['f_campaign'] = $fCampaignSel;
}
if ($fBranchSel !== 'all') {
    $listFilterParams['f_branch'] = $fBranchSel;
}

$metaLeadCols = leads_meta_leads_column_map();
if ($metaLeadCols === null && $loadError === '') {
    $loadError = 'Could not load leads data. The table allureone_meta_leads was not found (or is not readable). Confirm it exists in the database from config.php and check php_errors.log.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_lead'])) {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Invalid session. Please refresh and try again.'];
    } else {
        $saveId = isset($_POST['lead_id']) ? (int) $_POST['lead_id'] : 0;
        $status = isset($_POST['status']) ? (int) $_POST['status'] : 0;
        $statusKey = $statusIdToKey[$status] ?? '';
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        $amountRaw = trim((string) ($_POST['amount'] ?? ''));
        $followupInput = trim((string) ($_POST['followup_datetime'] ?? ''));
        $followupUtc = null;
        $amountValue = null;

        if ($saveId <= 0) {
            $flash = ['type' => 'error', 'text' => 'Invalid lead selected.'];
        } elseif ($status <= 0 || !isset($statusOptionIds[$status])) {
            $flash = ['type' => 'error', 'text' => 'Please select a valid status.'];
        } elseif ($remarks === '') {
            $flash = ['type' => 'error', 'text' => 'Remarks is required.'];
        } elseif ((function_exists('mb_strlen') ? mb_strlen($remarks) : strlen($remarks)) > 100) {
            $flash = ['type' => 'error', 'text' => 'Remarks can be maximum 100 characters.'];
        } else {
            if ($statusKey === 'follow_up') {
                $followupUtc = leads_parse_datetime_local_to_mysql_utc($followupInput);
                if ($followupUtc === null) {
                    $flash = ['type' => 'error', 'text' => 'Please select valid Follow Up date/time.'];
                }
            }
            if ($statusKey === 'converted') {
                if ($amountRaw === '' || preg_match('/^\d{1,20}$/', $amountRaw) !== 1) {
                    $flash = ['type' => 'error', 'text' => 'Please enter valid integer Amount (max 20 digits).'];
                } else {
                    $amountValue = $amountRaw;
                }
            }
        }

        if ($flash['text'] === '') {
            if (!is_array($metaLeadCols)) {
                $flash = ['type' => 'error', 'text' => 'Leads table is not available. Cannot save.'];
                $detailId = $saveId;
            } else {
            try {
                $iId = leads_ml_ident_bare($metaLeadCols, ['id']);
                $iStatus = leads_ml_ident_bare($metaLeadCols, ['status']);
                $iRemarks = leads_ml_ident_bare($metaLeadCols, ['remarks']);
                $iFollowup = leads_ml_ident_bare($metaLeadCols, ['followup_datetime', 'Followup_Datetime']);
                $iAmount = leads_ml_ident_bare($metaLeadCols, ['amount']);
                $iUpdated = leads_ml_ident_bare($metaLeadCols, ['updated_at', 'Updated_at']);
                $iBranch = leads_ml_ident_bare($metaLeadCols, ['branch_id', 'BranchId']);
                if ($iId === null || $iStatus === null || $iRemarks === null) {
                    throw new RuntimeException('allureone_meta_leads missing id/status/remarks column');
                }
                $setParts = [
                    $iStatus . ' = :status',
                    $iRemarks . ' = :remarks',
                ];
                $params = [
                    'status' => $status,
                    'remarks' => $remarks,
                    'id' => $saveId,
                ];
                if ($iFollowup !== null) {
                    $setParts[] = $iFollowup . ' = :followup_datetime';
                    $params['followup_datetime'] = $statusKey === 'follow_up' ? $followupUtc : null;
                } elseif ($statusKey === 'follow_up') {
                    throw new RuntimeException('followup_datetime column missing on allureone_meta_leads');
                }
                if ($iAmount !== null) {
                    $setParts[] = $iAmount . ' = :amount';
                    $params['amount'] = $statusKey === 'converted' ? $amountValue : null;
                }
                if ($iUpdated !== null) {
                    $setParts[] = $iUpdated . ' = NOW()';
                }
                $sql = 'UPDATE ' . META_LEADS_TABLE_SQL . ' SET ' . implode(', ', $setParts) . ' WHERE ' . $iId . ' = :id';
                if ($isBranchScopedRole) {
                    if ($branchId === null || $branchId <= 0 || $iBranch === null) {
                        $sql .= ' AND 0=1';
                    } else {
                        $sql .= ' AND ' . $iBranch . ' = :branch_id';
                        $params['branch_id'] = $branchId;
                    }
                }
                $st = db()->prepare($sql);
                $st->execute($params);
                if ($st->rowCount() > 0) {
                    $flash = ['type' => 'ok', 'text' => 'Lead updated successfully.'];
                } else {
                    $flash = ['type' => 'error', 'text' => 'Lead not updated. It may not be in your scope or data is unchanged.'];
                }
                $detailId = $saveId;
            } catch (Throwable $e) {
                error_log('AllureOne leads save failed: ' . $e->getMessage());
                $flash = ['type' => 'error', 'text' => 'Could not save lead details.'];
                $detailId = $saveId;
            }
            }
        } else {
            $detailId = $saveId;
        }
    }
}

if ($metaLeadCols === null || $metaLeadCols === []) {
    if ($loadError === '') {
        $loadError = 'Could not load leads data.';
    }
} else {
try {
    $map = $metaLeadCols;
    $branchScope = leads_ml_branch_scope_where($map, $isBranchScopedRole, $branchId);

    $qId = leads_ml_qualify_ml($map, ['id']);
    $qLeadName = leads_ml_qualify_ml($map, ['lead_name']);
    $qPhone = leads_ml_qualify_ml($map, ['lead_phone_number', 'Lead_Phone_Number', 'phone_number']);
    $qBranchName = leads_ml_qualify_ml($map, ['branch_name']);
    $qStatus = leads_ml_qualify_ml($map, ['status']);
    $qCreated = leads_ml_qualify_ml($map, ['Created_Datetime', 'created_datetime', 'DateTime']);
    $qFollowupCol = leads_ml_qualify_ml($map, ['followup_datetime', 'Followup_Datetime']);
    $qCampaignCol = leads_ml_qualify_ml($map, ['Campaiign', 'Campaign', 'campaign']);
    $campaignFilterColumnAvailable = ($qCampaignCol !== null);

    foreach (['id' => $qId, 'lead_name' => $qLeadName, 'lead_phone_number' => $qPhone, 'status' => $qStatus] as $needKey => $q) {
        if ($q === null) {
            throw new RuntimeException('Missing required leads column mapping for ' . $needKey);
        }
    }
    $baseWhereMl = $branchScope['sql'];
    $branchBind = $branchScope['params'];

    $listFilterSql = '';
    $listFilterBind = [];
    if ($fStatusSel !== 'all' && $qStatus !== null) {
        $listFilterSql .= ' AND ' . $qStatus . ' = :list_f_status';
        $listFilterBind['list_f_status'] = (int) $fStatusSel;
    }
    if ($statusIsFollowUpForFilter && $fFuSel !== 'all') {
        $fuBounds = leads_followup_boundaries_utc($fFuSel, $fFuDateSel);
        if ($fuBounds !== null && $qFollowupCol !== null) {
            $listFilterSql .= ' AND ' . $qFollowupCol . ' IS NOT NULL AND ' . $qFollowupCol . ' >= :list_fu_a AND ' . $qFollowupCol . ' <= :list_fu_b';
            $listFilterBind['list_fu_a'] = $fuBounds[0];
            $listFilterBind['list_fu_b'] = $fuBounds[1];
        }
    }
    if ($fCampaignSel !== 'all' && $qCampaignCol !== null) {
        $dbCampFilter = $leadsCampaignFilterDbValue[$fCampaignSel] ?? '';
        if ($dbCampFilter !== '') {
            $listFilterSql .= ' AND TRIM(IFNULL(' . $qCampaignCol . ', \'\')) = :list_f_campaign';
            $listFilterBind['list_f_campaign'] = $dbCampFilter;
        }
    }

    $leadsConvertedStatusBindId = -1;
    foreach ($statusIdToKey as $_convSidBind => $_convKeyBind) {
        if ($_convKeyBind === 'converted') {
            $leadsConvertedStatusBindId = (int) $_convSidBind;
            break;
        }
    }
    if ($leadsConvertedStatusBindId <= 0) {
        $leadsConvertedStatusBindId = -1;
    }

    $qMlBranchId = leads_ml_qualify_ml($map, ['branch_id', 'BranchId']);
    if (($roleId === ROLE_SUPERADMIN || $roleId === ROLE_ADMIN) && $fBranchSel !== 'all' && $qMlBranchId !== null) {
        $listFilterSql .= ' AND ' . $qMlBranchId . ' = :list_f_branch';
        $listFilterBind['list_f_branch'] = (int) $fBranchSel;
    }
    if (($roleId === ROLE_SUPERADMIN || $roleId === ROLE_ADMIN) && $qMlBranchId !== null && $detailId <= 0) {
        try {
            // locality from branch master via branch_id; per-branch converted count / converted amount totals.
            $qAmtBranch = leads_ml_qualify_ml($map, ['amount']);
            $convAmtSelectBr = '0 AS conv_amt_sum';
            if ($qAmtBranch !== null) {
                $convAmtSelectBr = 'SUM(CASE WHEN ' . $qStatus . ' = :br_summ_conv_a THEN CAST(TRIM(IFNULL(CAST(' . $qAmtBranch . ' AS CHAR), \'\')) AS DECIMAL(22, 4)) ELSE 0 END) AS conv_amt_sum';
            }
            $aggSql = 'SELECT MAX(TRIM(IFNULL(br.`locality`,\'\'))) AS bn, COUNT(*) AS cnt, '
                . 'SUM(CASE WHEN ' . $qStatus . ' = :br_summ_conv_c THEN 1 ELSE 0 END) AS conv_cnt_branch, '
                . $convAmtSelectBr . ' '
                . 'FROM ' . META_LEADS_TABLE_SQL . ' ml'
                . ' LEFT JOIN `allureone_branch` br ON br.`id` = ' . $qMlBranchId
                . $baseWhereMl . $listFilterSql
                . ' GROUP BY ' . $qMlBranchId
                . ' ORDER BY cnt DESC';
            $aggBind = array_merge($branchBind, $listFilterBind, ['br_summ_conv_c' => $leadsConvertedStatusBindId]);
            if ($qAmtBranch !== null) {
                $aggBind['br_summ_conv_a'] = $leadsConvertedStatusBindId;
            }
            $aggStmt = db()->prepare($aggSql);
            $aggStmt->execute($aggBind);
            while ($agr = $aggStmt->fetch(PDO::FETCH_ASSOC)) {
                $bn = isset($agr['bn']) ? trim((string) $agr['bn']) : '';
                $leadsBranchSummary[] = [
                    'location' => $bn !== '' ? $bn : '—',
                    'count' => (int) ($agr['cnt'] ?? 0),
                    'converted' => (int) ($agr['conv_cnt_branch'] ?? 0),
                    'amount_converted' => leads_format_aggregate_amount_scalar($agr['conv_amt_sum'] ?? '0'),
                    // Footers sum this; not displayed directly (avoids float drift aggregating formatted strings).
                    'amount_conv_raw' => (float) ($agr['conv_amt_sum'] ?? 0),
                ];
            }
        } catch (Throwable $aggE) {
            error_log('AllureOne leads branch summary failed: ' . $aggE->getMessage());
        }
    }

    if ($roleId === 3 && $detailId <= 0) {
        try {
            $convSummBindSid = $leadsConvertedStatusBindId;
            $qAmtForConv = leads_ml_qualify_ml($map, ['amount']);
            $amtSumSelect = '0 AS amount_total';
            // Native PDO (ATTR_EMULATE_PREPARES false) does not allow reusing the same named placeholder twice.
            if ($qAmtForConv !== null) {
                $amtSumSelect = 'SUM(CASE WHEN ' . $qStatus . ' = :conv_summ_a THEN CAST(TRIM(IFNULL(CAST(' . $qAmtForConv . ' AS CHAR), \'\')) AS DECIMAL(22, 4)) ELSE 0 END) AS amount_total';
            }
            $convSummSql = 'SELECT COUNT(*) AS received, '
                . 'SUM(CASE WHEN ' . $qStatus . ' = :conv_summ_c THEN 1 ELSE 0 END) AS converted_cnt, '
                . $amtSumSelect
                . ' FROM ' . META_LEADS_TABLE_SQL . ' ml'
                . $baseWhereMl . $listFilterSql;
            $convSummStmt = db()->prepare($convSummSql);
            $convSummBind = ['conv_summ_c' => $convSummBindSid];
            if ($qAmtForConv !== null) {
                $convSummBind['conv_summ_a'] = $convSummBindSid;
            }
            $convSummStmt->execute(array_merge($branchBind, $listFilterBind, $convSummBind));
            $convSummRow = $convSummStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($convSummRow)) {
                $leadsConversionSummary = [
                    'received' => (int) ($convSummRow['received'] ?? 0),
                    'converted' => (int) ($convSummRow['converted_cnt'] ?? 0),
                    'amount_total' => leads_format_aggregate_amount_scalar($convSummRow['amount_total'] ?? '0'),
                ];
            }
        } catch (Throwable $convE) {
            error_log('AllureOne leads conversion summary failed: ' . $convE->getMessage());
        }
    }

    if ($detailId > 0) {
        if ($hideLeadsListAndConversionCards) {
            $detailRow = null;
            $totalLeads = 0;
        } else {
        $qSrc = leads_ml_qualify_ml($map, ['sourceName', 'SourceName']);
        $qCamp = $qCampaignCol;
        $qRemarks = leads_ml_qualify_ml($map, ['remarks']);
        $qAmt = leads_ml_qualify_ml($map, ['amount']);

        $detailPieces = [$qId . ' AS id'];
        foreach (
            [
                [$qLeadName, 'lead_name'],
                [$qPhone, 'lead_phone_number'],
                [$qBranchName, 'branch_name'],
                [$qSrc, 'sourceName'],
                [$qCamp, 'Campaiign'],
                [$qCreated, 'Created_Datetime'],
                [$qStatus, 'status'],
                [$qRemarks, 'remarks'],
                [$qAmt, 'amount'],
                [$qFollowupCol, 'followup_datetime'],
            ] as [$expr, $alias]
        ) {
            if ($expr !== null) {
                $detailPieces[] = $expr . ' AS `' . str_replace('`', '``', $alias) . '`';
            }
        }
        $detailSql = 'SELECT ' . implode(', ', $detailPieces) . '
                      FROM ' . META_LEADS_TABLE_SQL . ' ml
                      WHERE ' . $qId . ' = :id';
        if ($isBranchScopedRole) {
            $bqBranch = leads_ml_qualify_ml($map, ['branch_id', 'BranchId']);
            if ($branchId === null || $branchId <= 0 || $bqBranch === null) {
                $detailSql .= ' AND 0=1';
            } else {
                $detailSql .= ' AND ' . $bqBranch . ' = :branch_id';
            }
        }
        $detailSql .= ' LIMIT 1';
        $detailStmt = db()->prepare($detailSql);
        $detailParams = ['id' => $detailId];
        if ($branchBind !== []) {
            $detailParams = array_merge($detailParams, $branchBind);
        }
        $detailStmt->execute($detailParams);
        $detailRow = $detailStmt->fetch() ?: null;
        if (is_array($detailRow)) {
            $dsid = (int) ($detailRow['status'] ?? 0);
            $detailRow['status_label'] = $statusIdToLabel[$dsid] ?? ($dsid > 0 ? 'Status #' . $dsid : '—');
            $detailRow['status_key'] = $statusIdToKey[$dsid] ?? '';
        }

        $countAllSql = 'SELECT COUNT(*) FROM ' . META_LEADS_TABLE_SQL . ' ml' . $baseWhereMl;
        $countAllStmt = db()->prepare($countAllSql);
        $countAllStmt->execute($branchBind);
        $totalLeads = (int) ($countAllStmt->fetchColumn() ?: 0);
        }
    } elseif ($hideLeadsListAndConversionCards) {
        $listTotalFiltered = 0;
        $listTotalPages = 1;
        $totalLeads = 0;
        $rows = [];
    } else {
        $countSql = 'SELECT COUNT(*) FROM ' . META_LEADS_TABLE_SQL . ' ml' . $baseWhereMl . $listFilterSql;
        $countStmt = db()->prepare($countSql);
        $countStmt->execute(array_merge($branchBind, $listFilterBind));
        $listTotalFiltered = (int) ($countStmt->fetchColumn() ?: 0);
        $totalLeads = $listTotalFiltered;
        $listTotalPages = max(1, (int) ceil($listTotalFiltered / $listPerPage));
        $listPage = min($listPage, $listTotalPages);

        $offset = ($listPage - 1) * $listPerPage;
        $lim = $listPerPage;
        $orderBy = ($qCreated !== null) ? $qCreated : $qId;

        $listSel = [
            $qId . ' AS id',
            $qLeadName . ' AS lead_name',
            $qPhone . ' AS lead_phone_number',
        ];
        $listSel[] = ($qCreated !== null ? $qCreated . ' AS Created_Datetime' : 'NULL AS Created_Datetime');
        $listSel[] = ($qBranchName !== null ? $qBranchName . ' AS branch_name' : 'NULL AS branch_name');
        $listSel[] = $qStatus . ' AS status';

        $dataSql = 'SELECT ' . implode(', ', $listSel) . '
                    FROM ' . META_LEADS_TABLE_SQL . ' ml' . $baseWhereMl . $listFilterSql . '
                    ORDER BY ' . $orderBy . ' DESC, ' . $qId . ' DESC
                    LIMIT ' . $lim . ' OFFSET ' . $offset;
        $dataStmt = db()->prepare($dataSql);
        $dataStmt->execute(array_merge($branchBind, $listFilterBind));
        $rows = $dataStmt->fetchAll();
        foreach ($rows as $ir => $lr) {
            $rsid = (int) ($lr['status'] ?? 0);
            $rows[$ir]['status_label'] = $statusIdToLabel[$rsid] ?? ($rsid > 0 ? 'Status #' . $rsid : '—');
            $rows[$ir]['status_key'] = $statusIdToKey[$rsid] ?? '';
        }
    }
} catch (Throwable $e) {
    error_log('AllureOne leads page failed: ' . $e->getMessage() . ' [' . $e->getCode() . ']');
    $loadError = 'Could not load leads data.';
}
}

$pageTitle = 'Leads';
$activeNav = 'leads';
require __DIR__ . '/includes/layout_start.php';
?>

<?php if ($loadError !== '' && $hideLeadsListAndConversionCards): ?>
    <div class="card" style="margin-bottom:1.15rem">
        <div class="card__body" style="padding:1rem 1.25rem">
            <p class="alert alert--error" style="margin:0"><?= e($loadError) ?></p>
        </div>
    </div>
<?php endif; ?>

<?php if ($loadError === '' && $detailId <= 0 && ($roleId === ROLE_SUPERADMIN || $roleId === ROLE_ADMIN) && $leadsBranchSummary !== []): ?>
<details id="leads-summary-section" class="card leads-branch-summary-card"<?= $leadsSummaryCollapsedByPageNav ? '' : ' open' ?>>
    <summary class="card__head card__toggle">
        <span class="card__toggle-inner">
            <span>Leads Summary</span>
            <span class="card__chevron" aria-hidden="true">▼</span>
        </span>
    </summary>
    <div class="card__body leads-branch-summary-card__body">
        <?php if ($campaignFilterColumnAvailable): ?>
        <form method="get" action="leads.php" class="leads-filters leads-summary-filters">
            <input type="hidden" name="f_status" value="<?= e($fStatusSel) ?>">
            <?php if ($statusIsFollowUpForFilter): ?>
                <input type="hidden" name="f_fu" value="<?= e($fFuSel) ?>">
                <?php if ($fFuSel === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFuDateSel) === 1): ?>
                    <input type="hidden" name="f_fu_date" value="<?= e($fFuDateSel) ?>">
                <?php endif; ?>
            <?php endif; ?>
            <div class="form__row">
                <label for="f_campaign_summary">Campaign</label>
                <select id="f_campaign_summary" name="f_campaign">
                    <option value="all"<?= $fCampaignSel === 'all' ? ' selected' : '' ?>>All</option>
                    <?php foreach ($leadsCampaignFilterDbValue as $campSlug => $campLabel): ?>
                    <option value="<?= e($campSlug) ?>"<?= $fCampaignSel === $campSlug ? ' selected' : '' ?>><?= e($campLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn--primary">Apply</button>
        </form>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="data leads-summary-table">
                <thead>
                    <tr>
                        <th scope="col">Location</th>
                        <th scope="col">Leads count</th>
                        <th scope="col">Converted</th>
                        <th scope="col">Amount (converted) Rs.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $leadsSummaryTableTotal = 0;
                    $leadsSummaryConvertedTotal = 0;
                    $leadsSummaryAmountConvertedTotal = 0.0;
                    foreach ($leadsBranchSummary as $bsRow):
                        $leadsSummaryTableTotal += (int) ($bsRow['count'] ?? 0);
                        $leadsSummaryConvertedTotal += (int) ($bsRow['converted'] ?? 0);
                        $leadsSummaryAmountConvertedTotal += (float) ($bsRow['amount_conv_raw'] ?? 0);
                        ?>
                        <tr>
                            <td class="leads-summary-table__location"><?= e((string) ($bsRow['location'] ?? '—')) ?></td>
                            <td class="leads-summary-table__count"><?= (int) ($bsRow['count'] ?? 0) ?></td>
                            <td class="leads-summary-table__conv"><?= (int) ($bsRow['converted'] ?? 0) ?></td>
                            <td class="leads-summary-table__amt"><?= e((string) ($bsRow['amount_converted'] ?? '0')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="leads-summary-table__total-row">
                        <th class="leads-summary-table__total-label" scope="row">Totals</th>
                        <td class="leads-summary-table__total-count"><?= $leadsSummaryTableTotal ?></td>
                        <td class="leads-summary-table__total-count"><?= $leadsSummaryConvertedTotal ?></td>
                        <td class="leads-summary-table__total-count"><?= e(leads_format_aggregate_amount_scalar(sprintf('%.4f', $leadsSummaryAmountConvertedTotal))) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="leads-branch-summary-note">Counts match current filters.</p>
    </div>
</details>
<?php endif; ?>

<?php if ($loadError === '' && $detailId <= 0 && $roleId === 3 && is_array($leadsConversionSummary)): ?>
<details id="leads-conversion-summary" class="card leads-conversion-summary-card" open>
    <summary class="card__head card__toggle">
        <span class="card__toggle-inner">
            <span>Conversion Summary</span>
            <span class="card__chevron" aria-hidden="true">▼</span>
        </span>
    </summary>
    <div class="card__body leads-branch-summary-card__body">
        <div class="table-wrap">
            <table class="data leads-conversion-summary-table">
                <thead>
                    <tr>
                        <th scope="col">Leads received</th>
                        <th scope="col">Leads converted</th>
                        <th scope="col">Total amount (Rs.)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="leads-conversion-summary-table__num"><?= (int) ($leadsConversionSummary['received'] ?? 0) ?></td>
                        <td class="leads-conversion-summary-table__num"><?= (int) ($leadsConversionSummary['converted'] ?? 0) ?></td>
                        <td class="leads-conversion-summary-table__num"><?= e((string) ($leadsConversionSummary['amount_total'] ?? '0')) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="leads-branch-summary-note">Figures match current filters.</p>
    </div>
</details>
<?php endif; ?>

<?php if (!$hideLeadsListAndConversionCards): ?>
<div id="leads-main-section" class="card">
    <div class="card__head">
        <span>Leads</span>
    </div>
    <div class="card__body">
        <?php if ($loadError !== ''): ?>
            <p class="alert alert--error" style="margin:1rem 1.25rem"><?= e($loadError) ?></p>
        <?php elseif ($detailId > 0): ?>
            <?php if ($flash['text'] !== ''): ?>
                <p class="alert alert--<?= $flash['type'] === 'ok' ? 'ok' : 'error' ?>" style="margin:1rem 1.25rem"><?= e($flash['text']) ?></p>
            <?php endif; ?>
            <?php if (!is_array($detailRow)): ?>
                <p class="empty">Lead not found.</p>
                <p style="padding:0 1.25rem 1.25rem;margin:0"><a class="btn btn--ghost" href="leads.php?<?= e(http_build_query($listFilterParams)) ?>">Back</a></p>
            <?php else: ?>
                <?php
                $detailPhone = (string) ($detailRow['lead_phone_number'] ?? '');
                $detailWa = leads_whatsapp_chat_url($detailPhone);
                $currentStatusId = (int) ($detailRow['status'] ?? 0);
                $currentStatusKey = (string) ($detailRow['status_key'] ?? ($statusIdToKey[$currentStatusId] ?? ''));
                ?>
                <div style="padding:1.25rem">
                    <table class="data">
                        <tbody>
                            <tr><th>Name</th><td><?= e((string) ($detailRow['lead_name'] ?? '')) ?></td></tr>
                            <tr>
                                <th>Number</th>
                                <td>
                                    <?php if ($detailWa !== null): ?>
                                        <a class="link--underlined" href="<?= e($detailWa) ?>" target="_blank" rel="noopener noreferrer"><?= e($detailPhone) ?></a>
                                    <?php elseif ($detailPhone !== ''): ?>
                                        <?= e($detailPhone) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr><th>Source</th><td><?= e((string) ($detailRow['sourceName'] ?? '')) ?></td></tr>
                            <tr><th>Campaign</th><td><?= e((string) ($detailRow['Campaiign'] ?? '')) ?></td></tr>
                            <tr><th>Status</th><td><?= e((string) ($detailRow['status_label'] ?? ($statusIdToLabel[$currentStatusId] ?? '—'))) ?></td></tr>
                            <tr><th>Lead Date</th><td><?= e(leads_format_datetime_ist_full((string) ($detailRow['Created_Datetime'] ?? ''))) ?></td></tr>
                        </tbody>
                    </table>
                    <form method="post" action="leads.php?id=<?= (int) ($detailRow['id'] ?? 0) ?>" style="margin-top:0.9rem">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="lead_id" value="<?= (int) ($detailRow['id'] ?? 0) ?>">
                        <div class="form__row" style="margin-bottom:0.75rem">
                            <label for="lead_status">Status</label>
                            <select id="lead_status" name="status">
                                <?php foreach ($statusOptions as $statusOpt): ?>
                                    <option value="<?= (int) ($statusOpt['id'] ?? 0) ?>" data-status-key="<?= e((string) ($statusOpt['key'] ?? '')) ?>"<?= $currentStatusId === (int) ($statusOpt['id'] ?? 0) ? ' selected' : '' ?>><?= e((string) ($statusOpt['label'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form__row" id="followup_wrap" style="margin-bottom:0.75rem;<?= $currentStatusKey === 'follow_up' ? '' : 'display:none;' ?>">
                            <label for="followup_datetime">Follow Up date/time</label>
                            <input type="datetime-local" id="followup_datetime" name="followup_datetime" value="<?= e(leads_format_utc_to_datetime_local_input((string) ($detailRow['followup_datetime'] ?? ''))) ?>">
                        </div>
                        <div class="form__row" id="amount_wrap" style="margin-bottom:0.75rem;<?= $currentStatusKey === 'converted' ? '' : 'display:none;' ?>">
                            <label for="amount">Amount (Rs.)</label>
                            <input type="text" id="amount" name="amount" maxlength="20" inputmode="numeric" pattern="[0-9]{1,20}"
                                   value="<?= e(leads_amount_detail_input_string(isset($detailRow['amount']) ? (string) $detailRow['amount'] : null)) ?>"
                                   <?= $currentStatusKey === 'converted' ? '' : 'disabled' ?>>
                        </div>
                        <div class="form__row" style="margin-bottom:0.75rem">
                            <label for="remarks">Remarks <span class="required-mark" aria-hidden="true">*</span></label>
                            <textarea id="remarks" name="remarks" rows="3" maxlength="100" required placeholder="Enter remarks (required, max 100 characters)"><?= e((string) ($detailRow['remarks'] ?? '')) ?></textarea>
                        </div>
                        <div class="leads-detail-actions" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem 1.25rem;margin-top:0.35rem">
                            <button type="submit" class="btn btn--primary" name="save_lead" value="1">Save</button>
                            <a class="btn btn--ghost" href="leads.php?<?= e(http_build_query($listFilterParams)) ?>">Back</a>
                        </div>
                    </form>
                </div>
                <script>
                (function () {
                    var statusEl = document.getElementById('lead_status');
                    var followupWrap = document.getElementById('followup_wrap');
                    var followupEl = document.getElementById('followup_datetime');
                    var amountWrap = document.getElementById('amount_wrap');
                    var amountEl = document.getElementById('amount');
                    if (!statusEl || !followupWrap || !amountWrap) return;

                    function selectedStatusKey() {
                        var selectedOpt = statusEl.options[statusEl.selectedIndex];
                        return selectedOpt ? String(selectedOpt.getAttribute('data-status-key') || '').toLowerCase() : '';
                    }

                    /** Tomorrow (Kolkata calendar) at 16:00 for backend + PHP (Asia/Kolkata). */
                    function followUpTomorrow4PmIstValue() {
                        var todayStr = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });
                        var tm = /^(\d{4})-(\d{2})-(\d{2})$/.exec(todayStr);
                        if (!tm) {
                            return '';
                        }
                        var y = parseInt(tm[1], 10), mo = parseInt(tm[2], 10), da = parseInt(tm[3], 10);
                        var next = new Date(Date.UTC(y, mo - 1, da + 1));
                        function pad2(n) {
                            return n < 10 ? '0' + n : String(n);
                        }
                        return next.getUTCFullYear() + '-' + pad2(next.getUTCMonth() + 1) + '-' + pad2(next.getUTCDate()) + 'T16:00';
                    }

                    var prevStatusKey = selectedStatusKey();

                    function sync(fromUserChange) {
                        var statusKey = selectedStatusKey();
                        if (fromUserChange && followupEl && statusKey === 'follow_up' && prevStatusKey !== 'follow_up') {
                            followupEl.value = followUpTomorrow4PmIstValue();
                        }
                        followupWrap.style.display = (statusKey === 'follow_up') ? '' : 'none';
                        var isConverted = (statusKey === 'converted');
                        amountWrap.style.display = isConverted ? '' : 'none';
                        if (amountEl) {
                            amountEl.disabled = !isConverted;
                        }
                        prevStatusKey = statusKey;
                    }

                    statusEl.addEventListener('change', function () {
                        sync(true);
                    });
                    sync(false);
                })();
                </script>
            <?php endif; ?>
        <?php else: ?>
            <p class="main__meta main__meta--mobile-visible leads-summary">Total leads received: <span class="leads-summary__count"><?= (int) $totalLeads ?></span></p>
            <form method="get" action="leads.php" class="leads-filters">
                <?php if (($roleId === ROLE_SUPERADMIN || $roleId === ROLE_ADMIN) && $branchFilterOptions !== []): ?>
                <div class="form__row">
                    <label for="f_branch">Branch</label>
                    <select id="f_branch" name="f_branch">
                        <option value="all"<?= $fBranchSel === 'all' ? ' selected' : '' ?>>All</option>
                        <?php foreach ($branchFilterOptions as $branchFilterId => $branchFilterLabel): ?>
                            <option value="<?= e((string) $branchFilterId) ?>"<?= $fBranchSel === (string) $branchFilterId ? ' selected' : '' ?>><?= e($branchFilterLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form__row">
                    <label for="f_status">Status</label>
                    <select id="f_status" name="f_status">
                        <option value="all"<?= $fStatusSel === 'all' ? ' selected' : '' ?>>All</option>
                        <?php foreach ($statusOptions as $stOpt): ?>
                            <option value="<?= (int) ($stOpt['id'] ?? 0) ?>"<?= $fStatusSel !== 'all' && (int) $fStatusSel === (int) ($stOpt['id'] ?? 0) ? ' selected' : '' ?>><?= e((string) ($stOpt['label'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($campaignFilterColumnAvailable): ?>
                <div class="form__row">
                    <label for="f_campaign">Campaign</label>
                    <select id="f_campaign" name="f_campaign">
                        <option value="all"<?= $fCampaignSel === 'all' ? ' selected' : '' ?>>All</option>
                        <?php foreach ($leadsCampaignFilterDbValue as $campSlug => $campLabel): ?>
                        <option value="<?= e($campSlug) ?>"<?= $fCampaignSel === $campSlug ? ' selected' : '' ?>><?= e($campLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($followUpFilterStatusId !== null && $followUpFilterStatusId > 0): ?>
                <div id="leads_filter_fu_wrap" class="leads-filter-fu-inner" style="display:<?= $statusIsFollowUpForFilter ? 'flex' : 'none' ?>;" data-follow-up-status-id="<?= (int) $followUpFilterStatusId ?>">
                    <div class="form__row">
                        <label for="f_fu">Follow-up date range</label>
                        <select id="f_fu" name="f_fu">
                            <option value="all"<?= $fFuSel === 'all' ? ' selected' : '' ?>>All</option>
                            <option value="today"<?= $fFuSel === 'today' ? ' selected' : '' ?>>Today</option>
                            <option value="tomorrow"<?= $fFuSel === 'tomorrow' ? ' selected' : '' ?>>Tomorrow</option>
                            <option value="week"<?= $fFuSel === 'week' ? ' selected' : '' ?>>This week</option>
                            <option value="month"<?= $fFuSel === 'month' ? ' selected' : '' ?>>This month</option>
                            <option value="custom"<?= $fFuSel === 'custom' ? ' selected' : '' ?>>Custom</option>
                        </select>
                    </div>
                    <div class="form__row" id="leads_fu_custom_wrap"<?= $statusIsFollowUpForFilter && $fFuSel === 'custom' ? '' : ' style="display:none"' ?>>
                        <label for="f_fu_date">Follow-up date</label>
                        <input type="date" id="f_fu_date" name="f_fu_date" value="<?= e($fFuDateSel) ?>">
                    </div>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn--primary">Apply</button>
            </form>
            <script>
            (function () {
                var fu = document.getElementById('f_fu');
                var wrapCustom = document.getElementById('leads_fu_custom_wrap');
                var fuRow = document.getElementById('leads_filter_fu_wrap');
                var st = document.getElementById('f_status');

                function toggleCustom() {
                    if (!fu || !wrapCustom) return;
                    wrapCustom.style.display = (fu.value === 'custom') ? '' : 'none';
                }

                function syncFuRow() {
                    if (!fuRow || !st || !fu) return;
                    var fid = parseInt(String(fuRow.getAttribute('data-follow-up-status-id') || '0'), 10);
                    if (!(fid > 0)) {
                        fuRow.style.display = 'none';
                        return;
                    }
                    var showRow = String(st.value) !== 'all' && parseInt(String(st.value), 10) === fid;
                    fuRow.style.display = showRow ? 'flex' : 'none';
                    if (!showRow) {
                        fu.value = 'all';
                        wrapCustom = document.getElementById('leads_fu_custom_wrap');
                        if (wrapCustom) wrapCustom.style.display = 'none';
                        var dt = document.getElementById('f_fu_date');
                        if (dt) dt.value = '';
                    }
                    toggleCustom();
                }

                if (fu && wrapCustom) {
                    fu.addEventListener('change', toggleCustom);
                    toggleCustom();
                }
                if (st && fuRow) {
                    st.addEventListener('change', syncFuRow);
                    syncFuRow();
                }
            })();
            </script>
            <?php if ($rows === []): ?>
                <p class="empty">No leads found.</p>
            <?php else: ?>
                <div class="table-wrap table-wrap--leads-mobile">
                    <?php $metaLeadsTableLocClass = ($roleId === ROLE_SUPERADMIN || $roleId === ROLE_ADMIN) ? ' data--meta-leads--with-loc' : ''; ?>
                    <table class="data data--meta-leads<?= $metaLeadsTableLocClass ?>">
                        <thead>
                            <tr>
                                <?php if ($roleId === ROLE_SUPERADMIN || $roleId === ROLE_ADMIN): ?>
                                    <th class="lead-list-col lead-list-col--loc">Location</th>
                                <?php endif; ?>
                                <th class="lead-list-col lead-list-col--name">Name</th>
                                <th class="lead-list-col lead-list-col--phone">Number</th>
                                <th class="lead-list-col lead-list-col--status">Status</th>
                                <th class="lead-list-col lead-list-col--date">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row):
                                $cellPhone = (string) ($row['lead_phone_number'] ?? '');
                                $cellWa = leads_whatsapp_chat_url($cellPhone);
                                ?>
                                <tr>
                                    <?php if ($roleId === ROLE_SUPERADMIN || $roleId === ROLE_ADMIN): ?>
                                        <td class="lead-list-col lead-list-col--loc" data-label="Location"><?= e((string) ($row['branch_name'] ?? '')) ?></td>
                                    <?php endif; ?>
                                    <td class="lead-list-col lead-list-col--name" data-label="Name"><a class="link--underlined" href="leads.php?<?= e(http_build_query(array_merge($listFilterParams, ['id' => (int) ($row['id'] ?? 0)]))) ?>"><?= e((string) ($row['lead_name'] ?? '')) ?></a></td>
                                    <td class="lead-list-col lead-list-col--phone" data-label="Number">
                                        <?php if ($cellWa !== null): ?>
                                            <a class="link--underlined" href="<?= e($cellWa) ?>" target="_blank" rel="noopener noreferrer"><?= e($cellPhone) ?></a>
                                        <?php elseif ($cellPhone !== ''): ?>
                                            <?= e($cellPhone) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <?php $rowStatusKey = strtolower(trim((string) ($row['status_key'] ?? ''))); ?>
                                    <td class="lead-list-col lead-list-col--status<?= $rowStatusKey === 'converted' ? ' lead-status--converted' : '' ?>" data-label="Status"><?= e((string) ($row['status_label'] ?? '—')) ?></td>
                                    <td class="lead-list-col lead-list-col--date" data-label="Date">
                                        <span class="lead-list-date lead-list-date--full"><?= e(leads_format_date_ist_dm((string) ($row['Created_Datetime'] ?? ''))) ?></span>
                                        <span class="lead-list-date lead-list-date--short"><?= e(leads_format_date_ist_dd_mmm((string) ($row['Created_Datetime'] ?? ''))) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($listTotalFiltered > $listPerPage): ?>
                    <nav class="leads-pagination" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem 0.85rem;padding:1rem 1.25rem 1.15rem;margin:0;justify-content:center">
                        <?php if ($listPage > 1): ?>
                            <a class="btn btn--ghost" href="leads.php?<?= e(http_build_query(array_merge($listFilterParams, ['page' => $listPage - 1, 'ls_collapsed' => 1]))) ?>">Previous</a>
                        <?php endif; ?>
                        <span style="font-size:.9rem;color:var(--muted, #64748b)">Page <?= (int) $listPage ?> of <?= (int) $listTotalPages ?></span>
                        <?php if ($listPage < $listTotalPages): ?>
                            <a class="btn btn--ghost" href="leads.php?<?= e(http_build_query(array_merge($listFilterParams, ['page' => $listPage + 1, 'ls_collapsed' => 1]))) ?>">Next</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($loadError === '' && $detailId <= 0 && $roleId === 3 && is_array($leadsConversionSummary) && !$hideLeadsListAndConversionCards): ?>
<script>
(function () {
    var conv = document.getElementById('leads-conversion-summary');
    var main = document.getElementById('leads-main-section');
    if (!conv || !main) return;
    main.addEventListener('click', function () {
        conv.open = false;
    }, false);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
