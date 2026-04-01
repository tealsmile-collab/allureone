-- Run once on existing DB if allureone_keys is missing (stores posToken etc.)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS allureone_keys (
  id INT NOT NULL AUTO_INCREMENT,
  key_name VARCHAR(64) NOT NULL,
  key_value LONGTEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_allureone_keys_name (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
