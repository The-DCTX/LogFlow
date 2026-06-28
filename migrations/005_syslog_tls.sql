-- 005 — Réglages du récepteur syslog TLS (RFC 5425, port 6514)
-- Désactivé par défaut : n'a aucun effet tant qu'un certificat n'est pas fourni
-- et le réglage activé (le récepteur UDP/TCP en clair continue normalement).
INSERT IGNORE INTO settings (key_name, value) VALUES
    ('syslog_tls_enabled', '0'),
    ('syslog_tls_port',    '6514'),
    ('syslog_tls_cert',    ''),       -- chemin du certificat PEM (cert + chaîne)
    ('syslog_tls_key',     '');       -- chemin de la clé privée PEM (si séparée du cert)
