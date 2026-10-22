-- TIMB Tobacco Crop Monitoring Portal — database schema (MySQL 5.7+ / MariaDB 10.3+)
-- Designed by Zimgeo — www.zimgeo.co.zw

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  organisation VARCHAR(160) NULL,
  phone VARCHAR(40) NULL,
  role ENUM('admin','viewer') NOT NULL DEFAULT 'viewer',
  status ENUM('invited','active','disabled') NOT NULL DEFAULT 'invited',
  password_hash VARCHAR(255) NULL,
  can_download TINYINT(1) NOT NULL DEFAULT 1,
  allowed_districts TEXT NULL,
  notify_new_season TINYINT(1) NOT NULL DEFAULT 1,
  notify_admin_events TINYINT(1) NOT NULL DEFAULT 1,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  purpose ENUM('invite','reset') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_token (token_hash),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_email (email, created_at),
  KEY idx_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seasons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  province VARCHAR(80) NOT NULL DEFAULT 'Mashonaland West',
  start_date DATE NULL,
  end_date DATE NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  yield_benchmark_t_ha DECIMAL(6,3) NOT NULL DEFAULT 2.200,
  classifier_accuracy DECIMAL(6,4) NULL,
  images_used INT NULL,
  training_points INT NULL,
  min_field_ha DECIMAL(8,3) NULL,
  field_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_ha DOUBLE NOT NULL DEFAULT 0,
  total_yield_t DOUBLE NOT NULL DEFAULT 0,
  data_version INT UNSIGNED NOT NULL DEFAULT 1,
  published_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_season_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fields (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  season_id INT UNSIGNED NOT NULL,
  field_code VARCHAR(30) NOT NULL,
  province VARCHAR(80) NOT NULL,
  district VARCHAR(80) NOT NULL,
  district_gee VARCHAR(80) NULL,
  ward VARCHAR(80) NULL,
  farmer_ref VARCHAR(80) NULL,
  area_ha DOUBLE NOT NULL,
  est_yield_t DOUBLE NOT NULL,
  ndvi_mean DOUBLE NULL,
  lon DOUBLE NOT NULL,
  lat DOUBLE NOT NULL,
  minlon DOUBLE NOT NULL,
  minlat DOUBLE NOT NULL,
  maxlon DOUBLE NOT NULL,
  maxlat DOUBLE NOT NULL,
  geom MEDIUMTEXT NOT NULL,
  import_id INT UNSIGNED NULL,
  KEY idx_season_district (season_id, district),
  KEY idx_season_lonlat (season_id, lon, lat),
  KEY idx_season_area (season_id, area_ha),
  KEY idx_code (field_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_points (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  season_id INT UNSIGNED NOT NULL,
  class TINYINT NOT NULL,
  lon DOUBLE NOT NULL,
  lat DOUBLE NOT NULL,
  import_id INT UNSIGNED NULL,
  KEY idx_season (season_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS imports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  season_id INT UNSIGNED NOT NULL,
  kind ENUM('fields','runinfo','points','unknown') NOT NULL DEFAULT 'unknown',
  mode ENUM('replace','append') NOT NULL DEFAULT 'replace',
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bytes_done BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rows_done INT UNSIGNED NOT NULL DEFAULT 0,
  rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
  header_json TEXT NULL,
  status ENUM('uploaded','running','done','failed','cancelled') NOT NULL DEFAULT 'uploaded',
  message TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  KEY idx_season (season_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  details TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
