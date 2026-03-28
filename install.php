<?php
declare(strict_types=1);

/**
 * One-time setup: creates tables and seed data.
 * Remove or protect this file in production after running.
 */

$config = require __DIR__ . '/config.php';
$c = $config['db'];
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['database'], $c['charset']);

header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = new PDO($dsn, $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo '<p>Database connection failed.</p>';
    exit(1);
}

$statements = [
    <<<SQL
CREATE TABLE IF NOT EXISTS allureone_roles (
  id INT NOT NULL,
  RoleName VARCHAR(100) NOT NULL,
  isActive TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    ,
    <<<SQL
CREATE TABLE IF NOT EXISTS allureone_branch (
  id INT NOT NULL AUTO_INCREMENT,
  BranchName VARCHAR(255) NOT NULL,
  Location TEXT NULL,
  isActive TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    ,
    <<<SQL
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
    <<<SQL
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
];

foreach ($statements as $sql) {
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
    [1, 'Mulund', ''],
    [2, 'Andheri', ''],
    [3, 'Powai', ''],
];
$bi = $pdo->prepare(
    'INSERT INTO allureone_branch (id, BranchName, Location, isActive) VALUES (?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE BranchName = VALUES(BranchName), Location = VALUES(Location), isActive = 1'
);
foreach ($branches as $b) {
    $bi->execute($b);
}
$pdo->exec('ALTER TABLE allureone_branch AUTO_INCREMENT = 4');

$adminPass = password_hash('Allure@011225', PASSWORD_DEFAULT);
$chk = $pdo->query("SELECT COUNT(*) FROM allureone_users WHERE loginname = 'admin'")->fetchColumn();
if ((int) $chk === 0) {
    $ui = $pdo->prepare(
        'INSERT INTO allureone_users (loginname, password, FullName, BranchId, RoleId, isactive)
         VALUES (?, ?, ?, 1, 1, 1)'
    );
    $ui->execute(['admin', $adminPass, 'Administrator']);
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
    </style>
</head>
<body>
    <h1 class="ok">Installation complete</h1>
    <p>Tables <code>allureone_roles</code>, <code>allureone_branch</code>, <code>allureone_users</code>, and <code>allureone_giftcard</code> are ready.</p>
    <p>Default login: <strong>admin</strong> / <strong>Allure@011225</strong> (Superadmin).</p>
    <p><a href="login.php">Go to login</a></p>
    <p><small>For security, delete or restrict access to <code>install.php</code> after setup.</small></p>
</body>
</html>
