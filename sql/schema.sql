-- AllureOne schema (MySQL 5.7+ / 8+)
-- Run install.php once from the app, or execute this file and seed users separately.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS allureone_roles (
  id INT NOT NULL,
  RoleName VARCHAR(100) NOT NULL,
  isActive TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS allureone_branch (
  id INT NOT NULL AUTO_INCREMENT,
  BranchName VARCHAR(255) NOT NULL,
  Location TEXT NULL,
  isActive TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO allureone_roles (id, RoleName, isActive) VALUES
  (1, 'Superadmin', 1),
  (2, 'admin', 1),
  (3, 'manager', 1),
  (4, 'jr. manager', 1),
  (5, 'therapist', 1),
  (6, 'housekeeping', 1)
ON DUPLICATE KEY UPDATE RoleName = VALUES(RoleName), isActive = VALUES(isActive);

INSERT INTO allureone_branch (id, BranchName, Location, isActive) VALUES
  (1, 'Mulund', '', 1),
  (2, 'Andheri', '', 1),
  (3, 'Powai', '', 1)
ON DUPLICATE KEY UPDATE BranchName = VALUES(BranchName), Location = VALUES(Location), isActive = VALUES(isActive);

ALTER TABLE allureone_branch AUTO_INCREMENT = 4;

-- Default admin password must be created via install.php (uses password_hash) or insert your own bcrypt hash.
-- Example: password Allure@011225 → run install.php once to create user `admin`.
