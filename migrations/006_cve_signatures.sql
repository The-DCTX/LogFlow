-- 006 — Détection CVE par signatures d'exploitation (hors-ligne, déterministe)
-- Détecte dans les logs les tentatives d'exploitation de CVE connues, tague le log
-- (colonne `cve`) et alimente une page récap. Aucune règle auto-ajoutée hors du
-- jeu curé + ce que l'admin ajoute. Données = données (jamais exécutées).

-- Tag CVE sur les logs (DDL instantané sous MariaDB pour une colonne NULL).
ALTER TABLE logs ADD COLUMN IF NOT EXISTS cve VARCHAR(30) NULL;
CREATE INDEX IF NOT EXISTS idx_cve ON logs (cve);

-- Base de signatures : motif (regex validée) → CVE + score CVSS + remédiation.
CREATE TABLE IF NOT EXISTS cve_signatures (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cve_id        VARCHAR(30)  NOT NULL,
    name          VARCHAR(120) NOT NULL DEFAULT '',
    pattern       VARCHAR(255) NOT NULL,
    program_match VARCHAR(100) NULL,
    cvss          DECIMAL(3,1) NOT NULL DEFAULT 0.0,
    remediation   VARCHAR(500) NOT NULL DEFAULT '',
    reference_url VARCHAR(255) NULL,
    enabled       TINYINT(1)   NOT NULL DEFAULT 1,
    source        ENUM('builtin','manual') NOT NULL DEFAULT 'builtin',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cve (cve_id, pattern(120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Acquittement par (CVE, hôte).
CREATE TABLE IF NOT EXISTS cve_acks (
    cve_id   VARCHAR(30)  NOT NULL,
    host     VARCHAR(255) NOT NULL,
    acked_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cve_id, host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jeu de signatures initial (CVE majeures, observables dans les logs).
INSERT IGNORE INTO cve_signatures (cve_id, name, pattern, program_match, cvss, remediation, reference_url) VALUES
 ('CVE-2021-44228','Log4Shell (Log4j JNDI)','\\$\\{jndi:(ldap|ldaps|rmi|dns|iiop|nis|corba|nds):', NULL, 10.0,
  'Mettre à jour Log4j ≥ 2.17.1. Mitigation temporaire : -Dlog4j2.formatMsgNoLookups=true. Bloquer les sorties LDAP/RMI sortantes.',
  'https://nvd.nist.gov/vuln/detail/CVE-2021-44228'),
 ('CVE-2014-6271','Shellshock (Bash)','\\(\\)\\s*\\{\\s*:;\\s*\\};', NULL, 9.8,
  'Mettre à jour bash (paquets distrib). Vérifier CGI exposés. Surveiller les User-Agent contenant la charge.',
  'https://nvd.nist.gov/vuln/detail/CVE-2014-6271'),
 ('CVE-2022-22965','Spring4Shell','class\\.module\\.classLoader', NULL, 9.8,
  'Mettre à jour Spring Framework ≥ 5.3.18 / 5.2.20 et le JDK. Bloquer les paramètres class.* en entrée.',
  'https://nvd.nist.gov/vuln/detail/CVE-2022-22965'),
 ('CVE-2021-41773','Apache HTTP path traversal','/cgi-bin/\\.%2e/|/icons/\\.%2e/|%2e%2e%2f', NULL, 7.5,
  'Mettre à jour Apache httpd ≥ 2.4.51. Désactiver les alias non nécessaires, "require all denied" hors racine.',
  'https://nvd.nist.gov/vuln/detail/CVE-2021-41773'),
 ('CVE-2017-5638','Apache Struts2 OGNL (Content-Type)','%\\{\\(#_?memberAccess|#context\\[|@java\\.lang\\.Runtime@', NULL, 10.0,
  'Mettre à jour Struts ≥ 2.3.32 / 2.5.10.1. Filtrer les Content-Type malformés.',
  'https://nvd.nist.gov/vuln/detail/CVE-2017-5638');
