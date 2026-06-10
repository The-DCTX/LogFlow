CREATE DATABASE IF NOT EXISTS logflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE logflow;

CREATE TABLE IF NOT EXISTS logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    received_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    log_time    DATETIME        NULL,
    host        VARCHAR(255)    NOT NULL DEFAULT '',
    source_ip   VARCHAR(45)     NOT NULL DEFAULT '',
    facility    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    severity    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    program     VARCHAR(100)    NOT NULL DEFAULT '',
    pid         INT UNSIGNED    NULL,
    message     TEXT            NOT NULL,
    source      ENUM('rsyslog','http') NOT NULL DEFAULT 'rsyslog',
    os          ENUM('linux','windows','macos','other') NULL,
    sec_event   VARCHAR(40)     NULL,
    INDEX idx_received  (received_at),
    INDEX idx_sec_event (sec_event),
    INDEX idx_host      (host),
    INDEX idx_severity  (severity),
    INDEX idx_facility  (facility),
    INDEX idx_program   (program),
    FULLTEXT idx_search (host, program, message)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS api_keys (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    api_key    VARCHAR(64)  NOT NULL UNIQUE,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used  DATETIME     NULL
) ENGINE=InnoDB;

-- Clé API par défaut
INSERT INTO api_keys (name, api_key) VALUES ('default', SHA2(RAND(), 256));

CREATE TABLE IF NOT EXISTS settings (
    key_name VARCHAR(50) PRIMARY KEY,
    value    TEXT
);
INSERT IGNORE INTO settings VALUES
    ('server_url',  'http://YOUR_SERVER_IP'),
    ('server_name', 'LogFlow'),
    ('discord_webhook_url',    ''),
    ('discord_min_severity',   '3'),
    ('discord_cooldown',       '5'),
    ('discord_sec_events_only','0'),
    -- SMTP (alertes email)
    ('smtp_enabled',          '0'),
    ('smtp_host',             ''),
    ('smtp_port',             '587'),
    ('smtp_user',             ''),
    ('smtp_pass',             ''),
    ('smtp_from',             ''),
    ('smtp_to',               ''),
    ('smtp_min_severity',     '3'),
    ('smtp_sec_events_only',  '0'),
    -- Rétention automatique des logs (jours)
    ('log_retention_days',    '90'),
    -- Auth — mot de passe par défaut : logflow2026 (à changer dans Setup > Sécurité)
    ('auth_username',      'admin'),
    ('auth_password_hash', '$2y$12$eoYCfnqWpnT5FWB7ekobke/5JYDuOEggBQxsUIGc0BGsHtS9tY2zG');

CREATE TABLE IF NOT EXISTS discord_notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host        VARCHAR(255) NOT NULL,
    event_key   VARCHAR(100) NOT NULL,
    notified_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cooldown (host, event_key, notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS discord_queue (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host       VARCHAR(255)     NOT NULL DEFAULT '',
    event_key  VARCHAR(100)     NOT NULL DEFAULT '',
    program    VARCHAR(100)     NOT NULL DEFAULT '',
    severity   TINYINT UNSIGNED NOT NULL DEFAULT 6,
    message    TEXT             NOT NULL,
    os         VARCHAR(20)      NULL,
    source_ip  VARCHAR(45)      NOT NULL DEFAULT '',
    sec_event  VARCHAR(40)      NULL,
    attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    queued_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_host_event (host, event_key, queued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Règles de recatégorisation de sévérité (page « Sévérité »)
CREATE TABLE IF NOT EXISTS severity_rules (
    id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    kind       ENUM('sec_event','program','message') NOT NULL,
    match_val  VARCHAR(255)     NOT NULL,
    severity   TINYINT          NOT NULL DEFAULT 6,
    label      VARCHAR(120)     NULL,
    enabled    TINYINT(1)       NOT NULL DEFAULT 1,
    sort_order INT              NOT NULL DEFAULT 100,
    created_at TIMESTAMP        NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_enabled (enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
