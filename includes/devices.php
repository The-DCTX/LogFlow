<?php
// Registre d'appareils — associe un host/IP brut (tel qu'il arrive dans `logs`)
// à un nom convivial et un type. Sert la « reconnaissance par nom » des NAS & co.
require_once __DIR__ . '/db.php';

// Types d'appareils reconnus : clé => [label, icône Bootstrap].
const DEVICE_TYPES = [
    'nas'        => ['NAS',          'bi-hdd-network'],
    'server'     => ['Serveur',      'bi-server'],
    'hypervisor' => ['Hyperviseur',  'bi-hdd-stack'],
    'router'     => ['Routeur',      'bi-router'],
    'firewall'   => ['Pare-feu',     'bi-bricks'],
    'switch'     => ['Switch',       'bi-diagram-3'],
    'ap'         => ['Point d\'accès','bi-wifi'],
    'printer'    => ['Imprimante',   'bi-printer'],
    'iot'        => ['IoT',          'bi-cpu'],
    'other'      => ['Autre',        'bi-pc-display'],
];

function device_type_label(string $t): string { return DEVICE_TYPES[$t][0] ?? ucfirst($t); }
function device_type_icon(string $t): string  { return DEVICE_TYPES[$t][1] ?? 'bi-pc-display'; }

// Icône effective d'un appareil : surcharge explicite sinon icône du type.
function device_icon(array $d): string {
    return !empty($d['icon']) ? $d['icon'] : device_type_icon($d['type'] ?? 'other');
}

// Tous les appareils enregistrés (cache process).
function all_devices(): array {
    static $cache = null;
    if ($cache === null) {
        try { $cache = db()->query("SELECT * FROM devices ORDER BY name")->fetchAll(); }
        catch (\Throwable $e) { $cache = []; }
    }
    return $cache;
}

// Résout un host ou une IP source vers un appareil enregistré.
// Tolérant : la valeur enregistrée est comparée au host (insensible à la casse)
// ET à l'IP source — peu importe le `match_type` choisi (qui ne sert plus qu'à
// l'affichage). Ainsi un NAS qui émet sous son nom d'hôte ET sous son IP, ou une
// IP saisie par erreur en tant que « host », est tout de même reconnu.
function resolve_device(string $host, string $ip = ''): ?array {
    foreach (all_devices() as $d) {
        $v = (string)$d['match_value'];
        if ($host !== '' && strcasecmp($v, $host) === 0) return $d;
        if ($ip !== '' && $v === $ip) return $d;
    }
    return null;
}

// Nom à afficher pour un host/IP — fallback sur le host brut puis l'IP.
function device_name(string $host, string $ip = ''): string {
    $d = resolve_device($host, $ip);
    return $d['name'] ?? ($host !== '' ? $host : ($ip !== '' ? $ip : '(inconnu)'));
}

// Heuristique de pré-suggestion : ce host ressemble-t-il à un Synology DSM ?
// (programmes/marqueurs typiques rencontrés dans les logs forwardés par Log Center)
function guess_synology(string $program, string $message = ''): bool {
    return (bool) preg_match(
        '~\b(synoscgi|synolog\w*|synocgi|SYNO\.|WinFileService|AFPService|smbd|Connection|PkgManApp|scemd)\b~i',
        $program . ' ' . $message
    );
}
