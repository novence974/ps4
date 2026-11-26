-- =====================================================================
--  ULTIMATE PS LIBRARY — Database schema
--  MySQL 8.0+ / MariaDB 10.6+  (utf8mb4, InnoDB)
--  All DATETIME values are stored in UTC.
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Roles & permissions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
  id           TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(32)  NOT NULL,
  name         VARCHAR(64)  NOT NULL,
  level        TINYINT UNSIGNED NOT NULL DEFAULT 10,
  description  VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(64)  NOT NULL,
  name        VARCHAR(128) NOT NULL,
  group_name  VARCHAR(64)  NOT NULL DEFAULT 'general',
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id        TINYINT UNSIGNED  NOT NULL,
  permission_id  SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_rp_permission (permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Users, tokens
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id                TINYINT UNSIGNED NOT NULL,
  username               VARCHAR(32)  NOT NULL,
  email                  VARCHAR(190) NOT NULL,
  password_hash          VARCHAR(255) NOT NULL,
  display_name           VARCHAR(64)  NULL,
  avatar_path            VARCHAR(255) NULL,
  bio                    VARCHAR(500) NULL,
  status                 ENUM('active','suspended') NOT NULL DEFAULT 'active',
  can_download           TINYINT(1) NOT NULL DEFAULT 1,
  email_verified_at      DATETIME NULL,
  failed_logins          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until           DATETIME NULL,
  last_login_at          DATETIME NULL,
  last_login_ip          VARCHAR(45) NULL,
  notifications_seen_at  DATETIME NULL,
  preferences            JSON NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role_id),
  KEY idx_users_status (status),
  KEY idx_users_created (created_at),
  KEY idx_users_last_login (last_login_at),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- E-mail verification & password reset tokens (only SHA-256 hashes are stored)
