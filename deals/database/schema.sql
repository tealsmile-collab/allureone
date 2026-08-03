-- =============================================================================
-- Allure Thai Spa Deals — Database Schema
-- Database: u716393246_AllurePro
-- Prefix: alluredeal_
-- Run on Hostinger phpMyAdmin / MySQL
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Cities
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_city` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `state` VARCHAR(100) DEFAULT 'Maharashtra',
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_city_slug` (`slug`),
  KEY `idx_city_active` (`is_active`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Branches
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_branch` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `city_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(180) NOT NULL,
  `address` TEXT NULL,
  `phone` VARCHAR(20) NULL,
  `whatsapp` VARCHAR(20) NULL,
  `email` VARCHAR(150) NULL,
  `lat` DECIMAL(10,7) NULL,
  `lng` DECIMAL(10,7) NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_branch_slug` (`slug`),
  KEY `idx_branch_city` (`city_id`),
  KEY `idx_branch_active` (`is_active`, `is_deleted`),
  CONSTRAINT `fk_branch_city` FOREIGN KEY (`city_id`) REFERENCES `alluredeal_city` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Categories
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_category` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(180) NOT NULL,
  `description` TEXT NULL,
  `image` VARCHAR(255) NULL,
  `icon` VARCHAR(100) NULL,
  `parent_id` INT UNSIGNED NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `seo_title` VARCHAR(255) NULL,
  `seo_description` TEXT NULL,
  `seo_keywords` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cat_slug` (`slug`),
  KEY `idx_cat_active` (`is_active`, `is_deleted`),
  KEY `idx_cat_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Products
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_product` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(280) NOT NULL,
  `short_description` VARCHAR(500) NULL,
  `long_description` MEDIUMTEXT NULL,
  `benefits` TEXT NULL,
  `duration` INT UNSIGNED NULL COMMENT 'minutes',
  `original_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `offer_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `auto_strike_price` TINYINT(1) NOT NULL DEFAULT 1,
  `image` VARCHAR(255) NULL,
  `rating` DECIMAL(3,2) NOT NULL DEFAULT 4.50,
  `rating_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_today_deal` TINYINT(1) NOT NULL DEFAULT 0,
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `is_bestseller` TINYINT(1) NOT NULL DEFAULT 0,
  `display_order` INT NOT NULL DEFAULT 0,
  `seo_title` VARCHAR(255) NULL,
  `seo_description` TEXT NULL,
  `seo_keywords` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_slug` (`slug`),
  KEY `idx_product_cat` (`category_id`),
  KEY `idx_product_deal` (`is_today_deal`, `is_active`),
  KEY `idx_product_price` (`offer_price`),
  KEY `idx_product_active` (`is_active`, `is_deleted`),
  CONSTRAINT `fk_product_cat` FOREIGN KEY (`category_id`) REFERENCES `alluredeal_category` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Product Gallery
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_product_images` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `image` VARCHAR(255) NOT NULL,
  `alt_text` VARCHAR(255) NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pimg_product` (`product_id`),
  CONSTRAINT `fk_pimg_product` FOREIGN KEY (`product_id`) REFERENCES `alluredeal_product` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Today's Deals
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_todaydeal` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `badge_text` VARCHAR(100) DEFAULT 'Limited Time Deal',
  `discount_percent` DECIMAL(5,2) NULL,
  `deal_price` DECIMAL(12,2) NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NULL,
  `show_countdown` TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT NOT NULL DEFAULT 0,
  `all_locations` TINYINT(1) NOT NULL DEFAULT 1,
  `city_ids` JSON NULL,
  `branch_ids` JSON NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_deal_product` (`product_id`),
  KEY `idx_deal_window` (`starts_at`, `ends_at`, `is_active`),
  CONSTRAINT `fk_deal_product` FOREIGN KEY (`product_id`) REFERENCES `alluredeal_product` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Hero Slider
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_slider` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `heading` VARCHAR(255) NULL,
  `sub_heading` VARCHAR(500) NULL,
  `cta_text` VARCHAR(100) NULL,
  `cta_link` VARCHAR(500) NULL,
  `desktop_image` VARCHAR(255) NOT NULL,
  `mobile_image` VARCHAR(255) NULL,
  `priority` INT NOT NULL DEFAULT 0,
  `starts_at` DATETIME NULL,
  `ends_at` DATETIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_slider_active` (`is_active`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Coupons
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_coupon` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `title` VARCHAR(150) NULL,
  `discount_type` ENUM('percent','flat') NOT NULL DEFAULT 'percent',
  `discount_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `min_order_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `max_discount` DECIMAL(12,2) NULL,
  `usage_limit` INT UNSIGNED NULL,
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `per_user_limit` INT UNSIGNED DEFAULT 1,
  `starts_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `category_ids` JSON NULL,
  `city_ids` JSON NULL,
  `branch_ids` JSON NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_coupon_code` (`code`),
  KEY `idx_coupon_active` (`is_active`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alluredeal_onetime_coupon` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `discount_type` ENUM('percent','flat') NOT NULL DEFAULT 'percent',
  `discount_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `max_discount` DECIMAL(12,2) NULL,
  `min_order_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `used_at` DATETIME NULL,
  `order_id` INT UNSIGNED NULL,
  `expires_at` DATETIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_otc_code` (`code`),
  KEY `idx_otc_used` (`is_used`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alluredeal_coupon_usage` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id` INT UNSIGNED NULL,
  `onetime_coupon_id` INT UNSIGNED NULL,
  `customer_id` INT UNSIGNED NULL,
  `order_id` INT UNSIGNED NULL,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cu_coupon` (`coupon_id`),
  KEY `idx_cu_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Customers
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_customer` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `mobile` VARCHAR(20) NOT NULL,
  `email` VARCHAR(150) NULL,
  `gender` ENUM('male','female','other','prefer_not') NULL,
  `city_id` INT UNSIGNED NULL,
  `notes` TEXT NULL,
  `total_orders` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_spent` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer_mobile` (`mobile`),
  KEY `idx_customer_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Cart
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_cart` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_token` VARCHAR(64) NOT NULL,
  `customer_id` INT UNSIGNED NULL,
  `coupon_code` VARCHAR(50) NULL,
  `coupon_discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `city_id` INT UNSIGNED NULL,
  `branch_id` INT UNSIGNED NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cart_token` (`session_token`),
  KEY `idx_cart_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alluredeal_cart_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cart_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `original_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `city_id` INT UNSIGNED NULL,
  `branch_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cart_item` (`cart_id`, `product_id`, `branch_id`),
  KEY `idx_ci_product` (`product_id`),
  CONSTRAINT `fk_ci_cart` FOREIGN KEY (`cart_id`) REFERENCES `alluredeal_cart` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ci_product` FOREIGN KEY (`product_id`) REFERENCES `alluredeal_product` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Order Status
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_order_status` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `label` VARCHAR(80) NOT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_os_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Orders
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(40) NOT NULL,
  `invoice_no` VARCHAR(40) NULL,
  `customer_id` INT UNSIGNED NULL,
  `customer_name` VARCHAR(150) NOT NULL,
  `customer_mobile` VARCHAR(20) NOT NULL,
  `customer_email` VARCHAR(150) NULL,
  `customer_gender` VARCHAR(20) NULL,
  `notes` TEXT NULL,
  `city_id` INT UNSIGNED NULL,
  `branch_id` INT UNSIGNED NOT NULL,
  `coupon_code` VARCHAR(50) NULL,
  `coupon_discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `gst_percent` DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  `gst_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
  `payment_status` ENUM('pending','paid','failed','refunded','partial') NOT NULL DEFAULT 'pending',
  `razorpay_order_id` VARCHAR(100) NULL,
  `razorpay_payment_id` VARCHAR(100) NULL,
  `razorpay_signature` VARCHAR(255) NULL,
  `status_id` INT UNSIGNED NULL,
  `status_code` VARCHAR(40) NOT NULL DEFAULT 'placed',
  `invoice_path` VARCHAR(255) NULL,
  `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `whatsapp_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_order_mobile` (`customer_mobile`),
  KEY `idx_order_branch` (`branch_id`),
  KEY `idx_order_city` (`city_id`),
  KEY `idx_order_payment` (`payment_status`),
  KEY `idx_order_status` (`status_code`),
  KEY `idx_order_coupon` (`coupon_code`),
  KEY `idx_order_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alluredeal_order_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `duration` INT UNSIGNED NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `original_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_oi_order` (`order_id`),
  KEY `idx_oi_product` (`product_id`),
  CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `alluredeal_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Payment & Activity Logs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_payment_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NULL,
  `event` VARCHAR(80) NOT NULL,
  `payload` JSON NULL,
  `status` VARCHAR(40) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pl_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alluredeal_activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity` VARCHAR(80) NULL,
  `entity_id` INT UNSIGNED NULL,
  `meta_json` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_al_user` (`user_id`),
  KEY `idx_al_entity` (`entity`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Settings / Policies / Wishlist
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alluredeal_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `setting_group` VARCHAR(60) DEFAULT 'general',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alluredeal_policy` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(120) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `seo_title` VARCHAR(255) NULL,
  `seo_description` TEXT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_policy_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alluredeal_wishlist` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_token` VARCHAR(64) NOT NULL,
  `customer_id` INT UNSIGNED NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wish` (`session_token`, `product_id`),
  KEY `idx_wish_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Seed Data
-- =============================================================================

INSERT INTO `alluredeal_order_status` (`code`, `label`, `display_order`) VALUES
('placed', 'Order Placed', 1),
('confirmed', 'Confirmed', 2),
('completed', 'Completed', 3),
('cancelled', 'Cancelled', 4),
('refunded', 'Refunded', 5)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

INSERT INTO `alluredeal_city` (`name`, `slug`, `state`, `display_order`) VALUES
('Mumbai', 'mumbai', 'Maharashtra', 1),
('Navi Mumbai', 'navi-mumbai', 'Maharashtra', 2),
('Thane', 'thane', 'Maharashtra', 3),
('Palghar', 'palghar', 'Maharashtra', 4),
('Vadodara', 'vadodara', 'Gujarat', 5)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `alluredeal_branch` (`city_id`, `name`, `slug`, `phone`, `whatsapp`, `display_order`)
SELECT c.id, v.name, v.slug, v.phone, v.whatsapp, v.ord
FROM (
  SELECT 'Mumbai' city, 'Andheri East' name, 'andheri-east' slug, '8010545836' phone, '918010545836' whatsapp, 1 ord UNION ALL
  SELECT 'Mumbai', 'Bandra', 'bandra', '8975155984', '918975155984', 2 UNION ALL
  SELECT 'Mumbai', 'Bhandup', 'bhandup', '8080515738', '918080515738', 3 UNION ALL
  SELECT 'Mumbai', 'Borivali', 'borivali', '7620049769', '917620049769', 4 UNION ALL
  SELECT 'Mumbai', 'Powai', 'powai', '7620049769', '917620049769', 5 UNION ALL
  SELECT 'Navi Mumbai', 'Kharghar', 'kharghar', '8424925346', '918424925346', 1 UNION ALL
  SELECT 'Navi Mumbai', 'Seawoods', 'seawoods', '7620049769', '917620049769', 2 UNION ALL
  SELECT 'Thane', 'Thane Kolshet Road', 'thane-kolshet', '7620049769', '917620049769', 1 UNION ALL
  SELECT 'Thane', 'Thane Vartak Nagar', 'thane-vartak', '7620049769', '917620049769', 2 UNION ALL
  SELECT 'Palghar', 'Palghar', 'palghar-branch', '7875588844', '917875588844', 1 UNION ALL
  SELECT 'Palghar', 'Boisar', 'boisar', '7620049769', '917620049769', 2 UNION ALL
  SELECT 'Vadodara', 'Vadodara', 'vadodara-branch', '7620049769', '917620049769', 1
) v
JOIN `alluredeal_city` c ON c.name = v.city
WHERE NOT EXISTS (SELECT 1 FROM `alluredeal_branch` b WHERE b.slug = v.slug);

INSERT INTO `alluredeal_category` (`name`, `slug`, `display_order`, `seo_title`) VALUES
('Thai Massage', 'thai-massage', 1, 'Thai Massage Deals | Allure Thai Spa'),
('Swedish Massage', 'swedish-massage', 2, 'Swedish Massage Deals'),
('Deep Tissue', 'deep-tissue', 3, 'Deep Tissue Massage Offers'),
('Hot Stone', 'hot-stone', 4, 'Hot Stone Therapy Deals'),
('Balinese', 'balinese', 5, 'Balinese Massage Offers'),
('Potli Massage', 'potli-massage', 6, 'Potli Massage Deals'),
('Foot Reflexology', 'foot-reflexology', 7, 'Foot Reflexology Offers'),
('Facial', 'facial', 8, 'Facial Spa Deals'),
('Body Scrub', 'body-scrub', 9, 'Body Scrub Offers'),
('Membership', 'membership', 10, 'Spa Membership Plans'),
('Gift Voucher', 'gift-voucher', 11, 'Spa Gift Vouchers')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `alluredeal_product`
(`category_id`, `name`, `slug`, `short_description`, `long_description`, `benefits`, `duration`,
 `original_price`, `offer_price`, `discount_percent`, `image`, `rating`, `rating_count`,
 `is_today_deal`, `is_featured`, `is_bestseller`, `display_order`, `seo_title`)
SELECT c.id, p.name, p.slug, p.short_d, p.long_d, p.benefits, p.duration,
       p.op, p.ofp, p.dp, NULL, 4.70, 128, p.deal, p.feat, p.best, p.ord, p.seo
FROM (
  SELECT 'Thai Massage' cat, 'Classic Thai Massage 60 Min' name, 'classic-thai-massage-60' slug,
         'Traditional Thai stretch & pressure therapy.' short_d,
         'Experience authentic Thai massage with skilled therapists focusing on energy lines and gentle stretches.' long_d,
         'Relieves tension; Improves flexibility; Boosts circulation' benefits, 60 duration,
         2499.00 op, 1499.00 ofp, 40.00 dp, 1 deal, 1 feat, 1 best, 1 ord,
         'Classic Thai Massage 60 Min Deal' seo UNION ALL
  SELECT 'Swedish Massage', 'Swedish Relaxation 60 Min', 'swedish-relaxation-60',
         'Long gliding strokes for deep relaxation.',
         'Swedish massage designed to melt stress and soothe tired muscles.',
         'Reduces stress; Improves sleep; Muscle recovery', 60, 2299, 1299, 43.5, 1, 1, 1, 2,
         'Swedish Massage Offer' UNION ALL
  SELECT 'Deep Tissue', 'Deep Tissue Therapy 90 Min', 'deep-tissue-90',
         'Targets chronic knots and tightness.',
         'Firm pressure therapy for athletes and desk workers with persistent muscle pain.',
         'Pain relief; Posture support; Deep recovery', 90, 3499, 2199, 37.15, 1, 1, 0, 3,
         'Deep Tissue Massage Deal' UNION ALL
  SELECT 'Hot Stone', 'Hot Stone Bliss 75 Min', 'hot-stone-75',
         'Warm basalt stones with aromatic oils.',
         'Heated stones placed on key points to release tension and warm the body.',
         'Muscle softening; Warmth therapy; Luxury unwind', 75, 3999, 2499, 37.5, 1, 1, 0, 4,
         'Hot Stone Spa Deal' UNION ALL
  SELECT 'Facial', 'Glow Facial Ritual 45 Min', 'glow-facial-45',
         'Deep cleanse, mask & glow finish.',
         'A rejuvenating facial ritual for radiant, refreshed skin.',
         'Hydration; Glow; Skin refresh', 45, 1999, 999, 50, 1, 0, 1, 5,
         'Glow Facial Offer' UNION ALL
  SELECT 'Gift Voucher', 'Spa Gift Voucher ₹2000', 'gift-voucher-2000',
         'Perfect gift for wellness lovers.',
         'Redeemable digital gift voucher for Allure Thai Spa services.',
         'Flexible; Shareable; Instant delivery', 0, 2000, 2000, 0, 0, 1, 0, 6,
         'Spa Gift Voucher'
) p
JOIN `alluredeal_category` c ON c.name = p.cat
WHERE NOT EXISTS (SELECT 1 FROM `alluredeal_product` x WHERE x.slug = p.slug);

INSERT INTO `alluredeal_todaydeal`
(`product_id`, `badge_text`, `discount_percent`, `deal_price`, `starts_at`, `ends_at`, `show_countdown`, `display_order`)
SELECT pr.id, 'Limited Time Deal', pr.discount_percent, pr.offer_price,
       NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 1, pr.display_order
FROM `alluredeal_product` pr
WHERE pr.is_today_deal = 1
  AND NOT EXISTS (SELECT 1 FROM `alluredeal_todaydeal` d WHERE d.product_id = pr.id AND d.is_deleted = 0);

INSERT INTO `alluredeal_slider`
(`heading`, `sub_heading`, `cta_text`, `cta_link`, `desktop_image`, `mobile_image`, `priority`, `is_active`)
SELECT * FROM (
  SELECT 'Luxury Spa Deals' h, 'Authentic Thai wellness at exclusive prices' s, 'Shop Deals' c, '#deals' l,
         'assets/img/slider-1.jpg' d, 'assets/img/slider-1-m.jpg' m, 1 p, 1 a UNION ALL
  SELECT 'Today\'s Limited Offers', 'Countdown deals you don\'t want to miss', 'View Offers', '#deals',
         'assets/img/slider-2.jpg', 'assets/img/slider-2-m.jpg', 2, 1
) t
WHERE NOT EXISTS (SELECT 1 FROM `alluredeal_slider` LIMIT 1);

INSERT INTO `alluredeal_coupon`
(`code`, `title`, `discount_type`, `discount_value`, `min_order_amount`, `max_discount`, `usage_limit`, `starts_at`, `expires_at`)
VALUES
('ALLURE10', 'Flat 10% Off', 'percent', 10, 999, 500, 1000, NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY)),
('SPA500', '₹500 Off', 'flat', 500, 2499, NULL, 500, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY))
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT INTO `alluredeal_policy` (`slug`, `title`, `content`, `display_order`) VALUES
('privacy-policy', 'Privacy Policy', '<p>We respect your privacy. Personal data collected during booking is used only for order processing, communication, and service improvement.</p>', 1),
('terms-conditions', 'Terms & Conditions', '<p>By purchasing deals on Allure Thai Spa Deals, you agree to service availability at selected branches and applicable spa house rules.</p>', 2),
('payment-policy', 'Payment Policy', '<p>All payments are processed securely via Razorpay in INR. Orders are confirmed only after successful payment.</p>', 3),
('cancellation-policy', 'Cancellation Policy', '<p>Cancellations must be requested at least 24 hours before the appointment. Same-day cancellations may not be eligible for reschedule.</p>', 4),
('refund-policy', 'Refund Policy', '<p>Eligible refunds are processed to the original payment method within 5–7 business days after approval.</p>', 5),
('no-refund-policy', 'No Refund Policy', '<p>Certain promotional and flash deals are non-refundable once redeemed or expired as stated on the deal page.</p>', 6),
('gift-voucher-policy', 'Gift Voucher Policy', '<p>Gift vouchers are valid as per the validity mentioned and cannot be exchanged for cash.</p>', 7),
('digital-product-policy', 'Digital Product Policy', '<p>Digital vouchers and memberships are delivered electronically after successful payment confirmation.</p>', 8)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT INTO `alluredeal_settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('site_tagline', 'Premium Thai Spa Deals Across India', 'general'),
('hero_autoplay_ms', '5000', 'frontend'),
('invoice_prefix', 'ATS', 'orders'),
('order_prefix', 'AD', 'orders')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
