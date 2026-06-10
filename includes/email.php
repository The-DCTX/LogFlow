<?php
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/db.php';

const EMAIL_SEV_EMOJI = [0=>'🆘',1=>'🚨',2=>'🔴',3=>'🟠',4=>'🟡',5=>'🔵',6=>'🟢',7=>'⚪'];
const EMAIL_SEV_LABEL = [0=>'EMERGENCY',1=>'ALERT',2=>'CRITICAL',3=>'ERROR',4=>'WARNING',5=>'NOTICE',6=>'INFO',7=>'DEBUG'];

// ── Enfile la notification email si les critères sont remplis ──
function maybe_notify_email(array $log): void
{
    if (!(bool)(int)get_setting('smtp_enabled', '0')) return;
    $to = get_setting('smtp_to', '');
    if (empty($to)) return;

    $min_sev  = (int)get_setting('smtp_min_severity', '3');
    $sec_only = (bool)(int)get_setting('smtp_sec_events_only', '0');

    $sev = (int)($log['severity'] ?? 6);
    if ($sev > $min_sev) return;
    if ($sec_only && empty($log['sec_event'])) return;

    try {
        send_email($to, $log);
    } catch (\Throwable $e) {
        error_log('[LogFlow/Email] ' . $e->getMessage());
    }
}

// ── Envoie un email (utilisé aussi pour les tests) ──────────────
function send_email(string $to, array $log, bool $is_test = false): bool
{
    $smtp_host = get_setting('smtp_host', '');
    $port      = (int)get_setting('smtp_port', '587');
    $user      = get_setting('smtp_user', '');
    $pass      = get_setting('smtp_pass', '');
    $from      = get_setting('smtp_from', '') ?: $user;

    if (empty($smtp_host) || empty($user) || empty($from)) {
        throw new \RuntimeException('Configuration SMTP incomplète (host/user/from requis)');
    }

    $sev   = (int)($log['severity'] ?? 6);
    $emoji = EMAIL_SEV_EMOJI[$sev] ?? '📋';
    $label = EMAIL_SEV_LABEL[$sev] ?? "Sev $sev";
    $hname = $log['host'] ?? 'unknown';
    $prog  = $log['program'] ?? '—';
    $msg   = $log['message'] ?? '';
    $os    = $log['os'] ?? '—';
    $sec   = $log['sec_event'] ?? null;
    $src   = $log['source_ip'] ?? '—';

    $subject = $is_test
        ? '[LogFlow] Test alerte email — ' . APP_NAME
        : "[LogFlow] $emoji $label — $hname";

    // ── Corps HTML ─────────────────────────────────────────────
    $rows_html = '';
    foreach ([
        ['Hôte', $hname], ['Programme', $prog], ['OS', $os],
        ['Sévérité', "$label ($sev)"], ['Source IP', $src],
    ] as [$k, $v]) {
        $rows_html .= '<tr>'
            . '<td style="padding:6px 12px;background:#1e242c;color:#8b949e;width:120px;font-size:12px">' . htmlspecialchars($k) . '</td>'
            . '<td style="padding:6px 12px;background:#0d1117;font-family:monospace;font-size:12px;color:#c9d1d9">' . htmlspecialchars((string)$v) . '</td>'
            . '</tr>';
    }
    if ($sec) {
        $rows_html .= '<tr>'
            . '<td style="padding:6px 12px;background:#1e242c;color:#f85149;width:120px;font-size:12px">Événement SEC</td>'
            . '<td style="padding:6px 12px;background:#0d1117;font-family:monospace;font-size:12px;color:#f85149">' . htmlspecialchars($sec) . '</td>'
            . '</tr>';
    }

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="background:#0d1117;color:#c9d1d9;margin:0;padding:20px;font-family:sans-serif">'
        . '<div style="max-width:620px;margin:0 auto;background:#0d1117">'
        . '<h2 style="color:#58a6ff;border-bottom:1px solid #30363d;padding-bottom:10px;font-size:18px">'
        . htmlspecialchars($subject) . '</h2>'
        . '<table style="width:100%;border-collapse:collapse;margin:16px 0">' . $rows_html . '</table>'
        . '<div style="background:#161b22;border:1px solid #30363d;border-radius:6px;padding:12px;font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-all;color:#c9d1d9">'
        . htmlspecialchars($msg)
        . '</div>'
        . '<p style="color:#8b949e;font-size:11px;margin-top:16px">LogFlow Security Monitor · ' . date('Y-m-d H:i:s') . '</p>'
        . '</div></body></html>';

    $text = "[$label] $hname — $prog\n\n$msg\n\nOS: $os | Source IP: $src"
          . ($sec ? "\nÉvénement SEC: $sec" : '');

    return smtp_send($smtp_host, $port, $user, $pass, $from, $to, $subject, $html, $text);
}

