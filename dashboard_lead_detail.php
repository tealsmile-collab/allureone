<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/leads_helpers.php';
require_login();
require_not_accounts_role();
require_not_franchise_officer_role();

$user = current_user();
$roleId = (int) ($user['role_id'] ?? 0);
if ($roleId !== ROLE_SUPERADMIN && $roleId !== ROLE_ADMIN && $roleId !== ROLE_MANAGER) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$branchId = isset($user['branch_id']) && (int) $user['branch_id'] > 0 ? (int) $user['branch_id'] : null;
$isBranchScopedRole = $roleId === ROLE_MANAGER;

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
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $statusRow) {
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
} catch (Throwable $e) {
    error_log('AllureOne dashboard lead detail status load failed: ' . $e->getMessage());
}

$metaLeadCols = leads_meta_leads_column_map();
$flash = ['type' => '', 'text' => ''];

/**
 * @return array<string,mixed>|null
 */
function dashboard_lead_fetch_detail(array $map, int $detailId, bool $isBranchScopedRole, ?int $branchId, array $statusIdToLabel, array $statusIdToKey): ?array
{
    if ($detailId <= 0) {
        return null;
    }
    $qId = leads_ml_qualify_ml($map, ['id']);
    $qLeadName = leads_ml_qualify_ml($map, ['lead_name']);
    $qPhone = leads_ml_qualify_ml($map, ['lead_phone_number', 'Lead_Phone_Number', 'phone_number']);
    $qBranchName = leads_ml_qualify_ml($map, ['branch_name']);
    $qStatus = leads_ml_qualify_ml($map, ['status']);
    $qCreated = leads_ml_qualify_ml($map, ['Created_Datetime', 'created_datetime', 'DateTime']);
    $qFollowupCol = leads_ml_qualify_ml($map, ['followup_datetime', 'Followup_Datetime']);
    $qCampaignCol = leads_ml_qualify_ml($map, ['Campaiign', 'Campaign', 'campaign']);
    $qSrc = leads_ml_qualify_ml($map, ['sourceName', 'SourceName']);
    $qRemarks = leads_ml_qualify_ml($map, ['remarks']);
    $qAmt = leads_ml_qualify_ml($map, ['amount']);
    if ($qId === null || $qLeadName === null || $qPhone === null || $qStatus === null) {
        return null;
    }

    $detailPieces = [$qId . ' AS id'];
    foreach (
        [
            [$qLeadName, 'lead_name'],
            [$qPhone, 'lead_phone_number'],
            [$qBranchName, 'branch_name'],
            [$qSrc, 'sourceName'],
            [$qCampaignCol, 'Campaiign'],
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
    $detailParams = ['id' => $detailId];
    if ($isBranchScopedRole) {
        $bqBranch = leads_ml_qualify_ml($map, ['branch_id', 'BranchId']);
        if ($branchId === null || $branchId <= 0 || $bqBranch === null) {
            $detailSql .= ' AND 0=1';
        } else {
            $detailSql .= ' AND ' . $bqBranch . ' = :branch_id';
            $detailParams['branch_id'] = $branchId;
        }
    }
    $detailSql .= ' LIMIT 1';
    $detailStmt = db()->prepare($detailSql);
    $detailStmt->execute($detailParams);
    $detailRow = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!is_array($detailRow)) {
        return null;
    }
    $dsid = (int) ($detailRow['status'] ?? 0);
    $detailRow['status_label'] = $statusIdToLabel[$dsid] ?? ($dsid > 0 ? 'Status #' . $dsid : '—');
    $detailRow['status_key'] = $statusIdToKey[$dsid] ?? '';

    return $detailRow;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_lead'])) {
    $saveId = isset($_POST['lead_id']) ? (int) $_POST['lead_id'] : 0;
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Invalid session. Please refresh and try again.'];
    } elseif (!is_array($metaLeadCols)) {
        $flash = ['type' => 'error', 'text' => 'Leads table is not available. Cannot save.'];
    } else {
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
            } catch (Throwable $e) {
                error_log('AllureOne dashboard lead save failed: ' . $e->getMessage());
                $flash = ['type' => 'error', 'text' => 'Could not save lead details.'];
            }
        }
    }
    $detailId = $saveId > 0 ? $saveId : (int) ($_GET['id'] ?? 0);
} else {
    $detailId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
}

