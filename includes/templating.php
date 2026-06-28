<?php
// Parser auto-adaptatif — couche d'apprentissage assisté.
// Principe : masquage DÉTERMINISTE (sans état, sans ML, donc fiable et reproductible)
// des parties variables d'un message → « squelette » servant de gabarit. Tourne HORS
// du chemin d'ingestion critique (appelé par api/cron.php ou un bouton « Analyser »).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/security.php';

// Remplace les parties variables d'un message par des marqueurs stables.
// Ordre : du plus spécifique au plus général (sinon un motif large mangerait un précis).
function mask_message(string $m): string {
    $m = trim($m);
    if ($m === '') return '';
    $rules = [
        '/\[[^\]]*\]/'                                   => '[<*>]',   // [admin], [192.0.2.10] (Synology)
        '/\b(?:[0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}\b/'     => '<mac>',
        '/\b\d{1,3}(?:\.\d{1,3}){3}(?::\d+)?\b/'          => '<ip>',    // IPv4 (+ port éventuel)
        '/\b[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}\b/' => '<uuid>',
        '/"[^"]*"/'                                       => '"<*>"',
        '/\b[0-9a-fA-F]{8,}\b/'                           => '<hex>',
        '/\b\d+\b/'                                       => '<num>',
    ];
    foreach ($rules as $re => $to) {
        $out = preg_replace($re, $to, $m);
        if ($out !== null) $m = $out;                    // si une regex échoue, on garde l'état précédent
    }
    return preg_replace('/\s+/', ' ', trim($m));
}

// Empreinte stable d'un gabarit (programme + squelette).
function template_fp(string $program, string $skeleton): string {
    return hash('sha256', $program . "\x1f" . $skeleton);
}

// Motif suggéré pour une règle : la plus longue suite littérale du squelette,
// échappée (preg_quote) → AUCUN risque de ReDoS, haute précision.
function suggest_pattern(string $skeleton): string {
    $literals = preg_split('/\s*<[^>]*>\s*|\s*\[<\*>\]\s*|\s*"<\*>"\s*/', $skeleton, -1, PREG_SPLIT_NO_EMPTY);
    $best = '';
    foreach ($literals as $lit) {
        $lit = trim($lit);
        if (strlen($lit) > strlen($best)) $best = $lit;
    }
    return $best !== '' ? preg_quote($best, '/') : '';
}

// Apprentissage par lot : traite les logs depuis le filigrane, met à jour les
// gabarits. Idempotent (filigrane = id croissant → pas de double comptage),
// borné (limite), plafonné (n'ajoute plus de NOUVEAU gabarit au-delà du cap).
function learn_templates(int $limit = 2000): array {
    $db        = db();
    $watermark = (int) get_setting('templating_last_id', '0');
    $cap       = (int) get_setting('templating_max', '5000');

    $sel = $db->prepare("SELECT id, program, message FROM logs WHERE id > ? ORDER BY id ASC LIMIT ?");
    $sel->bindValue(1, $watermark, PDO::PARAM_INT);
    $sel->bindValue(2, max(1, min(20000, $limit)), PDO::PARAM_INT);
    $sel->execute();
    $logs = $sel->fetchAll();
    if (!$logs) return ['processed' => 0, 'templates' => 0, 'last_id' => $watermark];

    $distinct = (int) $db->query("SELECT COUNT(*) FROM log_templates")->fetchColumn();

    $agg = [];   // fp => agrégat du lot
    $maxId = $watermark;
    foreach ($logs as $l) {
        $maxId    = max($maxId, (int) $l['id']);
        $skeleton = mask_message((string) $l['message']);
        if ($skeleton === '') continue;
        $prog = substr((string) ($l['program'] ?? ''), 0, 100);
        $fp   = template_fp($prog, $skeleton);
        if (!isset($agg[$fp])) {
            $sec = detect_sec_event((string) $l['message'], $prog);
            $agg[$fp] = [
                'program' => $prog, 'skeleton' => substr($skeleton, 0, 1000),
                'example' => substr((string) $l['message'], 0, 1000),
                'count'   => 0, 'sec' => $sec['event'] ?? null,
                'tokens'  => substr_count($skeleton, ' ') + 1,
            ];
        }
        $agg[$fp]['count']++;
    }

    $ins = $db->prepare(
        "INSERT INTO log_templates (fingerprint, program, token_count, template, example, count, sec_event)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE count = count + VALUES(count), last_seen = NOW(),
             sec_event = COALESCE(sec_event, VALUES(sec_event))"
    );
    $exists = $db->prepare("SELECT 1 FROM log_templates WHERE fingerprint = ?");
    $created = 0;
    foreach ($agg as $fp => $a) {
        if ($distinct >= $cap) {                         // plafond atteint : on n'ajoute plus de nouveaux
            $exists->execute([$fp]);
            if (!$exists->fetchColumn()) continue;       // (mais on continue d'incrémenter les existants)
        }
        $ins->execute([$fp, $a['program'], $a['tokens'], $a['skeleton'], $a['example'], $a['count'], $a['sec']]);
        if ($db->lastInsertId()) $created++;
    }

    set_setting('templating_last_id', (string) $maxId);
    return ['processed' => count($logs), 'templates' => count($agg), 'last_id' => $maxId];
}
