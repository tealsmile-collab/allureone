-- PWA push subscriptions and announcements (run on existing installs)

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS allureone_push_subscriptions (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth_key VARCHAR(255) NOT NULL,
  content_encoding VARCHAR(32) NOT NULL DEFAULT 'aes128gcm',
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
  target_type VARCHAR(16) NOT NULL DEFAULT 'all',
  target_user_ids TEXT NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'ui',
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
