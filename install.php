<?php
declare(strict_types=1);

/**
 * One-time setup: creates tables and seed data.
 * Open in browser or run: php install.php
 * Remove or protect this file after setup.
 */

$config = require __DIR__ . '/config.php';
$c = $config['db'];
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['database'], $c['charset']);

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
}

function out(string $msg, bool $isCli): void
{
    if ($isCli) {
        echo $msg . PHP_EOL;
    } else {
        echo $msg;
    }
}

function outHtml(string $html, bool $isCli): void
{
    if ($isCli) {
        echo strip_tags($html) . PHP_EOL;
    } else {
        echo $html;
    }
}

try {
    $pdo = new PDO($dsn, $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    $detail = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    outHtml(
        '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Install failed</title></head><body>',
        $isCli
    );
    outHtml('<h1>Database connection failed</h1>', $isCli);
    outHtml("<p>Check <code>config.php</code> (host, database name, user, password).</p>", $isCli);
    outHtml("<p><strong>Details:</strong> {$detail}</p>", $isCli);
    outHtml('<p>Typical causes: wrong password, database name, firewall blocking the server IP, or remote MySQL not enabled for your host.</p>', $isCli);
    outHtml('</body></html>', $isCli);
    exit(1);
}

$statements = [
    'roles' => <<<SQL
CREATE TABLE IF NOT EXISTS allureone_roles (
  id INT NOT NULL,
  RoleName VARCHAR(100) NOT NULL,
  isActive TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    ,
    'branch' => <<<SQL
CREATE TABLE IF NOT EXISTS allureone_branch (
  id INT NOT NULL,
  business_name VARCHAR(255) NOT NULL,
  locality VARCHAR(255) NULL,
  vendor_id INT NOT NULL,
  isActive TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_branch_vendor (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    ,
    'users' => <<<SQL
CREATE TABLE IF NOT EXISTS allureone_users (
  id INT NOT NULL AUTO_INCREMENT,
  loginname VARCHAR(20) NOT NULL,
  password VARCHAR(255) NOT NULL,
  FullName VARCHAR(255) NOT NULL,
  BranchId INT NULL,
  RoleId INT NOT NULL,
  isactive TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_allureone_login (loginname),
  KEY idx_user_branch (BranchId),
  KEY idx_user_role (RoleId),
  CONSTRAINT fk_allureone_user_branch FOREIGN KEY (BranchId) REFERENCES allureone_branch (id),
  CONSTRAINT fk_allureone_user_role FOREIGN KEY (RoleId) REFERENCES allureone_roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    ,
    'giftcard' => <<<SQL
CREATE TABLE IF NOT EXISTS allureone_giftcard (
  id INT NOT NULL AUTO_INCREMENT,
  GiftCode VARCHAR(100) NOT NULL,
  Price DECIMAL(12,2) NOT NULL,
  SenderName VARCHAR(255) NULL,
  SenderMobile VARCHAR(50) NULL,
  SenderEmail VARCHAR(255) NULL,
  RecipientName VARCHAR(255) NULL,
  RecipientMobile VARCHAR(50) NULL,
  RecipientEmail VARCHAR(255) NULL,
  GiftMessage TEXT NULL,
  BranchName TEXT NULL,
  BranchId INT NULL,
  PurchaseDate DATETIME NULL,
  PaymentStatus VARCHAR(100) NULL,
  isActive TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_gc_branch (BranchId),
  KEY idx_gc_purchase (PurchaseDate),
  CONSTRAINT fk_allureone_gc_branch FOREIGN KEY (BranchId) REFERENCES allureone_branch (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    ,
    'keys' => <<<SQL
CREATE TABLE IF NOT EXISTS allureone_keys (
  id INT NOT NULL AUTO_INCREMENT,
  key_name VARCHAR(64) NOT NULL,
  key_value LONGTEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_allureone_keys_name (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    ,
];

$lastSql = '';
try {
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $fkUserBranch = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'allureone_users'
           AND CONSTRAINT_NAME = 'fk_allureone_user_branch'"
    )->fetchColumn();
    if ($fkUserBranch > 0) {
        $pdo->exec('ALTER TABLE allureone_users DROP FOREIGN KEY fk_allureone_user_branch');
    }

    $fkGiftBranch = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'allureone_giftcard'
           AND CONSTRAINT_NAME = 'fk_allureone_gc_branch'"
    )->fetchColumn();
    if ($fkGiftBranch > 0) {
        $pdo->exec('ALTER TABLE allureone_giftcard DROP FOREIGN KEY fk_allureone_gc_branch');
    }

    $pdo->exec('DROP TABLE IF EXISTS allureone_branch');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    foreach ($statements as $step => $sql) {
        $lastSql = $sql;
        $pdo->exec($sql);
    }

    $roles = [
        [1, 'Superadmin'],
        [2, 'admin'],
        [3, 'manager'],
        [4, 'jr. manager'],
        [5, 'therapist'],
        [6, 'housekeeping'],
    ];
    $ri = $pdo->prepare(
        'INSERT INTO allureone_roles (id, RoleName, isActive) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE RoleName = VALUES(RoleName), isActive = 1'
    );
    foreach ($roles as $r) {
        $ri->execute($r);
    }

    $branches = [
        [3780, 'Allure Thai Spa And Wellness', 'Thane', 11179, 1],
        [2973, 'Allure Thai Spa & Wellness', 'Borivali (WEST)', 11179, 1],
        [2935, 'Allure Thai Spa & Wellness', 'Powai', 11179, 1],
        [3781, 'Allure Thai Spa & Wellness', 'Bhandup West', 11179, 1],
        [3782, 'Allure Thai Spa & Wellness', 'Nerul seawood', 11179, 1],
        [3000, 'Allure Thai Spa & Wellness', 'Andheri East', 11179, 1],
        [4185, 'Allure Thai Spa & Wellness', 'Malad West', 11179, 1],
        [4274, 'MANAS BOUTIQUE HOTEL & SPA', 'Gayalwadi', 11179, 1],
    ];
    $bi = $pdo->prepare(
        'INSERT INTO allureone_branch (id, business_name, locality, vendor_id, isActive) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE business_name = VALUES(business_name), locality = VALUES(locality), vendor_id = VALUES(vendor_id), isActive = VALUES(isActive)'
    );
    foreach ($branches as $b) {
        $bi->execute($b);
    }

    $pdo->exec(
        'UPDATE allureone_users u
         LEFT JOIN allureone_branch b ON b.id = u.BranchId
         SET u.BranchId = NULL
         WHERE u.BranchId IS NOT NULL AND b.id IS NULL'
    );
    $pdo->exec(
        'UPDATE allureone_giftcard g
         LEFT JOIN allureone_branch b ON b.id = g.BranchId
         SET g.BranchId = NULL
         WHERE g.BranchId IS NOT NULL AND b.id IS NULL'
    );

    $fkUserBranch = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'allureone_users'
           AND CONSTRAINT_NAME = 'fk_allureone_user_branch'"
    )->fetchColumn();
    if ($fkUserBranch === 0) {
        $pdo->exec(
            'ALTER TABLE allureone_users
             ADD CONSTRAINT fk_allureone_user_branch
             FOREIGN KEY (BranchId) REFERENCES allureone_branch (id)'
        );
    }

    $fkGiftBranch = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'allureone_giftcard'
           AND CONSTRAINT_NAME = 'fk_allureone_gc_branch'"
    )->fetchColumn();
    if ($fkGiftBranch === 0) {
        $pdo->exec(
            'ALTER TABLE allureone_giftcard
             ADD CONSTRAINT fk_allureone_gc_branch
             FOREIGN KEY (BranchId) REFERENCES allureone_branch (id)'
        );
    }

    $adminPass = password_hash('Allure@011225', PASSWORD_DEFAULT);
    $chk = $pdo->query("SELECT COUNT(*) FROM allureone_users WHERE loginname = 'admin'")->fetchColumn();
    if ((int) $chk === 0) {
        $ui = $pdo->prepare(
            'INSERT INTO allureone_users (loginname, password, FullName, BranchId, RoleId, isactive)
             VALUES (?, ?, ?, 3782, 1, 1)'
        );
        $ui->execute(['admin', $adminPass, 'Administrator']);
    }

    $counts = [
        'allureone_roles' => (int) $pdo->query('SELECT COUNT(*) FROM allureone_roles')->fetchColumn(),
        'allureone_branch' => (int) $pdo->query('SELECT COUNT(*) FROM allureone_branch')->fetchColumn(),
        'allureone_users' => (int) $pdo->query('SELECT COUNT(*) FROM allureone_users')->fetchColumn(),
        'allureone_giftcard' => (int) $pdo->query('SELECT COUNT(*) FROM allureone_giftcard')->fetchColumn(),
        'allureone_keys' => (int) $pdo->query('SELECT COUNT(*) FROM allureone_keys')->fetchColumn(),
    ];
} catch (PDOException $e) {
    $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    $code = htmlspecialchars((string) $e->getCode(), ENT_QUOTES, 'UTF-8');
    if ($isCli) {
        echo "Installation failed [{$code}]: {$e->getMessage()}\n";
        echo "Grant CREATE, ALTER, INSERT, INDEX on the database, or import sql/full_install.sql via MySQL client.\n";
        exit(1);
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Install failed</title>
        <style>body{font-family:system-ui;max-width:720px;margin:2rem auto;padding:0 1rem;}
        code{background:#f3f4f6;padding:2px 6px;border-radius:4px;}
        pre{overflow:auto;background:#1e293b;color:#e2e8f0;padding:1rem;border-radius:8px;font-size:12px;}</style></head><body>';
    echo '<h1>Installation failed</h1>';
    echo '<p>The database user may need <strong>CREATE</strong>, <strong>ALTER</strong>, <strong>INSERT</strong>, and <strong>INDEX</strong> rights on this database.</p>';
    echo "<p><strong>Error ({$code}):</strong> {$msg}</p>";
    echo '<p>If tables were partially created before, drop old <code>allureone_*</code> tables in phpMyAdmin (reverse order: giftcard → users → branch → roles), then run this page again, or import <code>sql/full_install.sql</code>.</p>';
    echo '<details><summary>Last SQL (for support)</summary><pre>' . htmlspecialchars($lastSql, ENT_QUOTES, 'UTF-8') . '</pre></details>';
    echo '</body></html>';
    exit(1);
}

if ($isCli) {
    echo "Installation complete.\n";
    foreach ($counts as $t => $n) {
        echo "  {$t}: {$n} rows\n";
    }
    echo "Login: admin / Allure@011225\n";
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AllureOne — Installed</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 560px; margin: 2rem auto; padding: 0 1rem; }
        .ok { color: #166534; }
        table { border-collapse: collapse; width: 100%; margin: 1rem 0; font-size: 0.9rem; }
        th, td { border: 1px solid #e5e7eb; padding: 0.5rem 0.75rem; text-align: left; }
        th { background: #f9fafb; }
    </style>
</head>
<body>
    <h1 class="ok">Installation complete</h1>
    <p>Tables are ready. Row counts:</p>
    <table>
        <thead><tr><th>Table</th><th>Rows</th></tr></thead>
        <tbody>
        <?php foreach ($counts as $table => $n): ?>
            <tr><td><code><?= htmlspecialchars($table, ENT_QUOTES, 'UTF-8') ?></code></td><td><?= (int) $n ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p>Default login: <strong>admin</strong> / <strong>Allure@011225</strong> (Superadmin).</p>
    <p><a href="login.php">Go to login</a></p>
    <p><small>Delete or protect <code>install.php</code> after setup. If the web installer fails, import <code>sql/full_install.sql</code> in phpMyAdmin.</small></p>
</body>
</html>
