-- 001 — Registre d'appareils + source syslog réseau
-- Objectif : reconnaître les NAS Synology (et autres équipements réseau) par un
-- nom convivial, et accepter les logs poussés en syslog standard (RFC3164/5424)
-- par le récepteur syslogd.php.

-- Registre d'appareils : associe un host/IP brut (tel qu'il arrive dans `logs`)
-- à un nom convivial + un type (NAS, serveur, routeur…).
CREATE TABLE IF NOT EXISTS devices (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_type  ENUM('host','ip') NOT NULL DEFAULT 'host',
    match_value VARCHAR(255) NOT NULL,
    name        VARCHAR(120) NOT NULL,
    type        VARCHAR(40)  NOT NULL DEFAULT 'other',
    vendor      VARCHAR(60)  NULL,
    icon        VARCHAR(40)  NULL,
    notes       VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_match (match_type, match_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ajoute la valeur 'syslog' (récepteur réseau) à côté de rsyslog/http.
ALTER TABLE logs MODIFY source ENUM('rsyslog','http','syslog') NOT NULL DEFAULT 'rsyslog';

-- Index pour filtrer/grouper par IP source (vue par appareil, drill-down).
CREATE INDEX IF NOT EXISTS idx_source_ip ON logs (source_ip);

-- Réglages par défaut du récepteur syslog.
INSERT IGNORE INTO settings (key_name, value) VALUES
    ('syslog_listen_port',  '1514'),
    ('syslog_listen_proto', 'udp');
