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

/**
 * @return array<int, array<string, mixed>>
 */
function franchise_leads_list(): array
{
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
                campaign_id
            FROM allureone_franchise_leads';
    $st = db()->query($sql);
    foreach ($st->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['lead_key'] = 'db-' . (int) ($row['id'] ?? 0);
        $rows[] = $row;
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
        $rows[] = [
            'id' => (int) ($wrow['id'] ?? 0),
            'lead_key' => 'web-' . (int) ($wrow['id'] ?? 0),
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
                    campaign_id
                FROM allureone_franchise_leads
                WHERE id = :id
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

        return [
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
        ];
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
$listQuery = [];
if ($sourceFilter !== '') {
    $listQuery['source'] = $sourceFilter;
}
if ($page > 1) {
    $listQuery['page'] = (string) $page;
}
$listUrl = 'Franchise-leads.php' . ($listQuery === [] ? '' : ('?' . http_build_query($listQuery, '', '&', PHP_QUERY_RFC3986)));

try {
    if ($detailKey !== '') {
        $detailRow = franchise_leads_detail($detailKey);
    } else {
        $rows = franchise_leads_list();
        if ($sourceFilter !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($sourceFilter): bool {
                return trim((string) ($row['source_name'] ?? '')) === $sourceFilter;
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
                        </tbody>
                    </table>
                    <p style="margin-top:0.75rem;margin-bottom:0"><a class="btn btn--ghost" href="<?= e($listUrl) ?>">Back</a></p>
                </div>
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
                <div class="form__row form__row--submit">
                    <button type="submit" class="btn btn--primary">Apply</button>
                </div>
            </form>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Full Name</th>
                            <th>City</th>
                            <th>Mobile</th>
                            <th>Source</th>
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
                                if ($page > 1) {
                                    $detailQuery['page'] = (string) $page;
                                }
                                ?>
                                <td><a class="link--underlined" href="Franchise-leads.php?<?= e(http_build_query($detailQuery, '', '&', PHP_QUERY_RFC3986)) ?>"><?= e((string) ($row['full_name'] ?? '')) ?></a></td>
                                <td><?= e((string) ($row['city'] ?? '')) ?></td>
                                <td><?= e((string) ($row['phone_number'] ?? '')) ?></td>
                                <td><?= e((string) ($row['source_name'] ?? '')) ?></td>
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
