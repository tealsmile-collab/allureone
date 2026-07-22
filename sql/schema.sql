-- AllureOne schema (MySQL 5.7+ / 8+)
-- Prefer: open install.php in the browser, or import full_install.sql (schema + seeds + admin user).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS allureone_roles (
  id INT NOT NULL,
  RoleName VARCHAR(100) NOT NULL,
  isActive TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS allureone_branch (
  id INT NOT NULL,
  business_name VARCHAR(255) NOT NULL,
  locality VARCHAR(255) NULL,
  vendor_id INT NOT NULL,
  isActive TINYINT(1) NOT NULL DEFAULT 1,
  isDingg TINYINT(1) NOT NULL DEFAULT 0,
  enableSaleRecord TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_branch_vendor (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS allureone_users (
  id INT NOT NULL AUTO_INCREMENT,
  loginname VARCHAR(20) NOT NULL,
  password VARCHAR(255) NOT NULL,
  FullName VARCHAR(255) NOT NULL,
  MobileNo VARCHAR(20) NULL,
  EmailId VARCHAR(255) NULL,
  BranchId INT NULL,
  RoleId INT NOT NULL,
  isactive TINYINT(1) NOT NULL DEFAULT 1,
  RecordSale TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_allureone_login (loginname),
  KEY idx_user_branch (BranchId),
  KEY idx_user_role (RoleId),
  CONSTRAINT fk_allureone_user_branch FOREIGN KEY (BranchId) REFERENCES allureone_branch (id),
  CONSTRAINT fk_allureone_user_role FOREIGN KEY (RoleId) REFERENCES allureone_roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS allureone_salerecord (
  id INT NOT NULL AUTO_INCREMENT,
  BranchId INT NOT NULL,
  SaleDate DATE NOT NULL,
  TotalSale DECIMAL(20,2) NOT NULL,
  CreatedBy INT NULL,
  CreatedDate DATETIME NOT NULL,
  UpdatedBy INT NULL,
  UpdatedDate DATETIME NULL,
  IsActive TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_salerecord_branch_date (BranchId, SaleDate),
  KEY idx_salerecord_branch (BranchId),
  KEY idx_salerecord_date (SaleDate),
  CONSTRAINT fk_salerecord_branch FOREIGN KEY (BranchId) REFERENCES allureone_branch (id)
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

CREATE TABLE IF NOT EXISTS allurepro_InvoiceCancellation (
  id INT NOT NULL AUTO_INCREMENT,
  `Invoice Number` VARCHAR(100) NOT NULL,
  `Invoice ID` INT NOT NULL,
  `Branch Name` VARCHAR(255) NOT NULL,
  `Branch ID` INT NOT NULL,
  `Invoice Date` VARCHAR(50) NOT NULL,
  `Client Name` VARCHAR(255) NOT NULL,
  `Invoice Amount` VARCHAR(50) NOT NULL,
  `Invoice Status` VARCHAR(255) NOT NULL,
  CancellationRemark VARCHAR(255) NOT NULL,
  AdminRemark VARCHAR(255) NULL,
  AdminID INT NULL,
  AdminName VARCHAR(255) NULL,
  RequestUserID INT NOT NULL,
  RequestUserName VARCHAR(255) NOT NULL,
  CancellationRequestDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CancelledDate DATETIME NULL,
  CancellationStatus TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_invoice_cancel_status (CancellationStatus),
  KEY idx_invoice_cancel_invoice_id (`Invoice ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS allureone_session_data (
  mobile_number VARCHAR(20) NOT NULL,
  mobile_key VARCHAR(50) NOT NULL,
  session_key VARCHAR(255) NOT NULL,
  branch_id INT NULL,
  updated_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (mobile_key),
  KEY idx_session_mobile_number (mobile_number),
  KEY idx_session_updated_date (updated_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS allureone_franchise_leads (
  id INT NOT NULL AUTO_INCREMENT,
  FULL_NAME VARCHAR(255) NULL,
  PHONE_NUMBER VARCHAR(50) NULL,
  CITY VARCHAR(150) NULL,
  investment_budget VARCHAR(255) NULL,
  preferred_timeline VARCHAR(255) NULL,
  experience_in_the_wellness VARCHAR(255) NULL,
  property_for_the_wellness VARCHAR(255) NULL,
  sourceName VARCHAR(255) NULL,
  DateTime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  form_id BIGINT NULL,
  campaign_id BIGINT NULL,
  status INT UNSIGNED NOT NULL DEFAULT 1,
  remarks VARCHAR(100) NULL,
  followup_datetime DATETIME NULL,
  web_submission_id BIGINT NULL,
  PRIMARY KEY (id),
  KEY idx_franchise_leads_datetime (DateTime),
  KEY idx_franchise_leads_phone (PHONE_NUMBER),
  KEY idx_franchise_leads_form_campaign (form_id, campaign_id),
  KEY idx_franchise_leads_status (status),
  UNIQUE KEY uq_franchise_leads_web_submission (web_submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PWA (see sql/pwa_tables.sql for full definitions on existing installs)
CREATE TABLE IF NOT EXISTS allureone_push_subscriptions (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth_key VARCHAR(255) NOT NULL,
  user_agent VARCHAR(512) NULL,
  device_label VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint_hash (endpoint_hash),
  KEY idx_push_user (user_id),
  KEY idx_push_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS allureone_announcements (
  id INT NOT NULL AUTO_INCREMENT,
  message TEXT NOT NULL,
  created_by INT NOT NULL,
  created_by_name VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_announcements_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS allureone_announcement_deliveries (
  id INT NOT NULL AUTO_INCREMENT,
  announcement_id INT NOT NULL,
  subscription_id INT NOT NULL,
  user_id INT NOT NULL,
  ack_token CHAR(64) NOT NULL,
  push_sent TINYINT(1) NOT NULL DEFAULT 0,
  push_error VARCHAR(512) NULL,
  delivered_at DATETIME NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ann_delivery (announcement_id, subscription_id),
  KEY idx_delivery_ann (announcement_id),
  KEY idx_delivery_ack (ack_token),
  CONSTRAINT fk_ann_delivery_ann FOREIGN KEY (announcement_id) REFERENCES allureone_announcements (id) ON DELETE CASCADE,
  CONSTRAINT fk_ann_delivery_sub FOREIGN KEY (subscription_id) REFERENCES allureone_push_subscriptions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO allureone_roles (id, RoleName, isActive) VALUES
  (1, 'Superadmin', 1),
  (2, 'admin', 1),
  (3, 'manager', 1),
  (4, 'jr. manager', 1),
  (5, 'therapist', 1),
  (6, 'housekeeping', 1)
ON DUPLICATE KEY UPDATE RoleName = VALUES(RoleName), isActive = VALUES(isActive);

INSERT INTO allureone_branch (id, business_name, locality, vendor_id, isActive) VALUES
  (3780, 'Allure Thai Spa And Wellness', 'Thane', 11179, 1),
  (2973, 'Allure Thai Spa & Wellness', 'Borivali (WEST)', 11179, 1),
  (2935, 'Allure Thai Spa & Wellness', 'Powai', 11179, 1),
  (3781, 'Allure Thai Spa & Wellness', 'Bhandup West', 11179, 1),
  (3782, 'Allure Thai Spa & Wellness', 'Nerul seawood', 11179, 1),
  (3000, 'Allure Thai Spa & Wellness', 'Andheri East', 11179, 1),
  (4185, 'Allure Thai Spa & Wellness', 'Malad West', 11179, 1),
  (4274, 'MANAS BOUTIQUE HOTEL & SPA', 'Gayalwadi', 11179, 1)
ON DUPLICATE KEY UPDATE
  business_name = VALUES(business_name),
  locality = VALUES(locality),
  vendor_id = VALUES(vendor_id),
  isActive = VALUES(isActive);

-- Default admin password must be created via install.php (uses password_hash) or insert your own bcrypt hash.
-- Example: password Allure@011225 → run install.php once to create user `admin`.
