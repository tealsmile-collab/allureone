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
    $sql = 'SELECT
                id,
                DateTime AS lead_datetime,
                FULL_NAME AS full_name,
                CITY AS city,
                PHONE_NUMBER AS phone_number,
                sourceName AS source_name
            FROM allureone_franchise_leads
            ORDER BY DateTime DESC, id DESC';
    $st = db()->query($sql);

    return $st->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function franchise_leads_detail(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $sql = 'SELECT
                id,
                FULL_NAME,
                PHONE_NUMBER,
                CITY,
                investment_budget,
                preferred_timeline,
                experience_in_the_wellness,
                property_for_the_wellness,
                DateTime,
                form_id,
                campaign_id
            FROM allureone_franchise_leads
            WHERE id = :id
            LIMIT 1';
    $st = db()->prepare($sql);
    $st->execute(['id' => $id]);
    $row = $st->fetch();

    return is_array($row) ? $row : null;
}

$detailId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$rows = [];
$detailRow = null;
$loadError = '';

try {
    if ($detailId > 0) {
        $detailRow = franchise_leads_detail($detailId);
    } else {
        $rows = franchise_leads_list();
    }
} catch (PDOException $e) {
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
        <?php elseif ($detailId > 0): ?>
            <?php if ($detailRow === null): ?>
                <p class="empty">Lead not found.</p>
                <p style="padding:0 1.25rem 1.25rem;margin:0"><a class="btn btn--ghost" href="Franchise-leads.php">Back</a></p>
            <?php else: ?>
                <div style="padding:1.25rem">
                    <table class="data">
                        <tbody>
                            <tr><th>Date</th><td><?= e(franchise_leads_format_datetime_ist((string) ($detailRow['DateTime'] ?? ''))) ?></td></tr>
                            <tr><th>Full Name</th><td><?= e((string) ($detailRow['FULL_NAME'] ?? '')) ?></td></tr>
                            <tr><th>Mobile</th><td><?= e((string) ($detailRow['PHONE_NUMBER'] ?? '')) ?></td></tr>
                            <tr><th>City</th><td><?= e((string) ($detailRow['CITY'] ?? '')) ?></td></tr>
                            <tr><th>Investment Budget</th><td><?= e((string) ($detailRow['investment_budget'] ?? '')) ?></td></tr>
                            <tr><th>Preferred Timeline</th><td><?= e((string) ($detailRow['preferred_timeline'] ?? '')) ?></td></tr>
                            <tr><th>Experience In Wellness</th><td><?= e((string) ($detailRow['experience_in_the_wellness'] ?? '')) ?></td></tr>
                            <tr><th>Property For Wellness</th><td><?= e((string) ($detailRow['property_for_the_wellness'] ?? '')) ?></td></tr>
                            <tr><th>Form ID</th><td><?= e((string) ($detailRow['form_id'] ?? '')) ?></td></tr>
                            <tr><th>Campaign ID</th><td><?= e((string) ($detailRow['campaign_id'] ?? '')) ?></td></tr>
                        </tbody>
                    </table>
                    <p style="margin-top:0.75rem;margin-bottom:0"><a class="btn btn--ghost" href="Franchise-leads.php">Back</a></p>
                </div>
            <?php endif; ?>
        <?php elseif ($rows === []): ?>
            <p class="empty">No franchise leads found.</p>
        <?php else: ?>
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
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e(franchise_leads_format_datetime_ist((string) ($row['lead_datetime'] ?? ''))) ?></td>
                                <td><a class="link--underlined" href="Franchise-leads.php?id=<?= (int) ($row['id'] ?? 0) ?>"><?= e((string) ($row['full_name'] ?? '')) ?></a></td>
                                <td><?= e((string) ($row['city'] ?? '')) ?></td>
                                <td><?= e((string) ($row['phone_number'] ?? '')) ?></td>
                                <td><?= e((string) ($row['source_name'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
