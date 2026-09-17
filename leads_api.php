<?php
declare(strict_types=1);

/**
 * Secured lead create API for external lead engines.
 *
 * POST leads_api.php
 * Header: X-Leads-Api-Key: <secret>  (or Authorization: Bearer <secret>)
 * Config: app.leads_api_key in config.php
 *
 * JSON body (required):
 *   lead_name, lead_phone_number, branch_id
 * Optional:
 *   branch_name, source_name, campaign, remarks, amount,
 *   external_id (stored as leadgen_id), form_id, ad_id, status
 *
 * Also accepts Gallabox-style envelopes (bodyParams) and aliases:
 *   customer_Name, customer_phonenumber, branchID, conversationDetails
 * Duplicate check is external_id + phone (not external_id alone).
 *
 * Creates a row in allureone_meta_leads (same table as leads.php).
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * @return array<string, mixed>
 */
function leads_api_config(): array
{
    global $config;
    if (!is_array($config ?? null)) {
        $config = require __DIR__ . '/config.php';
    }

    return is_array($config['app'] ?? null) ? $config['app'] : [];
}

function leads_api_expected_key(): string
{
    return trim((string) (leads_api_config()['leads_api_key'] ?? ''));
}

function leads_api_extract_key(): string
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if (is_array($h)) {
            $headers = $h;
        }
    }
    $normalized = [];
    foreach ($headers as $k => $v) {
        $normalized[strtolower((string) $k)] = (string) $v;
    }

    $fromHeader = trim((string) ($normalized['x-leads-api-key'] ?? ''));
    if ($fromHeader !== '') {
        return $fromHeader;
    }

    $auth = trim((string) ($normalized['authorization'] ?? ''));
    if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
        return trim((string) $m[1]);
    }

    return '';
}

function leads_api_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 10) {
        return '91' . $digits;
    }
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return $digits;
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        return '91' . substr($digits, 1);
    }

    return $digits;
}

/**
 * Drop unsubstituted automation placeholders like {{customer_Name}}.
 */
function leads_api_clean_template(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\{\{.+\}\}$/', $value) === 1) {
        return '';
    }

    return $value;
}

/**
 * Flatten Gallabox / automation envelopes and map common aliases.
 *
 * @param array<string, mixed> $json
 * @return array<string, mixed>
 */
function leads_api_normalize_payload(array $json): array
{
    foreach (['bodyParams', 'body', 'data', 'payload', 'params'] as $wrap) {
        if (isset($json[$wrap]) && is_array($json[$wrap])) {
            $json = array_merge($json, $json[$wrap]);
        }
    }

    $pick = static function (array $src, array $keys): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $src)) {
                continue;
            }
            $val = leads_api_clean_template(trim((string) $src[$key]));
            if ($val !== '') {
                return $val;
            }
        }

        return '';
    };

    $leadName = $pick($json, ['lead_name', 'name', 'customer_Name', 'customer_name', 'customerName']);
    $phoneRaw = $pick($json, [
        'lead_phone_number',
        'phone',
        'mobile',
        'customer_phonenumber',
        'customer_phone_number',
        'customer_phone',
        'customerPhone',
    ]);
    $branchRaw = $pick($json, ['branch_id', 'branchID', 'branchId', 'BranchID']);
    $branchNameHint = $pick($json, ['branch_name', 'location', 'branchName']);
    $sourceName = $pick($json, ['source_name', 'sourceName', 'source']);
    $campaign = $pick($json, ['campaign', 'Campaiign']);
    $remarks = $pick($json, ['remarks', 'conversationDetails', 'conversation_details', 'notes']);
    $externalId = $pick($json, ['external_id', 'leadgen_id', 'conversation_id', 'message_id']);

    return [
        'lead_name' => $leadName,
        'lead_phone_number' => $phoneRaw,
        'branch_id' => $branchRaw !== '' ? (int) $branchRaw : 0,
        'branch_name' => $branchNameHint,
        'source_name' => $sourceName !== '' ? $sourceName : 'Lead Engine',
        'campaign' => $campaign,
        'remarks' => $remarks,
        'external_id' => $externalId,
        'form_id' => $pick($json, ['form_id']),
        'ad_id' => $pick($json, ['ad_id']),
        'status' => $json['status'] ?? null,
        'amount' => $json['amount'] ?? null,
    ];
}

/**
 * @return array{ok:bool,error?:string,branch_id?:int,branch_name?:string}
 */
function leads_api_resolve_branch(int $branchId, string $branchNameHint): array
{
    if ($branchId <= 0) {
        return ['ok' => false, 'error' => 'branch_id is required and must be a positive integer.'];
    }
    try {
        $st = db()->prepare(
            'SELECT id, locality, business_name
             FROM allureone_branch
             WHERE id = :id AND isActive = 1
             LIMIT 1'
        );
        $st->execute(['id' => $branchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'Invalid or inactive branch_id.'];
        }
        $label = trim($branchNameHint);
        if ($label === '') {
            $label = trim((string) ($row['locality'] ?? ''));
        }
        if ($label === '') {
            $label = trim((string) ($row['business_name'] ?? ''));
        }
        if ($label === '') {
            $label = 'Branch #' . $branchId;
        }

        return [
            'ok' => true,
            'branch_id' => (int) $row['id'],
            'branch_name' => $label,
        ];
    } catch (Throwable $e) {
        error_log('AllureOne leads_api branch resolve: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'Could not validate branch.'];
    }
}

function leads_api_default_new_status_id(): int
{
    try {
        $st = db()->query(
            "SELECT id
             FROM allureone_leads_status
             WHERE is_active = 1
               AND applies_to IN ('all', 'meta')
               AND (
                    LOWER(TRIM(status_key)) IN ('new', 'open', 'pending')
                    OR LOWER(TRIM(status_label)) IN ('new', 'open', 'pending')
               )
             ORDER BY sort_order ASC, id ASC
             LIMIT 1"
        );
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    } catch (Throwable $e) {
        error_log('AllureOne leads_api status lookup: ' . $e->getMessage());
    }

    return 1;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$expectedKey = leads_api_expected_key();
if ($expectedKey === '') {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'Leads API is not configured. Set app.leads_api_key in config.php.',
    ]);
    exit;
}

