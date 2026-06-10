-- LogFlow — jeu de données de DÉMONSTRATION
-- Génère ~400 logs synthétiques cohérents, répartis sur les dernières 48 h,
-- pour que le dashboard soit immédiatement peuplé.
-- ⚠️ Ne pas charger en production. Pour repartir à vide : TRUNCATE TABLE logs;

INSERT INTO logs
  (received_at, log_time, host, source_ip, facility, severity, program, pid, message, source, os, sec_event)
SELECT
  g.ts,
  g.ts,
  CASE WHEN g.t = 8 THEN 'win-dc01'
       ELSE ELT(1 + FLOOR(RAND() * 4), 'web-01', 'db-01', 'fw-01', 'rpi-edge')
  END                                                                          AS host,
  CONCAT('192.0.2.', 1 + FLOOR(RAND() * 254))                                  AS source_ip,
  ELT(1 + FLOOR(RAND() * 5), 4, 4, 10, 1, 0)                                   AS facility,
  ELT(g.t, 6, 4, 2, 5, 6, 4, 6, 6)                                             AS severity,
  ELT(g.t, 'sshd', 'sshd', 'sshd', 'sudo', 'nginx', 'kernel', 'systemd',
           'Microsoft-Windows-Security-Auditing')                             AS program,
  100 + FLOOR(RAND() * 9000)                                                   AS pid,
  ELT(g.t,
      'Accepted password for admin from 192.0.2.10 port 51020 ssh2',
      'Failed password for invalid user root from 192.0.2.55 port 4022 ssh2',
      'Possible break-in attempt! 14 failed logins from 192.0.2.55',
      'pam_unix(sudo:session): session opened for user root by admin(uid=1000)',
      'GET /api/health HTTP/1.1 200 12ms',
      'kernel: [UFW BLOCK] IN=eth0 OUT= SRC=192.0.2.99 DST=10.0.0.5 PROTO=TCP DPT=23',
      'Started Daily apt download activities.',
      'An account was successfully logged on. Logon Type: 3')                  AS message,
  'http'                                                                       AS source,
  ELT(g.t, 'linux', 'linux', 'linux', 'linux', 'linux', 'linux', 'linux',
           'windows')                                                         AS os,
  ELT(g.t, 'auth_success', 'auth_fail', 'auth_brute', 'sudo', NULL,
           'firewall_block', NULL, 'auth_success')                            AS sec_event
FROM (
  SELECT
    seq,
    1 + MOD(seq, 8)                              AS t,
    NOW() - INTERVAL FLOOR(RAND() * 2880) MINUTE AS ts
  FROM seq_1_to_400
) AS g;