if ($detailId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid lead.']);
    exit;
}

$detailRow = null;
if (is_array($metaLeadCols)) {
    try {
        $detailRow = dashboard_lead_fetch_detail(
            $metaLeadCols,
            $detailId,
            $isBranchScopedRole,
            $branchId,
            $statusIdToLabel,
            $statusIdToKey
        );
    } catch (Throwable $e) {
        error_log('AllureOne dashboard lead detail fetch failed: ' . $e->getMessage());
    }
}

ob_start();
if ($flash['text'] !== '') {
    echo '<p class="alert alert--' . e($flash['type'] === 'ok' ? 'ok' : 'error') . '" style="margin:1rem 1.25rem">' . e($flash['text']) . '</p>';
}
if (!is_array($detailRow)) {
    echo '<p class="empty">Lead not found.</p>';
    echo '<p style="padding:0 1.25rem 1.25rem;margin:0"><button type="button" class="btn btn--ghost js-dash-lead-back">Back</button></p>';
} else {
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
        <form id="dash-lead-detail-form" method="post" action="dashboard_lead_detail.php?id=<?= (int) ($detailRow['id'] ?? 0) ?>" style="margin-top:0.9rem">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="lead_id" value="<?= (int) ($detailRow['id'] ?? 0) ?>">
            <div class="form__row" style="margin-bottom:0.75rem">
                <label for="dash_lead_status">Status</label>
                <select id="dash_lead_status" name="status">
                    <?php foreach ($statusOptions as $statusOpt): ?>
                        <option value="<?= (int) ($statusOpt['id'] ?? 0) ?>" data-status-key="<?= e((string) ($statusOpt['key'] ?? '')) ?>"<?= $currentStatusId === (int) ($statusOpt['id'] ?? 0) ? ' selected' : '' ?>><?= e((string) ($statusOpt['label'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form__row" id="dash_followup_wrap" style="margin-bottom:0.75rem;<?= $currentStatusKey === 'follow_up' ? '' : 'display:none;' ?>">
                <label for="dash_followup_datetime">Follow Up date/time</label>
                <input type="datetime-local" id="dash_followup_datetime" name="followup_datetime" value="<?= e(leads_format_utc_to_datetime_local_input((string) ($detailRow['followup_datetime'] ?? ''))) ?>">
            </div>
            <div class="form__row" id="dash_amount_wrap" style="margin-bottom:0.75rem;<?= $currentStatusKey === 'converted' ? '' : 'display:none;' ?>">
                <label for="dash_amount">Amount (Rs.)</label>
                <input type="text" id="dash_amount" name="amount" maxlength="20" inputmode="numeric" pattern="[0-9]{1,20}"
                       value="<?= e(leads_amount_detail_input_string(isset($detailRow['amount']) ? (string) $detailRow['amount'] : null)) ?>"
                       <?= $currentStatusKey === 'converted' ? '' : 'disabled' ?>>
            </div>
            <div class="form__row" style="margin-bottom:0.75rem">
                <label for="dash_remarks">Remarks <span class="required-mark" aria-hidden="true">*</span></label>
                <textarea id="dash_remarks" name="remarks" rows="3" maxlength="100" required placeholder="Enter remarks (required, max 100 characters)"><?= e((string) ($detailRow['remarks'] ?? '')) ?></textarea>
            </div>
            <div class="leads-detail-actions" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem 1.25rem;margin-top:0.35rem">
                <button type="submit" class="btn btn--primary" name="save_lead" value="1">Save</button>
                <button type="button" class="btn btn--ghost js-dash-lead-back">Back</button>
            </div>
        </form>
    </div>
    <?php
}
$html = (string) ob_get_clean();

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'ok' => is_array($detailRow),
    'id' => $detailId,
    'html' => $html,
    'flash' => $flash,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
