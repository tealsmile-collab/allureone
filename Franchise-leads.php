<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_not_accounts_role();

$user = current_user();
$roleId = (int) ($user['role_id'] ?? 0);
if ($roleId !== ROLE_SUPERADMIN && $roleId !== ROLE_ADMIN && $roleId !== ROLE_FRANCHISE_OFFICER) {
    http_response_code(403);
    exit('Forbidden');
}

function franchise_leads_format_datetime_ist(?string $utcDateTime): string
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

function franchise_leads_parse_datetime_local_to_mysql_utc(string $value): ?string
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

function franchise_leads_format_utc_to_datetime_local_input(?string $utcDateTime): string
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

function franchise_leads_ensure_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $cols = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'allureone_franchise_leads'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $colSet = is_array($cols) ? array_flip(array_map('strval', $cols)) : [];
        if (!isset($colSet['status'])) {
            $pdo->exec('ALTER TABLE allureone_franchise_leads ADD COLUMN status INT UNSIGNED NOT NULL DEFAULT 1');
        }
        if (!isset($colSet['remarks'])) {
            $pdo->exec('ALTER TABLE allureone_franchise_leads ADD COLUMN remarks VARCHAR(100) NULL');
        }
        if (!isset($colSet['followup_datetime'])) {
            $pdo->exec('ALTER TABLE allureone_franchise_leads ADD COLUMN followup_datetime DATETIME NULL');
        }
        if (!isset($colSet['web_submission_id'])) {
            $pdo->exec('ALTER TABLE allureone_franchise_leads ADD COLUMN web_submission_id BIGINT NULL');
        }
        $indexes = $pdo->query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'allureone_franchise_leads'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $indexSet = is_array($indexes) ? array_flip(array_map('strval', $indexes)) : [];
        if (!isset($indexSet['idx_franchise_leads_status'])) {
            $pdo->exec('ALTER TABLE allureone_franchise_leads ADD KEY idx_franchise_leads_status (status)');
        }
        if (!isset($indexSet['uq_franchise_leads_web_submission'])) {
            $pdo->exec('ALTER TABLE allureone_franchise_leads ADD UNIQUE KEY uq_franchise_leads_web_submission (web_submission_id)');
        }
        $old = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'allureone_franchise_lead_status'"
        )->fetchColumn();
        if ($old > 0) {
            // Migrate any saved rows into franchise_leads, then drop legacy table.
            $legacy = $pdo->query('SELECT lead_key, status, remarks, followup_datetime FROM allureone_franchise_lead_status')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($legacy as $leg) {
                if (!is_array($leg)) {
                    continue;
                }
                $key = trim((string) ($leg['lead_key'] ?? ''));
                if ($key === '' || strpos($key, '-') === false) {
                    continue;
                }
                [$src, $idPart] = explode('-', $key, 2);
                $id = (int) $idPart;
                if ($id <= 0) {
                    continue;
                }
                $status = (int) ($leg['status'] ?? 1);
                if ($status <= 0) {
                    $status = 1;
                }
                $remarks = trim((string) ($leg['remarks'] ?? ''));
                $fu = trim((string) ($leg['followup_datetime'] ?? ''));
                $fuVal = $fu !== '' ? $fu : null;
                if ($src === 'db') {
                    $ust = $pdo->prepare(
                        'UPDATE allureone_franchise_leads
                         SET status = :status, remarks = :remarks, followup_datetime = :followup_datetime
                         WHERE id = :id AND web_submission_id IS NULL'
                    );
                    $ust->execute([
                        'status' => $status,
                        'remarks' => $remarks !== '' ? $remarks : null,
                        'followup_datetime' => $fuVal,
                        'id' => $id,
                    ]);
                } elseif ($src === 'web') {
                    $chk = $pdo->prepare('SELECT id FROM allureone_franchise_leads WHERE web_submission_id = :wid LIMIT 1');
                    $chk->execute(['wid' => $id]);
                    $existingId = (int) ($chk->fetchColumn() ?: 0);
                    if ($existingId > 0) {
                        $ust = $pdo->prepare(
                            'UPDATE allureone_franchise_leads
                             SET status = :status, remarks = :remarks, followup_datetime = :followup_datetime
                             WHERE id = :id'
                        );
                        $ust->execute([
                            'status' => $status,
                            'remarks' => $remarks !== '' ? $remarks : null,
                            'followup_datetime' => $fuVal,
                            'id' => $existingId,
                        ]);
                    } else {
                        $ist = $pdo->prepare(
                            'INSERT INTO allureone_franchise_leads
                             (sourceName, status, remarks, followup_datetime, web_submission_id, form_id)
                             VALUES (\'Website\', :status, :remarks, :followup_datetime, :web_submission_id, 11)'
                        );
                        $ist->execute([
                            'status' => $status,
                            'remarks' => $remarks !== '' ? $remarks : null,
                            'followup_datetime' => $fuVal,
                            'web_submission_id' => $id,
                        ]);
                    }
                }
            }
            $pdo->exec('DROP TABLE IF EXISTS allureone_franchise_lead_status');
        }
    } catch (PDOException $e) {
        error_log('AllureOne franchise leads column ensure failed: ' . $e->getMessage());
    }
}