CREATE TABLE IF NOT EXISTS user_tokens (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  type        ENUM('verify_email','reset_password') NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_tokens_hash (token_hash),
  KEY idx_user_tokens_user (user_id, type),
  CONSTRAINT fk_user_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API bearer tokens for the mobile / PS4 apps (only SHA-256 hashes are stored)
CREATE TABLE IF NOT EXISTS api_tokens (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  name          VARCHAR(100) NOT NULL DEFAULT 'device',
  token_hash    CHAR(64) NOT NULL,
  last_used_at  DATETIME NULL,
  last_used_ip  VARCHAR(45) NULL,
  expires_at    DATETIME NULL,
  revoked_at    DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_tokens_hash (token_hash),
  KEY idx_api_tokens_user (user_id, revoked_at),
  CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Catalogue
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS platforms (
  id                   SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug                 VARCHAR(32)  NOT NULL,
  name                 VARCHAR(64)  NOT NULL,
  short_name           VARCHAR(16)  NOT NULL,
  manufacturer         VARCHAR(64)  NOT NULL DEFAULT 'Sony Interactive Entertainment',
  release_year         SMALLINT UNSIGNED NULL,
  generation           TINYINT UNSIGNED NULL,
  description          TEXT NULL,
  logo_path            VARCHAR(255) NULL,
  image_path           VARCHAR(255) NULL,
  accent_color         CHAR(7) NOT NULL DEFAULT '#8b5cf6',
  supported_formats    VARCHAR(255) NULL,
  compatibility_notes  TEXT NULL,
  install_notes        TEXT NULL,
  sort_order           SMALLINT NOT NULL DEFAULT 0,
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_platforms_slug (slug),
  KEY idx_platforms_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(64) NOT NULL,
  name        VARCHAR(64) NOT NULL,
  name_en     VARCHAR(64) NULL,
  icon        VARCHAR(32) NULL,
  color       CHAR(7) NOT NULL DEFAULT '#8b5cf6',
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug),
  KEY idx_categories_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS games (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform_id          SMALLINT UNSIGNED NOT NULL,
  title                VARCHAR(190) NOT NULL,
  title_normalized     VARCHAR(190) NOT NULL,
  slug                 VARCHAR(220) NOT NULL,
  product_code         VARCHAR(32)  NULL,
  description          TEXT NULL,
  release_year         SMALLINT UNSIGNED NULL,
  developer            VARCHAR(128) NULL,
  publisher            VARCHAR(128) NULL,
  age_rating           VARCHAR(24)  NULL,
  size_bytes           BIGINT UNSIGNED NULL,
  languages            VARCHAR(255) NULL,
  region               VARCHAR(16)  NULL,
  version              VARCHAR(32)  NULL,
  edition              VARCHAR(64)  NULL,
  rating               DECIMAL(3,1) NULL,
  cover_path           VARCHAR(255) NULL,
  banner_path          VARCHAR(255) NULL,
  trailer_url          VARCHAR(500) NULL,
  files_status         ENUM('available','unavailable','coming_soon') NOT NULL DEFAULT 'unavailable',
  compatibility_notes  TEXT NULL,
  is_published         TINYINT(1) NOT NULL DEFAULT 0,
  is_featured          TINYINT(1) NOT NULL DEFAULT 0,
  published_at         DATETIME NULL,
  view_count           INT UNSIGNED NOT NULL DEFAULT 0,
  download_count       INT UNSIGNED NOT NULL DEFAULT 0,
  favorite_count       INT UNSIGNED NOT NULL DEFAULT 0,
  created_by           INT UNSIGNED NULL,
  updated_by           INT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_games_slug (slug),
  KEY idx_games_platform_pub (platform_id, is_published, created_at),
  KEY idx_games_pub_created (is_published, created_at),
  KEY idx_games_pub_rating (is_published, rating),
  KEY idx_games_pub_downloads (is_published, download_count),
  KEY idx_games_pub_title (is_published, title),
  KEY idx_games_pub_updated (is_published, updated_at),
  KEY idx_games_year (release_year),
  KEY idx_games_developer (developer),
  KEY idx_games_publisher (publisher),
  KEY idx_games_region (region),
  KEY idx_games_size (size_bytes),
  KEY idx_games_normalized (platform_id, title_normalized),
  KEY idx_games_product_code (product_code),
  KEY idx_games_featured (is_featured, is_published),
  FULLTEXT KEY ft_games_search (title, developer, publisher),
  CONSTRAINT fk_games_platform FOREIGN KEY (platform_id) REFERENCES platforms (id) ON UPDATE CASCADE,
  CONSTRAINT fk_games_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_games_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_categories (
  game_id      INT UNSIGNED NOT NULL,
  category_id  SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (game_id, category_id),
  KEY idx_game_categories_category (category_id, game_id),
  CONSTRAINT fk_gc_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
  CONSTRAINT fk_gc_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Screenshots. `path` is a media base path; variants are <path>-sm.webp, -lg.webp, -sm.jpg, -lg.jpg
CREATE TABLE IF NOT EXISTS game_images (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id     INT UNSIGNED NOT NULL,
  path        VARCHAR(255) NOT NULL,
  width       SMALLINT UNSIGNED NULL,
  height      SMALLINT UNSIGNED NULL,
  caption     VARCHAR(190) NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_game_images_game (game_id, sort_order),
  CONSTRAINT fk_game_images_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_versions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id       INT UNSIGNED NOT NULL,
  version       VARCHAR(32) NOT NULL,
  region        VARCHAR(16) NULL,
  release_date  DATE NULL,
  size_bytes    BIGINT UNSIGNED NULL,
  changelog     TEXT NULL,
  is_latest     TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_game_versions_game (game_id, created_at),
  CONSTRAINT fk_game_versions_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Chunked uploads (large files never go into the database: only metadata)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS uploads (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid             CHAR(32) NOT NULL,
  user_id          INT UNSIGNED NULL,
  purpose          ENUM('game_file','cover','banner','screenshot','avatar','platform_logo','platform_image','trailer','music','backup','import') NOT NULL,
  game_id          INT UNSIGNED NULL,
  target_meta      JSON NULL,
  original_name    VARCHAR(255) NOT NULL,
  extension        VARCHAR(16)  NOT NULL,
  mime             VARCHAR(100) NULL,
  size_bytes       BIGINT UNSIGNED NOT NULL,
  chunk_size       INT UNSIGNED NOT NULL,
  total_chunks     INT UNSIGNED NOT NULL,
  received_chunks  INT UNSIGNED NOT NULL DEFAULT 0,
  received_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  expected_sha256  CHAR(64) NULL,
  sha256           CHAR(64) NULL,
  disk             VARCHAR(32) NOT NULL DEFAULT 'local',
  status           ENUM('uploading','processing','completed','failed','cancelled','expired') NOT NULL DEFAULT 'uploading',
  error            VARCHAR(1000) NULL,
  result           JSON NULL,
  ip               VARCHAR(45) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at     DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uploads_uuid (uuid),
  KEY idx_uploads_user (user_id, created_at),
  KEY idx_uploads_status (status, updated_at),
  KEY idx_uploads_game (game_id),
  CONSTRAINT fk_uploads_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_uploads_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS upload_chunks (
  upload_id    BIGINT UNSIGNED NOT NULL,
  chunk_index  INT UNSIGNED NOT NULL,
  size         INT UNSIGNED NOT NULL,
  sha256       CHAR(64) NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (upload_id, chunk_index),
  CONSTRAINT fk_upload_chunks_upload FOREIGN KEY (upload_id) REFERENCES uploads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hosted files. Game / update / DLC / data / save files are kept apart by file_type.
CREATE TABLE IF NOT EXISTS game_files (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id         INT UNSIGNED NOT NULL,
  version_id      INT UNSIGNED NULL,
  upload_id       BIGINT UNSIGNED NULL,
  file_type       ENUM('game','update','dlc','data','save','other') NOT NULL DEFAULT 'game',
  label           VARCHAR(190) NULL,
  original_name   VARCHAR(255) NOT NULL,
  disk            VARCHAR(32)  NOT NULL DEFAULT 'local',
  path            VARCHAR(500) NOT NULL,
  size_bytes      BIGINT UNSIGNED NOT NULL,
  mime            VARCHAR(100) NULL,
  sha256          CHAR(64) NULL,
  region          VARCHAR(16) NULL,
  version_label   VARCHAR(32) NULL,
  access_level    ENUM('public','registered','verified','moderator','admin') NOT NULL DEFAULT 'registered',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  download_count  INT UNSIGNED NOT NULL DEFAULT 0,
  verified_at     DATETIME NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_game_files_game (game_id, file_type, is_active),
  KEY idx_game_files_sha (sha256),
  KEY idx_game_files_created (created_at),
  CONSTRAINT fk_game_files_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
  CONSTRAINT fk_game_files_version FOREIGN KEY (version_id) REFERENCES game_versions (id) ON DELETE SET NULL,
  CONSTRAINT fk_game_files_upload FOREIGN KEY (upload_id) REFERENCES uploads (id) ON DELETE SET NULL,
  CONSTRAINT fk_game_files_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- External official / authorised links (kept apart from hosted files)
CREATE TABLE IF NOT EXISTS game_links (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id     INT UNSIGNED NOT NULL,
  title       VARCHAR(190) NOT NULL,
  url         VARCHAR(1000) NOT NULL,
  link_type   ENUM('official_store','publisher','authorized_mirror','info') NOT NULL DEFAULT 'official_store',
  region      VARCHAR(16) NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_game_links_game (game_id, sort_order),
  CONSTRAINT fk_game_links_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-game compatibility entries (backward compatibility, original hardware, emulators…)
CREATE TABLE IF NOT EXISTS game_compatibility (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id     INT UNSIGNED NOT NULL,
  target      VARCHAR(64) NOT NULL,
  status      ENUM('perfect','playable','ingame','menus','boots','nothing','unknown') NOT NULL DEFAULT 'unknown',
  notes       VARCHAR(500) NULL,
  tested_at   DATE NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_game_compat_target (game_id, target),
  CONSTRAINT fk_game_compat_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- User activity
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS downloads (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  game_id     INT UNSIGNED NOT NULL,
  file_id     BIGINT UNSIGNED NULL,
  ip          VARCHAR(45) NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_downloads_user (user_id, created_at),
  KEY idx_downloads_game (game_id, created_at),
  KEY idx_downloads_created (created_at),
  CONSTRAINT fk_downloads_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_downloads_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
  CONSTRAINT fk_downloads_file FOREIGN KEY (file_id) REFERENCES game_files (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorites (
  user_id     INT UNSIGNED NOT NULL,
  game_id     INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, game_id),
  KEY idx_favorites_game (game_id),
  KEY idx_favorites_user_created (user_id, created_at),
  CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_favorites_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Viewing history ("سجل المشاهدة")
CREATE TABLE IF NOT EXISTS view_history (
  user_id    INT UNSIGNED NOT NULL,
  game_id    INT UNSIGNED NOT NULL,
  views      INT UNSIGNED NOT NULL DEFAULT 1,
  viewed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, game_id),
  KEY idx_view_history_user (user_id, viewed_at),
  CONSTRAINT fk_view_history_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_view_history_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Library updates / changelog / announcements
CREATE TABLE IF NOT EXISTS updates (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type          ENUM('game_added','game_updated','patch','announcement','maintenance') NOT NULL DEFAULT 'announcement',
  game_id       INT UNSIGNED NULL,
  platform_id   SMALLINT UNSIGNED NULL,
  version_id    INT UNSIGNED NULL,
  title         VARCHAR(190) NOT NULL,
  body          TEXT NULL,
  is_published  TINYINT(1) NOT NULL DEFAULT 1,
  published_at  DATETIME NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_updates_published (is_published, published_at),
  KEY idx_updates_game (game_id),
  CONSTRAINT fk_updates_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
  CONSTRAINT fk_updates_platform FOREIGN KEY (platform_id) REFERENCES platforms (id) ON DELETE SET NULL,
  CONSTRAINT fk_updates_version FOREIGN KEY (version_id) REFERENCES game_versions (id) ON DELETE SET NULL,
  CONSTRAINT fk_updates_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_id NULL = broadcast to everyone (read state via users.notifications_seen_at)
CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  type        VARCHAR(32) NOT NULL DEFAULT 'info',
  title       VARCHAR(190) NOT NULL,
  body        VARCHAR(1000) NULL,
  link        VARCHAR(500) NULL,
  read_at     DATETIME NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user (user_id, read_at, created_at),
  KEY idx_notifications_created (created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- System
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(64) NOT NULL,
  `value`     MEDIUMTEXT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(64) NOT NULL,
  entity_type  VARCHAR(32) NULL,
  entity_id    BIGINT UNSIGNED NULL,
  description  VARCHAR(500) NULL,
  ip           VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  meta         JSON NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activity_user (user_id, created_at),
  KEY idx_activity_action (action, created_at),
  KEY idx_activity_entity (entity_type, entity_id),
  KEY idx_activity_created (created_at),
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Background job queue (file assembly, hash verification, backups, notifications…)
CREATE TABLE IF NOT EXISTS jobs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type           VARCHAR(64) NOT NULL,
  payload        JSON NULL,
  status         ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 3,
  reserve_token  CHAR(32) NULL,
  available_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at     DATETIME NULL,
  finished_at    DATETIME NULL,
  error          VARCHAR(2000) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_jobs_pick (status, available_at),
  KEY idx_jobs_token (reserve_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