// ── Client SMTP minimal (AUTH LOGIN, STARTTLS sur 587, SSL sur 465) ─
function smtp_send(string $host, int $port, string $user, string $pass, string $from, string $to, string $subject, string $html, string $text): bool
{
    $use_ssl = ($port === 465);
    $use_tls = in_array($port, [587, 2525]);
    $prefix  = $use_ssl ? 'ssl://' : '';

    $ctx = stream_context_create(['ssl' => [
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'allow_self_signed' => false,
    ]]);

    $fp = @stream_socket_client("{$prefix}{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new \RuntimeException("Connexion SMTP impossible : $errstr ($errno)");
    }
    stream_set_timeout($fp, 15);

    // Lit la réponse SMTP (multi-lignes) et vérifie le code de réponse
    $expect = function(string $code) use ($fp): bool {
        $line = '';
        do {
            $l = fgets($fp, 4096);
            if ($l === false) return false;
            $line = $l;
        } while (strlen($line) > 3 && $line[3] === '-');
        return str_starts_with($line, $code);
    };
    $send = fn(string $s) => fwrite($fp, $s . "\r\n");

    if (!$expect('220')) { fclose($fp); throw new \RuntimeException('Pas de bannière SMTP (220)'); }
    $send('EHLO logflow');
    if (!$expect('250')) { fclose($fp); throw new \RuntimeException('EHLO refusé'); }

    if ($use_tls) {
        $send('STARTTLS');
        if (!$expect('220')) { fclose($fp); throw new \RuntimeException('STARTTLS refusé'); }
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send('EHLO logflow');
        if (!$expect('250')) { fclose($fp); throw new \RuntimeException('EHLO post-STARTTLS refusé'); }
    }

    $send('AUTH LOGIN');
    if (!$expect('334')) { fclose($fp); throw new \RuntimeException('AUTH LOGIN refusé'); }
    $send(base64_encode($user));
    if (!$expect('334')) { fclose($fp); throw new \RuntimeException('Identifiant SMTP refusé'); }
    $send(base64_encode($pass));
    if (!$expect('235')) { fclose($fp); throw new \RuntimeException('Authentification SMTP échouée (mot de passe incorrect ?)'); }

    $send("MAIL FROM:<{$from}>");
    if (!$expect('250')) { fclose($fp); throw new \RuntimeException("MAIL FROM refusé pour $from"); }
    $send("RCPT TO:<{$to}>");
    if (!$expect('250')) { fclose($fp); throw new \RuntimeException("RCPT TO refusé pour $to"); }
    $send('DATA');
    if (!$expect('354')) { fclose($fp); throw new \RuntimeException('DATA refusé'); }

    $boundary  = 'lf_' . md5(uniqid('', true));
    $subj_enc  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $body      = "From: LogFlow <{$from}>\r\n"
               . "To: {$to}\r\n"
               . "Subject: {$subj_enc}\r\n"
               . 'Date: ' . date('r') . "\r\n"
               . "MIME-Version: 1.0\r\n"
               . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
               . "X-Mailer: LogFlow\r\n\r\n"
               . "--{$boundary}\r\n"
               . "Content-Type: text/plain; charset=UTF-8\r\n"
               . "Content-Transfer-Encoding: base64\r\n\r\n"
               . chunk_split(base64_encode($text)) . "\r\n"
               . "--{$boundary}\r\n"
               . "Content-Type: text/html; charset=UTF-8\r\n"
               . "Content-Transfer-Encoding: base64\r\n\r\n"
               . chunk_split(base64_encode($html)) . "\r\n"
               . "--{$boundary}--";

    $send($body . "\r\n.");
    if (!$expect('250')) { fclose($fp); throw new \RuntimeException('Message refusé par le serveur SMTP'); }

    $send('QUIT');
    fclose($fp);
    return true;
}