/**
 * @return array{status:int,remarks:string,followup_datetime:?string}
 */
function franchise_leads_status_defaults(int $defaultStatusId): array
{
    return [
        'status' => $defaultStatusId > 0 ? $defaultStatusId : 1,
        'remarks' => '',
        'followup_datetime' => null,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array{status:int,remarks:string,followup_datetime:?string}
 */
function franchise_leads_extract_status_fields(array $row, int $defaultStatusId): array
{
    $out = franchise_leads_status_defaults($defaultStatusId);
    $sid = (int) ($row['status'] ?? 0);
    if ($sid > 0) {
        $out['status'] = $sid;
    }
    $out['remarks'] = trim((string) ($row['remarks'] ?? ''));
    $fu = trim((string) ($row['followup_datetime'] ?? ''));
    $out['followup_datetime'] = $fu !== '' ? $fu : null;

    return $out;
}

/**
 * @return array<int, array<string, mixed>>
 */
function franchise_leads_list(): array
{
    franchise_leads_ensure_columns();
    $rows = [];

    $sql = 'SELECT
                id,
                DateTime AS lead_datetime,
                FULL_NAME AS full_name,
                CITY AS city,
                PHONE_NUMBER AS phone_number,
                investment_budget,
                preferred_timeline,
                experience_in_the_wellness,
                property_for_the_wellness,
                sourceName AS source_name,
                form_id,
                campaign_id,
                status,
                remarks,
                followup_datetime
            FROM allureone_franchise_leads
            WHERE web_submission_id IS NULL';
    $st = db()->query($sql);
    foreach ($st->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['lead_key'] = 'db-' . (int) ($row['id'] ?? 0);
        $sid = (int) ($row['status'] ?? 0);
        $row['status'] = $sid > 0 ? $sid : 1;
        $rows[] = $row;
    }

    $webStatusBySubmission = [];
    try {
        $wstMap = db()->query(
            'SELECT web_submission_id, status
             FROM allureone_franchise_leads
             WHERE web_submission_id IS NOT NULL'
        );
        foreach ($wstMap->fetchAll() as $wsRow) {
            if (!is_array($wsRow)) {
                continue;
            }
            $wid = (int) ($wsRow['web_submission_id'] ?? 0);
            if ($wid <= 0) {
                continue;
            }
            $sid = (int) ($wsRow['status'] ?? 0);
            $webStatusBySubmission[$wid] = $sid > 0 ? $sid : 1;
        }
    } catch (PDOException $e) {
        error_log('AllureOne franchise web status map load failed: ' . $e->getMessage());
    }

    $wpdb = wp_db();
    $wpPrefix = wp_table_prefix();
    $wsql = 'SELECT id, response, created_at, form_id
             FROM wp_fluentform_submissions
             WHERE form_id = 11';
    $wsql = str_replace('wp_', $wpPrefix, $wsql);
    $wst = $wpdb->query($wsql);
    foreach ($wst->fetchAll() as $wrow) {
        if (!is_array($wrow)) {
            continue;
        }
        $mapped = franchise_leads_map_fluentform_response((string) ($wrow['response'] ?? ''));
        $webId = (int) ($wrow['id'] ?? 0);
        $rows[] = [
            'id' => $webId,
            'lead_key' => 'web-' . $webId,
            'lead_datetime' => (string) ($wrow['created_at'] ?? ''),
            'full_name' => (string) ($mapped['full_name'] ?? ''),
            'phone_number' => (string) ($mapped['phone_number'] ?? ''),
            'city' => (string) ($mapped['city'] ?? ''),
            'investment_budget' => (string) ($mapped['investment_budget'] ?? ''),
            'preferred_timeline' => (string) ($mapped['preferred_timeline'] ?? ''),
            'experience_in_the_wellness' => (string) ($mapped['experience_in_the_wellness'] ?? ''),
            'property_for_the_wellness' => (string) ($mapped['property_for_the_wellness'] ?? ''),
            'source_name' => 'Website',
            'form_id' => (string) ($wrow['form_id'] ?? ''),
            'campaign_id' => '',
            'status' => $webStatusBySubmission[$webId] ?? 1,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $ta = strtotime((string) ($a['lead_datetime'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['lead_datetime'] ?? '')) ?: 0;
        if ($tb === $ta) {
            return strcmp((string) ($b['lead_key'] ?? ''), (string) ($a['lead_key'] ?? ''));
        }

        return $tb <=> $ta;
    });

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function franchise_leads_detail(string $leadKey): ?array
{
    franchise_leads_ensure_columns();
    $leadKey = trim($leadKey);
    if ($leadKey === '' || strpos($leadKey, '-') === false) {
        return null;
    }
    [$source, $idPart] = explode('-', $leadKey, 2);
    $id = (int) $idPart;
    if ($id <= 0) {
        return null;
    }

    if ($source === 'db') {
        $sql = 'SELECT
                    id,
                    DateTime AS lead_datetime,
                    FULL_NAME AS full_name,
                    PHONE_NUMBER AS phone_number,
                    CITY AS city,
                    investment_budget,
                    preferred_timeline,
                    experience_in_the_wellness,
                    property_for_the_wellness,
                    sourceName AS source_name,
                    form_id,
                    campaign_id,
                    status,
                    remarks,
                    followup_datetime
                FROM allureone_franchise_leads
                WHERE id = :id AND web_submission_id IS NULL
                LIMIT 1';
        $st = db()->prepare($sql);
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['lead_key'] = 'db-' . (int) ($row['id'] ?? 0);

        return $row;
    }

    if ($source === 'web') {
        $wpdb = wp_db();
        $wpPrefix = wp_table_prefix();
        $sql = 'SELECT id, response, created_at, form_id
                FROM wp_fluentform_submissions
                WHERE form_id = 11 AND id = :id
                LIMIT 1';
        $sql = str_replace('wp_', $wpPrefix, $sql);
        $st = $wpdb->prepare($sql);
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        if (!is_array($row)) {
            return null;
        }
        $mapped = franchise_leads_map_fluentform_response((string) ($row['response'] ?? ''));
        $out = [
            'id' => (int) ($row['id'] ?? 0),
            'lead_key' => 'web-' . (int) ($row['id'] ?? 0),
            'lead_datetime' => (string) ($row['created_at'] ?? ''),
            'full_name' => (string) ($mapped['full_name'] ?? ''),
            'phone_number' => (string) ($mapped['phone_number'] ?? ''),
            'city' => (string) ($mapped['city'] ?? ''),
            'investment_budget' => (string) ($mapped['investment_budget'] ?? ''),
            'preferred_timeline' => (string) ($mapped['preferred_timeline'] ?? ''),
            'experience_in_the_wellness' => (string) ($mapped['experience_in_the_wellness'] ?? ''),
            'property_for_the_wellness' => (string) ($mapped['property_for_the_wellness'] ?? ''),
            'source_name' => 'Website',
            'form_id' => (string) ($row['form_id'] ?? ''),
            'campaign_id' => '',
            'status' => 1,
            'remarks' => '',
            'followup_datetime' => null,
        ];
        try {
            $sst = db()->prepare(
                'SELECT status, remarks, followup_datetime
                 FROM allureone_franchise_leads
                 WHERE web_submission_id = :wid
                 LIMIT 1'
            );
            $sst->execute(['wid' => $id]);
            $srow = $sst->fetch(PDO::FETCH_ASSOC);
            if (is_array($srow)) {
                $out['status'] = (int) ($srow['status'] ?? 1);
                $out['remarks'] = trim((string) ($srow['remarks'] ?? ''));
                $fu = trim((string) ($srow['followup_datetime'] ?? ''));
                $out['followup_datetime'] = $fu !== '' ? $fu : null;
            }
        } catch (PDOException $e) {
            error_log('AllureOne franchise web lead status load failed: ' . $e->getMessage());
        }

        return $out;
    }

    return null;
}

/**
 * @return array<string, string>
 */
function franchise_leads_map_fluentform_response(string $jsonResponse): array
{
    $decoded = json_decode($jsonResponse, true);
    if (!is_array($decoded)) {
        return [
            'full_name' => '',
            'phone_number' => '',
            'city' => '',
            'investment_budget' => '',
            'preferred_timeline' => '',
            'experience_in_the_wellness' => '',
            'property_for_the_wellness' => '',
        ];
    }

    $names = $decoded['names'] ?? null;
    $firstName = '';
    if (is_array($names)) {
        $firstName = trim((string) ($names['first_name'] ?? ''));
    }

    return [
        'full_name' => $firstName,
        'phone_number' => trim((string) ($decoded['input_mask'] ?? '')),
        'city' => trim((string) ($decoded['input_text'] ?? '')),
        'investment_budget' => trim((string) ($decoded['dropdown'] ?? '')),
        'preferred_timeline' => trim((string) ($decoded['dropdown_1'] ?? '')),
        'experience_in_the_wellness' => trim((string) ($decoded['dropdown_2'] ?? '')),
        'property_for_the_wellness' => trim((string) ($decoded['dropdown_3'] ?? '')),
    ];
}

$detailKey = trim((string) ($_GET['id'] ?? ''));
$sourceFilter = trim((string) ($_GET['source'] ?? ''));
$allowedSources = ['Google Ads', 'Website'];
if ($sourceFilter !== '' && !in_array($sourceFilter, $allowedSources, true)) {
    $sourceFilter = '';
}
$statusFilterRaw = trim((string) ($_GET['status'] ?? ''));
$statusFilter = 0;
if ($statusFilterRaw !== '' && $statusFilterRaw !== 'all') {
    $statusFilter = (int) $statusFilterRaw;
}
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
$perPage = 20;
$rows = [];
$pagedRows = [];
$detailRow = null;
$loadError = '';
$totalRows = 0;
$totalPages = 1;
$flash = ['type' => '', 'text' => ''];

$statusOptions = [];
$statusOptionIds = [];
$statusIdToKey = [];
$statusIdToLabel = [];
$defaultStatusId = 1;

try {
    $statusStmt = db()->prepare(
        "SELECT id, status_key, status_label
         FROM allureone_leads_status
         WHERE is_active = 1
           AND applies_to IN ('all', 'franchise')
           AND status_key NOT IN ('booked', 'no_show')
         ORDER BY sort_order ASC, id ASC"
    );
    $statusStmt->execute();
    foreach ($statusStmt->fetchAll() as $statusRow) {
        if (!is_array($statusRow)) {
            continue;
        }
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
        if ($skey === 'new') {
            $defaultStatusId = $sid;
        }
    }
} catch (PDOException $e) {
    error_log('AllureOne franchise leads status master load failed: ' . $e->getMessage());
}

if ($statusFilter > 0 && !isset($statusOptionIds[$statusFilter])) {
    $statusFilter = 0;
}

$listQuery = [];
if ($sourceFilter !== '') {
    $listQuery['source'] = $sourceFilter;
}
if ($statusFilter > 0) {
    $listQuery['status'] = (string) $statusFilter;
}
if ($page > 1) {
    $listQuery['page'] = (string) $page;
}
$listUrl = 'Franchise-leads.php' . ($listQuery === [] ? '' : ('?' . http_build_query($listQuery, '', '&', PHP_QUERY_RFC3986)));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_franchise_lead'])) {
    $saveKey = trim((string) ($_POST['lead_key'] ?? ''));
    if ($saveKey !== '') {
        $detailKey = $saveKey;
    }
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Invalid session. Please refresh and try again.'];
    } else {
        $statusId = isset($_POST['status']) ? (int) $_POST['status'] : 0;
        $statusKey = $statusIdToKey[$statusId] ?? '';
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        $followupInput = trim((string) ($_POST['followup_datetime'] ?? ''));
        $followupUtc = null;

        if ($saveKey === '' || franchise_leads_detail($saveKey) === null) {
            $flash = ['type' => 'error', 'text' => 'Invalid franchise lead selected.'];
        } elseif ($statusId <= 0 || !isset($statusOptionIds[$statusId])) {
            $flash = ['type' => 'error', 'text' => 'Please select a valid status.'];
        } elseif ($remarks === '') {
            $flash = ['type' => 'error', 'text' => 'Remarks is required.'];
        } elseif ((function_exists('mb_strlen') ? mb_strlen($remarks) : strlen($remarks)) > 100) {
            $flash = ['type' => 'error', 'text' => 'Remarks can be maximum 100 characters.'];
        } else {
            if ($statusKey === 'follow_up') {
                $followupUtc = franchise_leads_parse_datetime_local_to_mysql_utc($followupInput);
                if ($followupUtc === null) {
                    $flash = ['type' => 'error', 'text' => 'Please select valid Follow Up date/time.'];
                }
            }
        }

        if ($flash['text'] === '') {
            try {
                franchise_leads_ensure_columns();
                $detailForSave = franchise_leads_detail($saveKey);
                if ($detailForSave === null) {
                    throw new RuntimeException('Franchise lead missing on save');
                }
                [$src, $idPart] = explode('-', $saveKey, 2);
                $leadId = (int) $idPart;
                $followupToStore = $statusKey === 'follow_up' ? $followupUtc : null;
                if ($src === 'db') {
                    $st = db()->prepare(
                        'UPDATE allureone_franchise_leads
                         SET status = :status,
                             remarks = :remarks,
                             followup_datetime = :followup_datetime
                         WHERE id = :id AND web_submission_id IS NULL'
                    );
                    $st->execute([
                        'status' => $statusId,
                        'remarks' => $remarks,
                        'followup_datetime' => $followupToStore,
                        'id' => $leadId,
                    ]);
                } elseif ($src === 'web') {
                    $st = db()->prepare(
                        'INSERT INTO allureone_franchise_leads
                            (FULL_NAME, PHONE_NUMBER, CITY, investment_budget, preferred_timeline,
                             experience_in_the_wellness, property_for_the_wellness, sourceName, DateTime,
                             form_id, campaign_id, status, remarks, followup_datetime, web_submission_id)
                         VALUES
                            (:full_name, :phone_number, :city, :investment_budget, :preferred_timeline,
                             :experience_in_the_wellness, :property_for_the_wellness, :source_name, :lead_datetime,
                             :form_id, NULL, :status, :remarks, :followup_datetime, :web_submission_id)
                         ON DUPLICATE KEY UPDATE
                            FULL_NAME = VALUES(FULL_NAME),
                            PHONE_NUMBER = VALUES(PHONE_NUMBER),
                            CITY = VALUES(CITY),
                            investment_budget = VALUES(investment_budget),
                            preferred_timeline = VALUES(preferred_timeline),
                            experience_in_the_wellness = VALUES(experience_in_the_wellness),
                            property_for_the_wellness = VALUES(property_for_the_wellness),
                            status = VALUES(status),
                            remarks = VALUES(remarks),
                            followup_datetime = VALUES(followup_datetime)'
                    );
                    $leadDt = trim((string) ($detailForSave['lead_datetime'] ?? ''));
                    if ($leadDt === '') {
                        $leadDt = gmdate('Y-m-d H:i:s');
                    }
                    $formIdRaw = trim((string) ($detailForSave['form_id'] ?? ''));
                    $st->execute([
                        'full_name' => (string) ($detailForSave['full_name'] ?? ''),
                        'phone_number' => (string) ($detailForSave['phone_number'] ?? ''),
                        'city' => (string) ($detailForSave['city'] ?? ''),
                        'investment_budget' => (string) ($detailForSave['investment_budget'] ?? ''),
                        'preferred_timeline' => (string) ($detailForSave['preferred_timeline'] ?? ''),
                        'experience_in_the_wellness' => (string) ($detailForSave['experience_in_the_wellness'] ?? ''),
                        'property_for_the_wellness' => (string) ($detailForSave['property_for_the_wellness'] ?? ''),
                        'source_name' => 'Website',
                        'lead_datetime' => $leadDt,
                        'form_id' => $formIdRaw !== '' && ctype_digit($formIdRaw) ? (int) $formIdRaw : 11,
                        'status' => $statusId,
                        'remarks' => $remarks,
                        'followup_datetime' => $followupToStore,
                        'web_submission_id' => $leadId,
                    ]);
                } else {
                    throw new RuntimeException('Unsupported franchise lead key');
                }
                $flash = ['type' => 'ok', 'text' => 'Franchise lead updated successfully.'];
            } catch (Throwable $e) {
                error_log('AllureOne franchise lead save failed: ' . $e->getMessage());
                $flash = ['type' => 'error', 'text' => 'Could not save franchise lead details.'];
            }
        }
    }
}

try {
    if ($detailKey !== '') {
        $detailRow = franchise_leads_detail($detailKey);
        if (is_array($detailRow)) {
            $statusFields = franchise_leads_extract_status_fields($detailRow, $defaultStatusId);
            $detailRow['status'] = $statusFields['status'];
            $detailRow['remarks'] = $statusFields['remarks'];
            $detailRow['followup_datetime'] = $statusFields['followup_datetime'];
            if (!isset($statusOptionIds[(int) $detailRow['status']])) {
                $detailRow['status'] = $defaultStatusId;
            }
            // Keep submitted values on validation failure
            if ($flash['type'] === 'error' && isset($_POST['save_franchise_lead'])) {
                $postedStatus = isset($_POST['status']) ? (int) $_POST['status'] : 0;
                if ($postedStatus > 0 && isset($statusOptionIds[$postedStatus])) {
                    $detailRow['status'] = $postedStatus;
                }
                $detailRow['remarks'] = trim((string) ($_POST['remarks'] ?? ''));
                $detailRow['followup_datetime_local'] = trim((string) ($_POST['followup_datetime'] ?? ''));
            }
        }
    } else {
        $rows = franchise_leads_list();
        if ($sourceFilter !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($sourceFilter): bool {
                return trim((string) ($row['source_name'] ?? '')) === $sourceFilter;
            }));
        }
        if ($statusFilter > 0) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($statusFilter, $defaultStatusId): bool {
                $sid = (int) ($row['status'] ?? 0);
                if ($sid <= 0) {
                    $sid = $defaultStatusId;
                }

                return $sid === $statusFilter;
            }));
        }
        $totalRows = count($rows);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $pagedRows = array_slice($rows, $offset, $perPage);
    }
} catch (Throwable $e) {
    error_log('AllureOne franchise leads page failed: ' . $e->getMessage());
    $loadError = 'Could not load franchise leads.';
}

