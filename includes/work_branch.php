<?php
declare(strict_types=1);

/**
 * Extra branch access for Leads, CRM, and Expense.
 * allureone_users.BranchId stays the default branch for every other page.
 *
 * @return array<int, string> branch id => label
 */
function work_branch_options(?array $user = null): array
{
    static $cache = [];
    if ($user === null) {
        $user = current_user();
    }
    if (!is_array($user)) {
        return [];
    }
    $userId = (int) ($user['id'] ?? 0);
    $roleId = (int) ($user['role_id'] ?? 0);
    $cacheKey = $userId . ':' . $roleId . ':' . (int) ($user['branch_id'] ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $rows = [];
    try {
        if ($roleId === ROLE_SUPERADMIN || $roleId === ROLE_ADMIN) {
            $rows = db()->query(
                'SELECT id, business_name, locality
                 FROM allureone_branch
                 WHERE isActive = 1
                 ORDER BY locality ASC, business_name ASC, id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $st = db()->prepare(
                'SELECT DISTINCT b.id, b.business_name, b.locality
                 FROM allureone_branch b
                 WHERE b.isActive = 1
                   AND (
                        b.id = :default_branch
                        OR b.id IN (
                            SELECT ub.branch_id
                            FROM allureone_user_branch ub
                            WHERE ub.user_id = :user_id
                        )
                   )
                 ORDER BY b.locality ASC, b.business_name ASC, b.id ASC'
            );
            $st->execute([
                'default_branch' => (int) ($user['branch_id'] ?? 0),
                'user_id' => $userId,
            ]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log('AllureOne work branch list failed: ' . $e->getMessage());
        $rows = [];
    }

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $branchId = (int) ($row['id'] ?? 0);
        if ($branchId <= 0) {
            continue;
        }
        $out[$branchId] = work_branch_label($branchId, (string) ($row['business_name'] ?? ''), (string) ($row['locality'] ?? ''));
    }
    $cache[$cacheKey] = $out;

    return $out;
}

function work_branch_label(int $branchId, string $businessName, string $locality): string
{
    $place = trim($locality);
    if ($place !== '') {
        return $place;
    }
    $name = trim($businessName);
    if ($name !== '') {
        return $name;
    }

    return 'Branch ' . $branchId;
}

function work_branch_allows_all(?array $user = null): bool
{
    if ($user === null) {
        $user = current_user();
    }
    if (!is_array($user)) {
        return false;
    }
    $roleId = (int) ($user['role_id'] ?? 0);

    return $roleId === ROLE_SUPERADMIN || $roleId === ROLE_ADMIN;
}

function work_branch_user_can(?array $user, int $branchId): bool
{
    if ($branchId <= 0) {
        return false;
    }
    $options = work_branch_options($user);

    return isset($options[$branchId]);
}

function work_branch_current_id(?array $user = null): int
{
    if ($user === null) {
        $user = current_user();
    }
    $options = work_branch_options($user);
    $allowsAll = work_branch_allows_all($user);
    $sessionIsSet = array_key_exists('work_branch_id', $_SESSION);
    $sessionId = $sessionIsSet ? (int) $_SESSION['work_branch_id'] : 0;
    if ($sessionId > 0 && isset($options[$sessionId])) {
        return $sessionId;
    }
    if ($allowsAll) {
        $_SESSION['work_branch_id'] = 0;

        return 0;
    }
    $defaultId = is_array($user) ? (int) ($user['branch_id'] ?? 0) : 0;
    if ($defaultId > 0 && isset($options[$defaultId])) {
        $_SESSION['work_branch_id'] = $defaultId;

        return $defaultId;
    }
    if (count($options) === 1) {
        $onlyId = 0;
        foreach ($options as $onlyId => $ignoredLabel) {
            break;
        }
        $onlyId = (int) $onlyId;
        if ($onlyId > 0) {
            $_SESSION['work_branch_id'] = $onlyId;

            return $onlyId;
        }
    }
    $_SESSION['work_branch_id'] = 0;

    return 0;
}

/**
 * @return list<int>
 */
function work_branch_stored_ids(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    try {
        $st = db()->prepare('SELECT branch_id FROM allureone_user_branch WHERE user_id = :uid ORDER BY branch_id ASC');
        $st->execute(['uid' => $userId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return $out;
    } catch (Throwable $e) {
        error_log('AllureOne work branch load failed: ' . $e->getMessage());

        return [];
    }
}

/**
 * Replace a user's extra branch rows. The default branch is always kept when it is active.
 *
 * @param list<mixed> $postedIds
 */
function work_branch_save(int $userId, int $defaultBranchId, array $postedIds): void
{
    if ($userId <= 0) {
        return;
    }
    $wanted = [];
    if ($defaultBranchId > 0) {
        $wanted[$defaultBranchId] = true;
    }
    foreach ($postedIds as $raw) {
        $id = (int) $raw;
        if ($id > 0) {
            $wanted[$id] = true;
        }
    }
    $valid = [];
    if ($wanted !== []) {
        $ids = array_keys($wanted);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare(
            'SELECT id FROM allureone_branch WHERE isActive = 1 AND id IN (' . $placeholders . ')'
        );
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $valid[] = $id;
            }
        }
    }
    $del = db()->prepare('DELETE FROM allureone_user_branch WHERE user_id = :uid');
    $del->execute(['uid' => $userId]);
    if ($valid === []) {
        return;
    }
    $ins = db()->prepare('INSERT INTO allureone_user_branch (user_id, branch_id) VALUES (:uid, :bid)');
    foreach ($valid as $branchId) {
        $ins->execute(['uid' => $userId, 'bid' => $branchId]);
    }
}
