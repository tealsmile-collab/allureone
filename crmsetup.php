<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
$user = current_user();
$roleId = (int) ($user['role_id'] ?? 0);
if ($roleId !== ROLE_SUPERADMIN && $roleId !== ROLE_ADMIN) {
    http_response_code(403);
    exit('Forbidden');
}
$isSuperadmin = ($roleId === ROLE_SUPERADMIN);

/** @var list<array<string,mixed>> */
$branches = [];
$loadError = '';
$flash = null;
$selectedBranchId = isset($_GET['branch']) ? (int) $_GET['branch'] : 0;
$selectedSegmentId = isset($_GET['segment']) ? (int) $_GET['segment'] : 0;
$segmentPage = max(1, (int) ($_GET['page'] ?? 1));
$segmentLimit = 10;
$segmentFetchedCount = 0;
$segmentHasNextPage = false;
$selectedBranch = null;
$selectedSegmentName = '';
$selectedSegmentCustomerCount = 0;
$importSkippedUserIds = [];
/** @var list<array{id:int,name:string,customer_count:int}> */
$segmentList = [];
/** @var list<array{name:string,number:string,last_visit:string,status:string,user_id:int}> */
$segmentCustomers = [];

function crmsetup_branch_session_key(int $branchId): string
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
        error_log('AllureOne crmsetup session key lookup failed: ' . $e->getMessage());
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crm_import_branch'])) {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Invalid session. Please refresh and try again.'];
    } else {
        $branchId = isset($_POST['branch_id']) ? (int) $_POST['branch_id'] : 0;
        $segmentId = isset($_POST['segment_id']) ? (int) $_POST['segment_id'] : 0;
        $selectedBranchId = $branchId > 0 ? $branchId : $selectedBranchId;
        $selectedSegmentId = $segmentId > 0 ? $segmentId : $selectedSegmentId;
        if ($branchId <= 0 || $segmentId <= 0) {
            $flash = ['type' => 'error', 'text' => 'Invalid branch or segment selected.'];
        } else {
            $sessionKey = crmsetup_branch_session_key($branchId);
            if ($sessionKey === '') {
                $flash = ['type' => 'error', 'text' => 'Dingg session key not configured for selected branch.'];
            } else {
                try {
                    $allRows = [];
                    $apiPage = 1;
                    $apiLimit = 100;
                    while (true) {
                        $url = 'https://api.dingg.app/api/v1/marketing/segment/customers?' . http_build_query(
                            ['id' => (string) $segmentId, 'page' => (string) $apiPage, 'limit' => (string) $apiLimit],
                            '',
                            '&',
                            PHP_QUERY_RFC3986
                        );
                        $resp = dingg_http_request_authenticated('GET', $url, $sessionKey, null);
                        $http = (int) ($resp['http'] ?? 0);
                        $body = (string) ($resp['body'] ?? '');
                        if ($http < 200 || $http >= 300 || $body === '' || dingg_response_looks_unauthorized($http, $body)) {
                            throw new RuntimeException('Could not fetch segment customers from API.');
                        }
                        $json = json_decode($body, true);
                        $batch = is_array($json) && isset($json['data']) && is_array($json['data']) ? $json['data'] : [];
                        foreach ($batch as $row) {
                            if (is_array($row)) {
                                $allRows[] = $row;
                            }
                        }
                        if (count($batch) < $apiLimit) {
                            break;
                        }
                        $apiPage++;
                        if ($apiPage > 1000) {
                            break;
                        }
                    }

                    $candidateMap = [];
                    foreach ($allRows as $row) {
                        $uid = (int) ($row['user_id'] ?? 0);
                        if ($uid > 0) {
                            $candidateMap[$uid] = $row;
                        }
                    }
                    $candidateUserIds = array_keys($candidateMap);
                    if ($candidateUserIds === []) {
                        $flash = ['type' => 'error', 'text' => 'No valid users returned from segment API.'];
                    } else {
                        $pdoMain = db();
                        $existingIds = [];
                        $ph = implode(',', array_fill(0, count($candidateUserIds), '?'));
                        $chkSql = 'SELECT user_id FROM allureone_crm WHERE user_id IN (' . $ph . ')';
                        $chkStmt = $pdoMain->prepare($chkSql);
                        $chkStmt->execute($candidateUserIds);
                        foreach ($chkStmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
                            $eid = (int) ($er['user_id'] ?? 0);
                            if ($eid > 0) {
                                $existingIds[$eid] = true;
                            }
                        }

                        $insSql = 'INSERT INTO allureone_crm
                            (user_id, Mobile, fname, Gender, client_code, last_visit, branch_id, crm_status, remarks, followup_datetime, last_contacted_datetime, created_datetime, update_datetime)
                            VALUES
                            (:user_id, :mobile, :fname, :gender, :client_code, :last_visit, :branch_id, :crm_status, :remarks, :followup_datetime, :last_contacted_datetime, NOW(), NOW())';
                        $insStmt = $pdoMain->prepare($insSql);
                        $insertedCount = 0;
                        foreach ($candidateMap as $uid => $row) {
                            if (isset($existingIds[$uid])) {
                                $importSkippedUserIds[] = $uid;
                                continue;
                            }
                            $u = isset($row['user']) && is_array($row['user']) ? $row['user'] : [];
                            $mobile = trim((string) ($u['mobile'] ?? $row['mobile'] ?? ''));
                            $fname = trim((string) ($u['fname'] ?? $row['fname'] ?? ''));
                            $gender = trim((string) ($u['gender'] ?? $row['gender'] ?? ''));
                            $clientCode = trim((string) ($u['client_code'] ?? ''));
                            $lastVisitRaw = trim((string) ($row['last_visit'] ?? ''));
                            $lastVisit = null;
                            if ($lastVisitRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastVisitRaw) === 1) {
                                $lastVisit = $lastVisitRaw . ' 00:00:00';
                            }
                            $insStmt->execute([
                                'user_id' => $uid,
                                'mobile' => $mobile !== '' ? $mobile : null,
                                'fname' => $fname !== '' ? $fname : null,
                                'gender' => $gender !== '' ? $gender : null,
                                'client_code' => $clientCode !== '' ? $clientCode : null,
                                'last_visit' => $lastVisit,
                                'branch_id' => $branchId,
                                'crm_status' => 1,
                                'remarks' => null,
                                'followup_datetime' => null,
                                'last_contacted_datetime' => null,
                            ]);
                            $insertedCount++;
                        }
                        $flash = [
                            'type' => 'ok',
                            'text' => 'Imported ' . $insertedCount . ' users into CRM. Skipped existing: ' . count($importSkippedUserIds) . '.',
                        ];
                    }
                } catch (Throwable $e) {
                    error_log('AllureOne crmsetup import failed: ' . $e->getMessage());
                    $flash = ['type' => 'error', 'text' => 'Could not import segment users.'];
                }
            }
        }
    }
}

