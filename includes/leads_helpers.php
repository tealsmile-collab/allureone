<?php
declare(strict_types=1);

/**
 * Shared Meta Leads helpers (list/filters).
 */

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

/**
 * Normalize lead source for list display: Organic / Insta-Fb / other non-empty label.
 */
function leads_source_display_label(?string $sourceName, ?string $campaign): string
{
    $src = strtolower(trim((string) ($sourceName ?? '')));
    $camp = strtolower(trim((string) ($campaign ?? '')));
    if ($src === 'insta-fb') {
        return 'Insta-Fb';
    }
    if ($src === 'organic' || $camp === 'organic') {
        return 'Organic';
    }
    $raw = trim((string) ($sourceName ?? ''));

    return $raw !== '' ? $raw : '';
}

/** Status cell text: "New (Organic)", "New (Insta-Fb)", or status alone. */
function leads_status_with_source(string $statusLabel, ?string $sourceName, ?string $campaign): string
{
    $status = trim($statusLabel);
    if ($status === '') {
        $status = '—';
    }
    $source = leads_source_display_label($sourceName, $campaign);
    if ($source === '') {
        return $status;
    }

    return $status . ' (' . $source . ')';
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

/**
 * IST calendar month bounds for lead created date; returns inclusive UTC bounds for DB comparison.
 *
 * @return array{0:string,1:string}|null
 */
function leads_created_month_boundaries_utc(int $year, int $month): ?array
{
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        return null;
    }
    try {
        $tz = new DateTimeZone('Asia/Kolkata');
        $utc = new DateTimeZone('UTC');
        $start = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $end = new DateTime($start->format('Y-m-t') . ' 23:59:59', $tz);
        $start->setTimezone($utc);
        $end->setTimezone($utc);

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    } catch (Exception $e) {
        return null;
    }
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
