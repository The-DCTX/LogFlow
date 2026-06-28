<?php
// Détection CVE par signatures d'exploitation. Consulté dans le chemin d'ingestion
// (receive.php + syslog_parse.php), comme detect_sec_event. Signatures en cache
// (TTL court), regex protégées (@), délimiteur « ~ » car les motifs contiennent des « / ».
require_once __DIR__ . '/db.php';

function active_cve_signatures(): array {
    static $cache = null, $at = 0;
    $now = time();
    if ($cache === null || ($now - $at) > 30) {
        try {
            $cache = db()->query("SELECT cve_id, name, pattern, program_match, cvss FROM cve_signatures WHERE enabled=1")->fetchAll();
        } catch (\Throwable $e) { $cache = $cache ?? []; }
        $at = $now;
    }
    return $cache;
}

// Renvoie la signature au CVSS le plus élevé qui matche, ou null.
function detect_cve(string $message, string $program = ''): ?array {
    $best = null;
    foreach (active_cve_signatures() as $s) {
        $pm = $s['program_match'] ?? '';
        if ($pm !== '' && stripos($program, $pm) === false) continue;
        if (@preg_match('~' . $s['pattern'] . '~i', $message) === 1) {
            if ($best === null || (float)$s['cvss'] > (float)$best['cvss']) $best = $s;
        }
    }
    return $best ? ['cve_id' => $best['cve_id'], 'cvss' => (float)$best['cvss'], 'name' => $best['name']] : null;
}

// Niveau de menace + couleur (variables CSS de l'app) à partir du score CVSS.
function cvss_meta(float $c): array {
    if ($c >= 9.0) return ['Critique', 'var(--red)'];
    if ($c >= 7.0) return ['Élevé',    'var(--orange)'];
    if ($c >= 4.0) return ['Moyen',    'var(--yellow)'];
    if ($c >  0.0) return ['Faible',   'var(--text-muted)'];
    return ['Inconnu', 'var(--text-muted)'];
}

// Sévérité log (0-7) dérivée du CVSS, pour propager l'urgence aux alertes.
function cve_severity(float $c): int {
    if ($c >= 9.0) return 1;   // Alert
    if ($c >= 7.0) return 2;   // Critical
    return 3;                  // Error
}