try {
    $stmt = db()->query(
        'SELECT id, business_name, locality, isActive
         FROM allureone_branch
         ORDER BY id ASC'
    );
    $branches = $stmt->fetchAll();
    if ($selectedBranchId > 0) {
        foreach ($branches as $b) {
            if ((int) ($b['id'] ?? 0) === $selectedBranchId) {
                $selectedBranch = $b;
                break;
            }
        }
        if (!is_array($selectedBranch)) {
            $flash = ['type' => 'error', 'text' => 'Selected branch not found.'];
            $selectedBranchId = 0;
            $selectedSegmentId = 0;
        }
    }
} catch (PDOException $e) {
    error_log('AllureOne crmsetup branch list failed: ' . $e->getMessage());
    $loadError = 'Could not load branch records.';
}

if ($loadError === '' && is_array($selectedBranch)) {
    $sessionKey = crmsetup_branch_session_key((int) ($selectedBranch['id'] ?? 0));
    if ($sessionKey === '') {
        $flash = ['type' => 'error', 'text' => 'Dingg session key not configured for selected branch.'];
    } else {
        $segResp = dingg_http_request_authenticated('GET', 'https://api.dingg.app/api/v1/marketing/segments', $sessionKey, null);
        $segHttp = (int) ($segResp['http'] ?? 0);
        $segBody = (string) ($segResp['body'] ?? '');
        if ($segHttp < 200 || $segHttp >= 300 || $segBody === '' || dingg_response_looks_unauthorized($segHttp, $segBody)) {
            $flash = ['type' => 'error', 'text' => 'Could not fetch segments list from API.'];
        } else {
            $segJson = json_decode($segBody, true);
            $segRows = is_array($segJson) && isset($segJson['data']) && is_array($segJson['data']) ? $segJson['data'] : [];
            foreach ($segRows as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $segId = (int) ($s['id'] ?? 0);
                $segName = trim((string) ($s['name'] ?? ''));
                $segCount = (int) ($s['customer_count'] ?? 0);
                $segmentList[] = [
                    'id' => $segId,
                    'name' => $segName,
                    'customer_count' => $segCount,
                ];
                if ($selectedSegmentId > 0 && $segId === $selectedSegmentId) {
                    $selectedSegmentName = $segName;
                    $selectedSegmentCustomerCount = $segCount;
                }
            }
        }

        if ($selectedSegmentId > 0) {
            $custUrl = 'https://api.dingg.app/api/v1/marketing/segment/customers?' . http_build_query(
                ['id' => (string) $selectedSegmentId, 'page' => (string) $segmentPage, 'limit' => (string) $segmentLimit],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
            $custResp = dingg_http_request_authenticated('GET', $custUrl, $sessionKey, null);
            $custHttp = (int) ($custResp['http'] ?? 0);
            $custBody = (string) ($custResp['body'] ?? '');
            if ($custHttp < 200 || $custHttp >= 300 || $custBody === '' || dingg_response_looks_unauthorized($custHttp, $custBody)) {
                $flash = ['type' => 'error', 'text' => 'Could not fetch segment customers from API.'];
            } else {
                $custJson = json_decode($custBody, true);
                $rows = is_array($custJson) && isset($custJson['data']) && is_array($custJson['data']) ? $custJson['data'] : [];
                foreach ($rows as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $u = isset($r['user']) && is_array($r['user']) ? $r['user'] : [];
                    $segmentCustomers[] = [
                        'name' => trim((string) ($u['fname'] ?? $r['fname'] ?? '')),
                        'number' => trim((string) ($u['mobile'] ?? $r['mobile'] ?? '')),
                        'last_visit' => trim((string) ($r['last_visit'] ?? '')),
                        'status' => 'Lost',
                        'user_id' => (int) ($r['user_id'] ?? 0),
                    ];
                }
                $fetchedThisPage = count($segmentCustomers);
                $segmentFetchedCount = (($segmentPage - 1) * $segmentLimit) + $fetchedThisPage;
                $segmentHasNextPage = ($fetchedThisPage === $segmentLimit);
            }
        }
    }
}

$pageTitle = 'CRM Segments';
$activeNav = 'crm_setup';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="card">
    <div class="card__head">
        <span>CRM Segments</span>
    </div>
    <div class="card__body">
        <?php if ($flash !== null): ?>
            <p class="alert alert--<?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'error' ?>" style="margin:1rem 1.25rem 0">
                <?= e((string) ($flash['text'] ?? '')) ?>
            </p>
        <?php endif; ?>
        <?php if ($loadError !== ''): ?>
            <p class="alert alert--error" style="margin:1rem 1.25rem"><?= e($loadError) ?></p>
        <?php elseif ($branches === []): ?>
            <p class="empty">No branches found.</p>
        <?php elseif (!is_array($selectedBranch)): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Branch ID</th>
                            <th>Branch Name</th>
                            <th>Locality</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($branches as $branch): ?>
                            <tr>
                                <td><?= (int) ($branch['id'] ?? 0) ?></td>
                                <td><a class="link--underlined" href="crmsetup.php?<?= e(http_build_query(['branch' => (int) ($branch['id'] ?? 0)])) ?>"><?= e((string) ($branch['business_name'] ?? '')) ?></a></td>
                                <td><?= e((string) ($branch['locality'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="padding:1rem 1.25rem">
                <p style="margin:0 0 0.9rem">
                    <a class="btn btn--ghost" href="crmsetup.php">Back</a>
                </p>
                <table class="data" style="margin-bottom:0.9rem">
                    <tbody>
                        <tr><th>Branch ID</th><td><?= (int) ($selectedBranch['id'] ?? 0) ?></td></tr>
                        <tr><th>Branch Name</th><td><?= e((string) ($selectedBranch['business_name'] ?? '')) ?></td></tr>
                        <tr><th>Locality</th><td><?= e((string) ($selectedBranch['locality'] ?? '')) ?></td></tr>
                    </tbody>
                </table>

                <?php if ($selectedSegmentId <= 0): ?>
                    <h3 style="margin:0.35rem 0 0.55rem;font-size:1rem">Segments List</h3>
                    <?php if ($segmentList === []): ?>
                        <p class="main__meta">No segments found for this branch.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Client Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($segmentList as $seg): ?>
                                        <tr data-segment-id="<?= (int) ($seg['id'] ?? 0) ?>">
                                            <td>
                                                <?php if ($isSuperadmin): ?>
                                                    <a class="link--underlined" href="crmsetup.php?<?= e(http_build_query(['branch' => (int) ($selectedBranch['id'] ?? 0), 'segment' => (int) ($seg['id'] ?? 0), 'page' => 1])) ?>">
                                                        <?= e((string) ($seg['name'] ?? '')) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?= e((string) ($seg['name'] ?? '')) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= (int) ($seg['customer_count'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($selectedSegmentId > 0): ?>
                    <p style="margin:0 0 0.65rem">
                        <a class="btn btn--ghost" href="crmsetup.php?<?= e(http_build_query(['branch' => (int) ($selectedBranch['id'] ?? 0)])) ?>">Back to Segments List</a>
                    </p>
                    <h3 style="margin:1rem 0 0.55rem;font-size:1rem">Segment Customers</h3>
                    <p class="main__meta" style="margin:0 0 0.55rem">
                        <strong>Segment:</strong> <?= e($selectedSegmentName !== '' ? $selectedSegmentName : ('#' . $selectedSegmentId)) ?>
                        &nbsp;|&nbsp;
                        <strong>Customer count:</strong> <?= (int) $selectedSegmentCustomerCount ?>
                    </p>
                    <form method="post" action="crmsetup.php?<?= e(http_build_query(['branch' => (int) ($selectedBranch['id'] ?? 0), 'segment' => $selectedSegmentId, 'page' => $segmentPage])) ?>" style="margin:0 0 0.75rem">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="branch_id" value="<?= (int) ($selectedBranch['id'] ?? 0) ?>">
                            <input type="hidden" name="segment_id" value="<?= (int) $selectedSegmentId ?>">
                            <button class="btn btn--primary" style="background:#15803d;border-color:#15803d" type="submit" name="crm_import_branch" value="1">Import</button>
                    </form>
                    <?php if ($importSkippedUserIds !== []): ?>
                        <p class="main__meta" style="margin:0 0 0.7rem">
                            Not imported user_id(s): <?= e(implode(',', array_map(static fn($v): string => (string) $v, $importSkippedUserIds))) ?>
                        </p>
                    <?php endif; ?>

                    <p class="main__meta" style="margin:0.15rem 0 0.6rem">Fetched records upto page <?= (int) $segmentPage ?>: <strong><?= (int) $segmentFetchedCount ?></strong></p>
                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Number</th>
                                    <th>Last Visit</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($segmentCustomers === []): ?>
                                    <tr><td colspan="4" style="color:var(--muted)">No records on this page.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($segmentCustomers as $c): ?>
                                        <tr data-user-id="<?= (int) ($c['user_id'] ?? 0) ?>">
                                            <td><?= e((string) ($c['name'] ?? '')) ?></td>
                                            <td><?= e((string) ($c['number'] ?? '')) ?></td>
                                            <td><?= e((string) ($c['last_visit'] ?? '')) ?></td>
                                            <td><?= e((string) ($c['status'] ?? 'Lost')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.65rem;flex-wrap:wrap;margin-top:0.8rem">
                        <?php if ($segmentPage > 1): ?>
                            <a class="btn btn--ghost" href="crmsetup.php?<?= e(http_build_query(['branch' => (int) ($selectedBranch['id'] ?? 0), 'segment' => $selectedSegmentId, 'page' => $segmentPage - 1])) ?>">Previous page</a>
                        <?php endif; ?>
                        <?php if ($segmentHasNextPage): ?>
                            <a class="btn btn--ghost" href="crmsetup.php?<?= e(http_build_query(['branch' => (int) ($selectedBranch['id'] ?? 0), 'segment' => $selectedSegmentId, 'page' => $segmentPage + 1])) ?>">Next page</a>
                        <?php else: ?>
                            <span class="main__meta">No more records (received less than 10 rows).</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
