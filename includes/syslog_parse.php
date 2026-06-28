<?php
// Parsing d'un message syslog (RFC 5424 « IETF » et RFC 3164 « BSD »).
// Mutualisé entre le récepteur (syslogd.php) et l'outil de re-parsing.
require_once __DIR__ . '/security.php';   // detect_sec_event()
require_once __DIR__ . '/cve.php';         // detect_cve()

function parse_syslog(string $raw, string $peer_ip): ?array {
    $raw = rtrim($raw, "\r\n\0");
    if ($raw === '') return null;

    $facility = 1; $severity = 6; $rest = $raw;
    if (preg_match('/^<(\d{1,3})>(.*)$/s', $raw, $m)) {
        $pri = (int)$m[1];
        $facility = intdiv($pri, 8);
        $severity = $pri % 8;
        $rest = $m[2];
    }

    $host = ''; $program = ''; $pid = null; $message = $rest; $log_time = null;

    // RFC 5424 : "1 TIMESTAMP HOST APP-NAME PROCID MSGID [SD] MSG"
    if (preg_match('/^1\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(?:\[[^\]]*\]|-)\s*(.*)$/s', $rest, $m)) {
        if ($m[1] !== '-') { $ts = strtotime($m[1]); if ($ts) $log_time = date('Y-m-d H:i:s', $ts); }
        $host    = $m[2] !== '-' ? $m[2] : '';
        $program = $m[3] !== '-' ? $m[3] : '';
        $pid     = ($m[4] !== '-' && ctype_digit($m[4])) ? (int)$m[4] : null;
        $message = ltrim($m[6], "\xEF\xBB\xBF"); // strip BOM éventuel du champ MSG
    }
    // RFC 3164 : "Mmm dd hh:mm:ss HOST <reste>"
    elseif (preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\s+(\S+)\s+(.*)$/s', $rest, $m)) {
        $ts = strtotime($m[1]); if ($ts) $log_time = date('Y-m-d H:i:s', $ts);
        $host    = $m[2];
        $tagrest = $m[3];
        // Tag classique terminé par ':' (avec [pid] optionnel) — ex. sshd[123]: msg, Connection: msg
        if (preg_match('/^([^\s:\[]+)(?:\[(\d+)\])?:\s*(.*)$/s', $tagrest, $t)) {
            $program = $t[1];
            $pid     = ($t[2] ?? '') !== '' ? (int)$t[2] : null;
            $message = $t[3];
        }
        // …ou tag terminé par un espace — ex. Synology « System ... », « MacFileService Event: ... »
        elseif (preg_match('/^(\S+)\s+(.*)$/s', $tagrest, $t)) {
            $program = $t[1];
            $message = $t[2];
        } else {
            $message = $tagrest;
        }
    }
    // Repli : structure non reconnue → tout dans le message, host = IP de l'émetteur.
    else {
        $message = $rest;
    }

    if ($host === '' || $host === '-') $host = $peer_ip;

    $message = substr($message, 0, 65000);
    $program = substr($program, 0, 100);
    $host    = substr($host, 0, 255);

    // CVE prioritaire (exploitation), sinon détection d'événement de sécurité.
    $cve_id = null;
    $cve = detect_cve($message, $program);
    if ($cve) {
        $cve_id    = $cve['cve_id'];
        $sec_event = 'exploit_attempt';
        $severity  = cve_severity($cve['cvss']);
    } else {
        $sec       = detect_sec_event($message, $program);
        $sec_event = $sec ? $sec['event'] : null;
        if ($sec && $sec['severity'] !== null) $severity = $sec['severity'];
    }

    return [
        'cols' => [
            $log_time, $host, substr($peer_ip, 0, 45), $facility, $severity,
            $program, $pid, $message, null /*os*/, $sec_event, $cve_id,
        ],
        'notify' => [
            'host' => $host, 'program' => $program, 'severity' => $severity,
            'message' => $message, 'os' => null, 'source_ip' => $peer_ip, 'sec_event' => $sec_event,
        ],
    ];
}