$providedKey = leads_api_extract_key();
if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing API key.']);
    exit;
}

$raw = file_get_contents('php://input');
$json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($json)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$payload = leads_api_normalize_payload($json);
$leadName = (string) $payload['lead_name'];
$phoneRaw = (string) $payload['lead_phone_number'];
$branchId = (int) $payload['branch_id'];
$branchNameHint = (string) $payload['branch_name'];
$sourceName = (string) $payload['source_name'];
$campaign = (string) $payload['campaign'];
$remarks = (string) $payload['remarks'];
$externalId = (string) $payload['external_id'];
$formId = (string) $payload['form_id'];
$adId = (string) $payload['ad_id'];
$statusIn = $payload['status'] ?? null;
$amountIn = $payload['amount'] ?? null;

if ($leadName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'lead_name is required.']);
    exit;
}
$nameLen = function_exists('mb_strlen') ? mb_strlen($leadName) : strlen($leadName);
if ($nameLen > 255) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'lead_name must be at most 255 characters.']);
    exit;
}

$phone = leads_api_normalize_phone($phoneRaw);
if ($phone === '' || (strlen($phone) !== 12 && strlen($phone) !== 10)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'lead_phone_number is required (10-digit or 91XXXXXXXXXX).']);
    exit;
}
if (strlen($phone) === 10) {
    $phone = '91' . $phone;
}

$branch = leads_api_resolve_branch($branchId, $branchNameHint);
if (!($branch['ok'] ?? false)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => (string) ($branch['error'] ?? 'Invalid branch.')]);
    exit;
}

$statusId = is_numeric($statusIn) ? (int) $statusIn : leads_api_default_new_status_id();
if ($statusId <= 0) {
    $statusId = 1;
}

$amount = null;
if ($amountIn !== null && $amountIn !== '') {
    if (!is_numeric($amountIn)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'amount must be numeric when provided.']);
        exit;
    }
    $amount = (float) $amountIn;
}

if ($sourceName === '') {
    $sourceName = 'Lead Engine';
}
if (function_exists('mb_substr')) {
    $sourceName = mb_substr($sourceName, 0, 100);
    $campaign = mb_substr($campaign, 0, 255);
    $remarks = mb_substr($remarks, 0, 2000);
    $externalId = mb_substr($externalId, 0, 100);
    $formId = mb_substr($formId, 0, 100);
    $adId = mb_substr($adId, 0, 100);
} else {
    $sourceName = substr($sourceName, 0, 100);
    $campaign = substr($campaign, 0, 255);
    $remarks = substr($remarks, 0, 2000);
    $externalId = substr($externalId, 0, 100);
    $formId = substr($formId, 0, 100);
    $adId = substr($adId, 0, 100);
}

try {
    // Duplicate only when same external_id AND same phone.
    // Gallabox often sends a static external_id like "1"; phone must differ for a new lead.
    if ($externalId !== '') {
        $dup = db()->prepare(
            'SELECT id
             FROM allureone_meta_leads
             WHERE leadgen_id = :lid
               AND lead_phone_number = :phone
             ORDER BY id DESC
             LIMIT 1'
        );
        $dup->execute(['lid' => $externalId, 'phone' => $phone]);
        $existingId = (int) ($dup->fetchColumn() ?: 0);
        if ($existingId > 0) {
            echo json_encode([
                'ok' => true,
                'duplicate' => true,
                'id' => $existingId,
                'message' => 'Lead already exists for this external_id and phone.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $sql = 'INSERT INTO allureone_meta_leads
        (sourceName, Campaiign, branch_id, branch_name, lead_name, lead_phone_number, Created_Datetime, status, remarks, amount, leadgen_id, form_id, ad_id)
        VALUES
        (:sourceName, :campaign, :branch_id, :branch_name, :lead_name, :lead_phone_number, NOW(), :status, :remarks, :amount, :leadgen_id, :form_id, :ad_id)';
    $st = db()->prepare($sql);
    $st->execute([
        'sourceName' => $sourceName,
        'campaign' => $campaign !== '' ? $campaign : null,
        'branch_id' => (int) $branch['branch_id'],
        'branch_name' => (string) $branch['branch_name'],
        'lead_name' => $leadName,
        'lead_phone_number' => $phone,
        'status' => $statusId,
        'remarks' => $remarks !== '' ? $remarks : null,
        'amount' => $amount,
        'leadgen_id' => $externalId !== '' ? $externalId : null,
        'form_id' => $formId !== '' ? $formId : null,
        'ad_id' => $adId !== '' ? $adId : null,
    ]);
    $newId = (int) db()->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'ok' => true,
        'duplicate' => false,
        'id' => $newId,
        'lead_phone_number' => $phone,
        'branch_id' => (int) $branch['branch_id'],
        'branch_name' => (string) $branch['branch_name'],
        'status' => $statusId,
        'message' => 'Lead created.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('AllureOne leads_api insert failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not create lead.']);
}
