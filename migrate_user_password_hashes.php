<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * One-time migration:
 * Convert plaintext passwords in allureone_users to password_hash format.
 *
 * Run:
 *   php migrate_user_password_hashes.php
 */

function allureone_password_is_hashed(string $value): bool
{
    $info = password_get_info($value);
    if (($info['algo'] ?? 0) !== 0) {
        return true;
    }

    // Fallback pattern checks for legacy bcrypt prefixes.
    return preg_match('/^\$(2y|2b|2a|argon2i|argon2id)\$/', $value) === 1;
}

$pdo = db();
$rows = $pdo->query('SELECT id, loginname, password FROM allureone_users')->fetchAll();

$checked = 0;
$alreadyHashed = 0;
$converted = 0;
$skippedEmpty = 0;
$errors = 0;

$update = $pdo->prepare('UPDATE allureone_users SET password = :p WHERE id = :id');

foreach ($rows as $row) {
    $checked++;
    $id = (int) ($row['id'] ?? 0);
    $login = (string) ($row['loginname'] ?? '');
    $password = (string) ($row['password'] ?? '');

    if ($password === '') {
        $skippedEmpty++;
        echo "[skip-empty] id={$id} login={$login}" . PHP_EOL;
        continue;
    }

    if (allureone_password_is_hashed($password)) {
        $alreadyHashed++;
        continue;
    }

    $newHash = password_hash($password, PASSWORD_DEFAULT);
    if ($newHash === false || $newHash === '') {
        $errors++;
        echo "[error-hash] id={$id} login={$login}" . PHP_EOL;
        continue;
    }

    try {
        $update->execute([
            'p' => $newHash,
            'id' => $id,
        ]);
        $converted++;
        echo "[converted] id={$id} login={$login}" . PHP_EOL;
    } catch (PDOException $e) {
        $errors++;
        echo "[error-db] id={$id} login={$login} msg=" . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL;
echo "Checked: {$checked}" . PHP_EOL;
echo "Already hashed: {$alreadyHashed}" . PHP_EOL;
echo "Converted: {$converted}" . PHP_EOL;
echo "Skipped empty: {$skippedEmpty}" . PHP_EOL;
echo "Errors: {$errors}" . PHP_EOL;

