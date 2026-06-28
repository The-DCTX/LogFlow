-- 004 — Détection d'anomalies (brute-force, silence radio, nouvel hôte)
-- Tourne en cron (hors chemin d'ingestion). Dédupliqué, seuils configurables.

CREATE TABLE IF NOT EXISTS anomalies (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type       ENUM('brute_force','silence','new_host') NOT NULL,
    host       VARCHAR(255) NOT NULL DEFAULT '',
    source_ip  VARCHAR(45)  NOT NULL DEFAULT '',
    detail     VARCHAR(255) NOT NULL DEFAULT '',
    observed   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    severity   TINYINT UNSIGNED NOT NULL DEFAULT 2,
    status     ENUM('open','resolved','ack') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- déduplication gérée en code (un seul 'open' par type/host/ip à la fois)
    INDEX idx_dedup (type, host, source_ip, status),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;  -- aligné sur `logs` (jointure host)

INSERT IGNORE INTO settings (key_name, value) VALUES
    ('anomaly_enabled',           '1'),
    ('anomaly_bruteforce_count',  '10'),   -- N échecs d'auth…
    ('anomaly_bruteforce_window', '10'),   -- …en M minutes (par IP source)
    ('anomaly_silence_minutes',   '30'),   -- hôte chatty devenu muet
    ('anomaly_newhost',           '1'),    -- alerter sur tout nouvel hôte
    ('anomaly_last_run',          '');
