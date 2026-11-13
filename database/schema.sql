-- Twitch Subscriber Alerter schema (MySQL 8 / MariaDB 10.4+)
-- Run with:  php bin/migrate.php

CREATE TABLE IF NOT EXISTS users (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  twitch_id         VARCHAR(32)  NOT NULL,
  login             VARCHAR(64)  NOT NULL,
  display_name      VARCHAR(64)  NOT NULL,
  profile_image_url VARCHAR(255) NULL,
  overlay_key       CHAR(32)     NOT NULL,
  is_admin          TINYINT(1)   NOT NULL DEFAULT 0,
  is_disabled       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at        DATETIME     NOT NULL,
  last_login_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_twitch_id (twitch_id),
  UNIQUE KEY uq_users_overlay_key (overlay_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_settings (
  user_id         INT UNSIGNED     NOT NULL,
  overlay_enabled TINYINT(1)       NOT NULL DEFAULT 1,
  volume          TINYINT UNSIGNED NOT NULL DEFAULT 80,
  react_gift      TINYINT(1)       NOT NULL DEFAULT 1,
  react_sub       TINYINT(1)       NOT NULL DEFAULT 1,
  react_resub     TINYINT(1)       NOT NULL DEFAULT 1,
  sub_alert       VARCHAR(32)      NULL,
  resub_alert     VARCHAR(32)      NULL,
  play_mode       VARCHAR(8)       NOT NULL DEFAULT 'queue',   -- how alerts that overlap play: queue or stack
  updated_at      DATETIME         NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per alert per streamer: gifts >= min_count play the enabled alert with the
-- highest min_count that is still <= the number of subs gifted.
CREATE TABLE IF NOT EXISTS alert_rules (
  user_id   INT UNSIGNED NOT NULL,
  alert_id  VARCHAR(32)  NOT NULL,
  enabled   TINYINT(1)   NOT NULL DEFAULT 1,
  min_count INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, alert_id),
  CONSTRAINT fk_rules_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eventsub_subscriptions (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                 INT UNSIGNED NOT NULL,
  type                    VARCHAR(64)  NOT NULL,
  twitch_subscription_id  VARCHAR(64)  NULL,
  status                  VARCHAR(48)  NOT NULL,
  updated_at              DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sub_user_type (user_id, type),
  KEY idx_sub_twitch_id (twitch_subscription_id),
  CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event log + overlay queue. The overlay polls rows with alert_id set.
CREATE TABLE IF NOT EXISTS events (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            INT UNSIGNED    NOT NULL,
  type               VARCHAR(16)     NOT NULL,   -- gift | sub | resub | test
  twitch_message_id  VARCHAR(64)     NULL,
  actor              VARCHAR(64)     NULL,       -- gifter / subscriber display name
  gift_count         INT UNSIGNED    NULL,
  tier               VARCHAR(8)      NULL,
  is_anonymous       TINYINT(1)      NOT NULL DEFAULT 0,
  months             SMALLINT UNSIGNED NULL,
  alert_id           VARCHAR(32)     NULL,       -- NULL = logged but nothing to play
  note               VARCHAR(64)     NULL,       -- why nothing played, etc.
  created_at         DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_events_message (twitch_message_id),
  KEY idx_events_user_id (user_id, id),
  KEY idx_events_created (created_at),
  CONSTRAINT fk_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Streamer-submitted alerts. Nothing plays until an admin has approved it (status = 'approved').
-- Submissions are immutable: to change one, delete it and submit a new one.
--   kind        media = uploaded image/GIF/video (+ sound, text);  html = self-contained HTML page
--   visibility  chosen by the admin on approval: private = only the owner, public = shared library
CREATE TABLE IF NOT EXISTS custom_alerts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id     INT UNSIGNED NOT NULL,
  name         VARCHAR(60)  NOT NULL,
  description  VARCHAR(255) NULL,
  kind         VARCHAR(8)   NOT NULL,
  status       VARCHAR(12)  NOT NULL DEFAULT 'pending',   -- pending | approved | rejected
  visibility   VARCHAR(8)   NULL,                         -- private | public (set on approval)
  config       MEDIUMTEXT   NOT NULL,                     -- JSON: files + display settings
  scan         MEDIUMTEXT   NULL,                         -- JSON: automatic findings for the reviewer
  review_note  VARCHAR(255) NULL,
  reviewed_by  INT UNSIGNED NULL,
  reviewed_at  DATETIME     NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_custom_owner (owner_id),
  KEY idx_custom_status (status, visibility),
  CONSTRAINT fk_custom_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- When a streamer's overlay (OBS) last polled; powers the "connected" indicator.
CREATE TABLE IF NOT EXISTS overlay_seen (
  user_id  INT UNSIGNED NOT NULL,
  seen_at  DATETIME     NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_seen_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_state (
  k          VARCHAR(64) NOT NULL,
  v          TEXT        NOT NULL,
  updated_at DATETIME    NOT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