$currentStatusId = is_array($detailRow) ? (int) ($detailRow['status'] ?? $defaultStatusId) : $defaultStatusId;
if ($currentStatusId <= 0 || !isset($statusOptionIds[$currentStatusId])) {
    $currentStatusId = $defaultStatusId;
}
$currentStatusKey = $statusIdToKey[$currentStatusId] ?? 'new';
$detailFormQuery = ['id' => $detailKey];
if ($sourceFilter !== '') {
    $detailFormQuery['source'] = $sourceFilter;
}
if ($statusFilter > 0) {
    $detailFormQuery['status'] = (string) $statusFilter;
}
if ($page > 1) {
    $detailFormQuery['page'] = (string) $page;
}

$pageTitle = 'Franchise Leads';
$activeNav = 'franchise_leads';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="card">
    <div class="card__head">
        <span>Franchise Leads</span>
    </div>
    <div class="card__body">
        <?php if ($loadError !== ''): ?>
            <p class="alert alert--error" style="margin:1rem 1.25rem"><?= e($loadError) ?></p>
        <?php elseif ($detailKey !== ''): ?>
            <?php if ($flash['text'] !== ''): ?>
                <p class="alert alert--<?= $flash['type'] === 'ok' ? 'ok' : 'error' ?>" style="margin:1rem 1.25rem"><?= e($flash['text']) ?></p>
            <?php endif; ?>
            <?php if ($detailRow === null): ?>
                <p class="empty">Lead not found.</p>
                <p style="padding:0 1.25rem 1.25rem;margin:0"><a class="btn btn--ghost" href="<?= e($listUrl) ?>">Back</a></p>
            <?php else: ?>
                <div style="padding:1.25rem">
                    <table class="data">
                        <tbody>
                            <tr><th>Date</th><td><?= e(franchise_leads_format_datetime_ist((string) ($detailRow['lead_datetime'] ?? ''))) ?></td></tr>
                            <tr><th>Full Name</th><td><?= e((string) ($detailRow['full_name'] ?? '')) ?></td></tr>
                            <tr><th>Mobile</th><td><?= e((string) ($detailRow['phone_number'] ?? '')) ?></td></tr>
                            <tr><th>City</th><td><?= e((string) ($detailRow['city'] ?? '')) ?></td></tr>
                            <tr><th>Investment Budget</th><td><?= e((string) ($detailRow['investment_budget'] ?? '')) ?></td></tr>
                            <tr><th>Preferred Timeline</th><td><?= e((string) ($detailRow['preferred_timeline'] ?? '')) ?></td></tr>
                            <tr><th>Experience In Wellness</th><td><?= e((string) ($detailRow['experience_in_the_wellness'] ?? '')) ?></td></tr>
                            <tr><th>Property For Wellness</th><td><?= e((string) ($detailRow['property_for_the_wellness'] ?? '')) ?></td></tr>
                            <tr><th>Source</th><td><?= e((string) ($detailRow['source_name'] ?? '')) ?></td></tr>
                            <tr><th>Form ID</th><td><?= e((string) ($detailRow['form_id'] ?? '')) ?></td></tr>
                            <tr><th>Campaign ID</th><td><?= e((string) ($detailRow['campaign_id'] ?? '')) ?></td></tr>
                            <tr><th>Status</th><td><?= e((string) ($statusIdToLabel[$currentStatusId] ?? 'New')) ?></td></tr>
                        </tbody>
                    </table>
                    <form method="post" action="Franchise-leads.php?<?= e(http_build_query($detailFormQuery, '', '&', PHP_QUERY_RFC3986)) ?>" style="margin-top:0.9rem">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="lead_key" value="<?= e((string) ($detailRow['lead_key'] ?? '')) ?>">
                        <div class="form__row" style="margin-bottom:0.75rem">
                            <label for="franchise_lead_status">Status</label>
                            <select id="franchise_lead_status" name="status">
                                <?php foreach ($statusOptions as $statusOpt): ?>
                                    <option value="<?= (int) ($statusOpt['id'] ?? 0) ?>" data-status-key="<?= e((string) ($statusOpt['key'] ?? '')) ?>"<?= $currentStatusId === (int) ($statusOpt['id'] ?? 0) ? ' selected' : '' ?>><?= e((string) ($statusOpt['label'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form__row" id="franchise_followup_wrap" style="margin-bottom:0.75rem;<?= $currentStatusKey === 'follow_up' ? '' : 'display:none;' ?>">
                            <label for="followup_datetime">Follow Up date/time</label>
                            <input type="datetime-local" id="followup_datetime" name="followup_datetime" value="<?= e(isset($detailRow['followup_datetime_local']) ? (string) $detailRow['followup_datetime_local'] : franchise_leads_format_utc_to_datetime_local_input(isset($detailRow['followup_datetime']) ? (string) $detailRow['followup_datetime'] : null)) ?>">
                        </div>
                        <div class="form__row" style="margin-bottom:0.75rem">
                            <label for="remarks">Remarks <span class="required-mark" aria-hidden="true">*</span></label>
                            <textarea id="remarks" name="remarks" rows="3" maxlength="100" required placeholder="Enter remarks (required, max 100 characters)"><?= e((string) ($detailRow['remarks'] ?? '')) ?></textarea>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem 1.25rem;margin-top:0.35rem">
                            <button type="submit" class="btn btn--primary" name="save_franchise_lead" value="1">Save</button>
                            <a class="btn btn--ghost" href="<?= e($listUrl) ?>">Back</a>
                        </div>
                    </form>
                </div>
                <script>
                (function () {
                    var statusEl = document.getElementById('franchise_lead_status');
                    var followupWrap = document.getElementById('franchise_followup_wrap');
                    var followupEl = document.getElementById('followup_datetime');
                    if (!statusEl || !followupWrap) return;

                    function selectedStatusKey() {
                        var selectedOpt = statusEl.options[statusEl.selectedIndex];
                        return selectedOpt ? String(selectedOpt.getAttribute('data-status-key') || '').toLowerCase() : '';
                    }

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
                        prevStatusKey = statusKey;
                    }

                    statusEl.addEventListener('change', function () {
                        sync(true);
                    });
                    sync(false);
                })();
                </script>
            <?php endif; ?>
        <?php elseif ($rows === []): ?>
            <form method="get" action="Franchise-leads.php" class="form form--invoice-search" style="padding:1rem 1.25rem 0">
                <div class="form__row">
                    <label for="source_filter">Source</label>
                    <select id="source_filter" name="source">
                        <option value="">All</option>
                        <?php foreach ($allowedSources as $src): ?>
                            <option value="<?= e($src) ?>"<?= $sourceFilter === $src ? ' selected' : '' ?>><?= e($src) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form__row">
                    <label for="status_filter">Status</label>
                    <select id="status_filter" name="status">
                        <option value="">All</option>
                        <?php foreach ($statusOptions as $statusOpt): ?>
                            <option value="<?= (int) ($statusOpt['id'] ?? 0) ?>"<?= $statusFilter === (int) ($statusOpt['id'] ?? 0) ? ' selected' : '' ?>><?= e((string) ($statusOpt['label'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form__row form__row--submit">
                    <button type="submit" class="btn btn--primary">Apply</button>
                </div>
            </form>
            <p class="empty">No franchise leads found.</p>
        <?php else: ?>
            <form method="get" action="Franchise-leads.php" class="form form--invoice-search" style="padding:1rem 1.25rem 0">
                <div class="form__row">
                    <label for="source_filter">Source</label>
                    <select id="source_filter" name="source">
                        <option value="">All</option>
                        <?php foreach ($allowedSources as $src): ?>
                            <option value="<?= e($src) ?>"<?= $sourceFilter === $src ? ' selected' : '' ?>><?= e($src) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form__row">
                    <label for="status_filter">Status</label>
                    <select id="status_filter" name="status">
                        <option value="">All</option>
                        <?php foreach ($statusOptions as $statusOpt): ?>
                            <option value="<?= (int) ($statusOpt['id'] ?? 0) ?>"<?= $statusFilter === (int) ($statusOpt['id'] ?? 0) ? ' selected' : '' ?>><?= e((string) ($statusOpt['label'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form__row form__row--submit">
                    <button type="submit" class="btn btn--primary">Apply</button>
                </div>
            </form>
            <div class="table-wrap">
                <table class="data data--franchise-leads">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Full Name</th>
                            <th>City</th>
                            <th>Mobile</th>
                            <th>Source</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagedRows as $row): ?>
                            <tr>
                                <td><?= e(franchise_leads_format_datetime_ist((string) ($row['lead_datetime'] ?? ''))) ?></td>
                                <?php
                                $detailQuery = ['id' => (string) ($row['lead_key'] ?? '')];
                                if ($sourceFilter !== '') {
                                    $detailQuery['source'] = $sourceFilter;
                                }
                                if ($statusFilter > 0) {
                                    $detailQuery['status'] = (string) $statusFilter;
                                }
                                if ($page > 1) {
                                    $detailQuery['page'] = (string) $page;
                                }
                                $rowStatusId = (int) ($row['status'] ?? 0);
                                if ($rowStatusId <= 0 || !isset($statusIdToLabel[$rowStatusId])) {
                                    $rowStatusId = $defaultStatusId;
                                }
                                ?>
                                <td><a class="link--underlined" href="Franchise-leads.php?<?= e(http_build_query($detailQuery, '', '&', PHP_QUERY_RFC3986)) ?>"><?= e((string) ($row['full_name'] ?? '')) ?></a></td>
                                <td><?= e((string) ($row['city'] ?? '')) ?></td>
                                <td><?= e((string) ($row['phone_number'] ?? '')) ?></td>
                                <td><?= e((string) ($row['source_name'] ?? '')) ?></td>
                                <td><?= e((string) ($statusIdToLabel[$rowStatusId] ?? 'New')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div style="padding:0.9rem 1.25rem 1.15rem;display:flex;gap:0.55rem;align-items:center;flex-wrap:wrap">
                    <?php if ($page > 1): ?>
                        <?php
                        $prevQuery = [];
                        if ($sourceFilter !== '') {
                            $prevQuery['source'] = $sourceFilter;
                        }
                        if ($statusFilter > 0) {
                            $prevQuery['status'] = (string) $statusFilter;
                        }
                        if ($page - 1 > 1) {
                            $prevQuery['page'] = (string) ($page - 1);
                        }
                        ?>
                        <a class="btn btn--ghost" href="Franchise-leads.php<?= $prevQuery === [] ? '' : ('?' . e(http_build_query($prevQuery, '', '&', PHP_QUERY_RFC3986))) ?>">Previous</a>
                    <?php endif; ?>
                    <span class="main__meta" style="margin:0">Page <?= $page ?> of <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <?php
                        $nextQuery = [];
                        if ($sourceFilter !== '') {
                            $nextQuery['source'] = $sourceFilter;
                        }
                        if ($statusFilter > 0) {
                            $nextQuery['status'] = (string) $statusFilter;
                        }
                        $nextQuery['page'] = (string) ($page + 1);
                        ?>
                        <a class="btn btn--ghost" href="Franchise-leads.php?<?= e(http_build_query($nextQuery, '', '&', PHP_QUERY_RFC3986)) ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
