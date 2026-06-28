<?php
/**
 * LogFlow — récepteur syslog réseau (RFC 3164 « BSD » et RFC 5424 « IETF »).
 *
 * Écoute en UDP et/ou TCP, parse les messages, applique la détection de sécurité
 * et les notifications, puis insère en base (source='syslog'). Conçu pour recevoir
 * les logs forwardés par le Log Center des NAS Synology (et tout équipement réseau
 * sachant émettre du syslog : routeurs, pare-feux, switches, imprimantes…).
 *
 * Réservé au CLI — lancé par systemd (agents/logflow-syslogd.service) ou Docker.
 *
 * Usage :
 *   php syslogd.php [--port=N] [--proto=udp|tcp|both]
 * Réglages (priorité décroissante) : option CLI > env SYSLOG_PORT/SYSLOG_PROTO >
 *   table settings (syslog_listen_port / syslog_listen_proto) > défaut 1514/udp.
 *
 * Port < 1024 (ex. 514) : nécessite root ou CAP_NET_BIND_SERVICE. Le Log Center
 * Synology permet de choisir le port → 1514 par défaut pour tourner sans privilège.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/security.php';
require __DIR__ . '/includes/settings.php';
require __DIR__ . '/includes/discord.php';
require __DIR__ . '/includes/email.php';

// ── Résolution de la configuration ────────────────────────────────────────
function opt(string $name): ?string {
    foreach ($GLOBALS['argv'] as $a) {
        if (str_starts_with($a, "--$name=")) return substr($a, strlen($name) + 3);
    }
    return null;
}
$port  = (int)(opt('port')  ?? getenv('SYSLOG_PORT')  ?: get_setting('syslog_listen_port', '1514'));
$proto = strtolower(opt('proto') ?? getenv('SYSLOG_PROTO') ?: get_setting('syslog_listen_proto', 'udp'));
if ($port < 1 || $port > 65535) { fwrite(STDERR, "Port invalide : $port\n"); exit(1); }
if (!in_array($proto, ['udp', 'tcp', 'both'], true)) $proto = 'udp';

$BIND    = getenv('SYSLOG_BIND') ?: '0.0.0.0';
$FLUSH_S = 2;     // intervalle de vidage du buffer (secondes)
$BATCH   = 200;   // taille max d'un INSERT groupé

function logline(string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] $m\n"); }

// ── Ouverture des sockets ──────────────────────────────────────────────────
$sockets = [];   // ressource => 'udp'|'tcp'
$clients = [];   // flux TCP acceptés => buffer de ligne partielle
foreach (($proto === 'both' ? ['udp', 'tcp'] : [$proto]) as $p) {
    $uri = "$p://$BIND:$port";
    $flags = $p === 'udp' ? STREAM_SERVER_BIND : (STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
    $srv = @stream_socket_server($uri, $errno, $errstr, $flags);
    if (!$srv) { fwrite(STDERR, "Impossible d'écouter sur $uri : $errstr ($errno)\n"); exit(1); }
    stream_set_blocking($srv, false);
    $sockets[(int)$srv] = ['res' => $srv, 'proto' => $p];
    logline("écoute $p://$BIND:$port");
}

// ── Listener TLS optionnel (RFC 5425, port 6514 par défaut) ─────────────────
if ((int) get_setting('syslog_tls_enabled', '0')) {
    $tlsPort = (int) get_setting('syslog_tls_port', '6514');
    $cert    = get_setting('syslog_tls_cert', '');
    $key     = get_setting('syslog_tls_key', '');
    if ($cert === '' || !is_readable($cert)) {
        logline("TLS activé mais certificat illisible ($cert) — listener TLS ignoré");
    } else {
        $ssl = [
            'local_cert'        => $cert,
            'verify_peer'       => false,   // serveur : on n'exige pas de cert client
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'ciphers'           => 'HIGH:!aNULL:!MD5:!3DES',
        ];
        if ($key !== '' && is_readable($key)) $ssl['local_pk'] = $key;
        $ctx    = stream_context_create(['ssl' => $ssl]);
        $uri    = "tls://$BIND:$tlsPort";
        $tlssrv = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
        if (!$tlssrv) {
            logline("TLS : impossible d'écouter sur $uri : $errstr ($errno)");
        } else {
            stream_set_blocking($tlssrv, false);
            $sockets[(int)$tlssrv] = ['res' => $tlssrv, 'proto' => 'tls'];
            logline("écoute tls://$BIND:$tlsPort");
        }
    }
}

// ── Buffer d'insertion ───────────────────────────────────────────────────
$buffer = [];

// Erreur de connexion (la connexion d'un démon peut être coupée par MySQL après
// inactivité : « server has gone away » / « Lost connection »).
function is_conn_error(\Throwable $e): bool {
    $m = $e->getMessage();
    return stripos($m, 'gone away') !== false
        || stripos($m, 'Lost connection') !== false
        || stripos($m, 'Error while sending') !== false
        || stripos($m, 'not connected') !== false
        || in_array((string)$e->getCode(), ['2006', '2013', 'HY000', '08S01'], true);
}

function flush_buffer(): void {
    global $buffer, $BATCH;
    if (!$buffer) return;

    try { $db = db(); }
    catch (\Throwable $e) { logline('DB indisponible, buffer conservé : ' . $e->getMessage()); return; }

    while ($buffer) {
        $chunk = array_slice($buffer, 0, $BATCH);     // on ne retire PAS encore : seulement après succès
        $ph  = implode(',', array_fill(0, count($chunk), "(?,?,?,?,?,?,?,?,'syslog',?,?,?)"));
        $sql = "INSERT INTO logs (log_time,host,source_ip,facility,severity,program,pid,message,source,os,sec_event,cve) VALUES $ph";
        $rows = []; $notify = [];
        foreach ($chunk as $r) { foreach ($r['cols'] as $c) $rows[] = $c; $notify[] = $r['notify']; }

        $ok = false; $drop = false;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $db->beginTransaction();
                $db->prepare($sql)->execute($rows);
                $db->commit();
                $ok = true; break;
            } catch (\Throwable $e) {
                try { if ($db->inTransaction()) $db->rollBack(); } catch (\Throwable $x) {}
                if (is_conn_error($e)) {
                    if ($attempt === 1) {
                        logline('connexion DB perdue → reconnexion');
                        try { $db = db(true); continue; }       // reconnecte et réessaie ce lot
                        catch (\Throwable $x) { logline('reconnexion échouée : ' . $x->getMessage()); }
                    }
                    return;   // DB indisponible : on GARDE le buffer pour le prochain tick
                }
                logline('ERREUR insert (lot rejeté) : ' . $e->getMessage());
                $drop = true; break;                            // erreur de données : on abandonne ce lot
            }
        }

        if (!$ok && !$drop) return;                             // sécurité : garder le buffer
        array_splice($buffer, 0, count($chunk));                // lot traité (ou définitivement rejeté)
        if ($ok) foreach ($notify as $n) {
            try { maybe_notify_discord($n); maybe_notify_email($n); }
            catch (\Throwable $e) { logline('notif : ' . $e->getMessage()); }
        }
    }
    if (function_exists('process_discord_queue')) { try { process_discord_queue(); } catch (\Throwable $e) {} }
}

// ── Parsing d'un message syslog (mutualisé : includes/syslog_parse.php) ──────
require __DIR__ . '/includes/syslog_parse.php';

const MAX_BUFFER = 50000;   // plafond anti-saturation si la DB reste indisponible
$dropped = 0;

function ingest(string $raw, string $peer): void {
    global $buffer, $dropped;
    $ip = preg_replace('/:\d+$/', '', $peer);   // "ip:port" → "ip"
    $ip = trim($ip, '[]');                       // IPv6 éventuel
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if (count($buffer) >= MAX_BUFFER) {     // DB durablement injoignable : on jette les plus récents
            if (++$dropped % 1000 === 1) logline("buffer plein (" . MAX_BUFFER . ") — messages abandonnés : $dropped");
            continue;
        }
        $row = parse_syslog($line, $ip);
        if ($row) $buffer[] = $row;
    }
}

// Découpe un flux (TCP/TLS) en messages complets, en gérant les DEUX cadrages
// syslog : RFC 6587 octet-counting (« <len> SP message ») et délimité par newline.
// Laisse le reliquat partiel dans $buf.
function extract_frames(string &$buf): array {
    $msgs = [];
    while ($buf !== '') {
        if (preg_match('/^(\d{1,7}) /', $buf, $m)) {                 // octet-counting
            $len = (int)$m[1]; $prefix = strlen($m[0]);
            if ($len > 0 && $len <= 1048576) {
                if (strlen($buf) < $prefix + $len) break;            // trame incomplète → on attend
                $msgs[] = substr($buf, $prefix, $len);
                $buf    = substr($buf, $prefix + $len);
                continue;
            }
            // longueur absurde → on retombe sur le découpage par newline
        }
        $pos = strpos($buf, "\n");
        if ($pos === false) break;                                   // ligne incomplète → on attend
        $msgs[] = substr($buf, 0, $pos);
        $buf    = substr($buf, $pos + 1);
    }
    return $msgs;
}

// ── Boucle d'événements ─────────────────────────────────────────────────────
logline("LogFlow syslogd démarré (proto=$proto, batch=$BATCH, flush={$FLUSH_S}s)");
$last_flush = time();

while (true) {
    $read = [];
    foreach ($sockets as $s) $read[] = $s['res'];
    foreach ($clients as $c)  $read[] = $c['res'];
    $write = $except = null;

    $n = @stream_select($read, $write, $except, $FLUSH_S);

    if ($n > 0) {
        foreach ($read as $r) {
            $key = (int)$r;
            // Socket serveur ?
            if (isset($sockets[$key])) {
                if ($sockets[$key]['proto'] === 'udp') {
                    $peer = '';
                    $data = @stream_socket_recvfrom($r, 65535, 0, $peer);
                    if ($data !== false && $data !== '') ingest($data, $peer);
                } else { // TCP ou TLS : nouvelle connexion (handshake TLS sous timeout)
                    $isTls = $sockets[$key]['proto'] === 'tls';
                    $conn  = @stream_socket_accept($r, $isTls ? 3 : 0, $peer);
                    if ($conn) { stream_set_blocking($conn, false); $clients[(int)$conn] = ['res' => $conn, 'peer' => $peer, 'buf' => '']; }
                    elseif ($isTls) { logline('TLS : handshake/accept échoué'); }
                }
            }
            // Flux TCP client
            elseif (isset($clients[$key])) {
                $data = @fread($r, 65535);
                if ($data === '' || $data === false) {
                    if (feof($r)) { if ($clients[$key]['buf'] !== '') ingest($clients[$key]['buf'], $clients[$key]['peer']); fclose($r); unset($clients[$key]); }
                } else {
                    $clients[$key]['buf'] .= $data;
                    foreach (extract_frames($clients[$key]['buf']) as $msg) ingest($msg, $clients[$key]['peer']);
                    // Garde-fou : un client qui n'envoie jamais de trame complète ne sature pas la RAM.
                    if (strlen($clients[$key]['buf']) > 2097152) $clients[$key]['buf'] = '';
                }
            }
        }
    }

    if (count($buffer) >= $BATCH || (time() - $last_flush) >= $FLUSH_S) {
        try { flush_buffer(); }
        catch (\Throwable $e) { logline('flush_buffer (exception inattendue, démon maintenu) : ' . $e->getMessage()); }
        $last_flush = time();
    }
}
