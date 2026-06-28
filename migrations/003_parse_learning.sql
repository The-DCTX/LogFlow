-- 003 — Parser auto-adaptatif (apprentissage assisté, sûr et révisable)
-- Deux tables : découverte de gabarits (non supervisé, déterministe) et règles
-- de classification validées par un humain. Aucune règle n'est jamais appliquée
-- sans approbation ; les données apprises sont des DONNÉES (jamais exécutées).

-- Gabarits de messages découverts automatiquement (masquage déterministe).
CREATE TABLE IF NOT EXISTS log_templates (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fingerprint CHAR(64)     NOT NULL,
    program     VARCHAR(100) NOT NULL DEFAULT '',
    token_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    template    VARCHAR(1000) NOT NULL,          -- squelette : parties variables → <*>, <ip>, <num>…
    example     VARCHAR(1000) NOT NULL,          -- échantillon réel
    count       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sec_event   VARCHAR(40)  NULL,               -- classification connue (si l'exemple matchait déjà)
    status      ENUM('new','reviewed','ignored') NOT NULL DEFAULT 'new',
    first_seen  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_fp (fingerprint),
    INDEX idx_status (status),
    INDEX idx_count (count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Règles de classification approuvées (data-driven, additives aux SEC_EVENTS codés).
CREATE TABLE IF NOT EXISTS parse_rules (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label         VARCHAR(120) NOT NULL DEFAULT '',
    program_match VARCHAR(100) NULL,             -- restreint au programme (LIKE), optionnel
    pattern       VARCHAR(255) NOT NULL,         -- regex (validée à la création)
    sec_event     VARCHAR(40)  NOT NULL,         -- doit être une clé de SEC_EVENTS
    enabled       TINYINT(1)   NOT NULL DEFAULT 1,
    source        ENUM('manual','suggested') NOT NULL DEFAULT 'manual',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Réglages : filigrane de progression + plafond anti-explosion de gabarits.
INSERT IGNORE INTO settings (key_name, value) VALUES
    ('templating_last_id', '0'),
    ('templating_max',     '5000');
