<?php
require_once __DIR__ . '/db.php';   // active_parse_rules() consulte la base
/*
 * Détection automatique des événements de sécurité.
 * Retourne ['event' => string, 'severity' => int] ou null.
 */

const SEC_EVENTS = [

    // ── Exploitation de vulnérabilité (CVE) ──────────────────
    'exploit_attempt' => [
        'label'    => 'Exploitation (CVE)',
        'color'    => '#f85149',
        'icon'     => '🧨',
        'severity' => 1,
        'patterns' => [],   // alimenté par les signatures CVE (includes/cve.php), pas par regex ici
    ],

    // ── Authentification ─────────────────────────────────────
    'auth_fail' => [
        'label'    => 'Auth échouée',
        'color'    => '#f85149',
        'icon'     => '🔴',
        'severity' => 3,
        'patterns' => [
            '/Failed password/i',
            '/authentication failure/i',
            '/Invalid user/i',
            '/FAILED LOGIN/i',
            '/Login incorrect/i',
            '/Logon failure/i',             // Windows 4625
            '/EventID: 4625/i',
            '/Kerberos pre-authentication failed/i',
            '/pam_unix.*auth.*failure/i',
            '/Authorization failure/i',
            '/failed to (log|sign)\s?in/i', // Synology DSM (Connection: User [..] failed to sign in to [..])
            '/login failed/i',              // NAS / équipements réseau divers
            '/authentication failed/i',
        ],
    ],

    'auth_success' => [
        'label'    => 'Auth réussie',
        'color'    => '#3fb950',
        'icon'     => '🟢',
        'severity' => null,
        'patterns' => [
            '/Accepted (password|publickey|keyboard)/i',
            '/session opened for user/i',
            '/Successful logon/i',
            '/EventID: 4624/i',
            '/User logged in/i',
            '/signed in to/i',              // Synology DSM (User [..] signed in to [..] successfully)
            '/pam_unix.*session.*opened/i',
        ],
    ],

    'auth_brute' => [
        'label'    => 'Brute-force',
        'color'    => '#f85149',
        'icon'     => '🚨',
        'severity' => 2,
        'patterns' => [
            '/Too many authentication failures/i',
            '/maximum authentication attempts exceeded/i',
            '/Blocking.*brute/i',
            '/fail2ban.*Ban/i',
            '/repeated login failures/i',
        ],
    ],

    // ── Comptes ───────────────────────────────────────────────
    'account_create' => [
        'label'    => 'Compte créé',
        'color'    => '#a371f7',
        'icon'     => '👤',
        'severity' => 5,
        'patterns' => [
            '/new user:/i',
            '/useradd/i',
            '/EventID: 4720/i',             // Windows: user account created
            '/User account created/i',
        ],
    ],

    'account_delete' => [
        'label'    => 'Compte supprimé',
        'color'    => '#f0883e',
        'icon'     => '🗑️',
        'severity' => 4,
        'patterns' => [
            '/userdel/i',
            '/EventID: 4726/i',
            '/User account deleted/i',
        ],
    ],

    'account_lockout' => [
        'label'    => 'Compte verrouillé',
        'color'    => '#f85149',
        'icon'     => '🔒',
        'severity' => 3,
        'patterns' => [
            '/account.*locked/i',
            '/EventID: 4740/i',
            '/pam_tally/i',
            '/pam_faillock.*account locked/i',
        ],
    ],

    'account_change' => [
        'label'    => 'Modif. compte',
        'color'    => '#d29922',
        'icon'     => '✏️',
        'severity' => 5,
        'patterns' => [
            '/passwd.*changed/i',
            '/password changed for/i',
            '/EventID: 4723/i',
            '/EventID: 4724/i',
            '/chage/i',
            '/usermod/i',
        ],
    ],

    // ── Privilèges ────────────────────────────────────────────
    'sudo' => [
        'label'    => 'Sudo / Privilege',
        'color'    => '#d29922',
        'icon'     => '⚡',
        'severity' => null,
        'patterns' => [
            '/sudo:.*COMMAND/i',
            '/su:.*session opened/i',
            '/EventID: 4648/i',             // Logon with explicit credentials
            '/EventID: 4672/i',             // Special privileges assigned
            '/RunAs/i',
        ],
    ],

    'sudo_fail' => [
        'label'    => 'Sudo échoué',
        'color'    => '#f85149',
        'icon'     => '⛔',
        'severity' => 3,
        'patterns' => [
            '/sudo:.*incorrect password/i',
            '/sudo:.*3 incorrect password/i',
            '/sudo:.*NOT in sudoers/i',
            '/authentication failure.*sudo/i',
        ],
    ],

    'group_change' => [
        'label'    => 'Modif. groupe',
        'color'    => '#a371f7',
        'icon'     => '👥',
        'severity' => 4,
        'patterns' => [
            '/EventID: 4728/i',
            '/EventID: 4732/i',
            '/EventID: 4756/i',
            '/added to group/i',
            '/gpasswd.*adding/i',
            '/usermod.*-G/i',
        ],
    ],

    // ── Services & Système ────────────────────────────────────
    'service_install' => [
        'label'    => 'Service installé',
        'color'    => '#f0883e',
        'icon'     => '⚙️',
        'severity' => 4,
        'patterns' => [
            '/EventID: 7045/i',
            '/New service installed/i',
            '/systemctl.*enable/i',
        ],
    ],

    'log_cleared' => [
        'label'    => 'Logs effacés',
        'color'    => '#f85149',
        'icon'     => '🗑️',
        'severity' => 1,
        'patterns' => [
            '/EventID: 1102/i',
            '/EventID: 104/i',
            '/audit.*log.*cleared/i',
            '/log file.*cleared/i',
        ],
    ],

    // ── Réseau ────────────────────────────────────────────────
    'ssh_connect' => [
        'label'    => 'Connexion SSH',
        'color'    => '#58a6ff',
        'icon'     => '🔑',
        'severity' => null,
        'patterns' => [
            '/sshd.*Connection from/i',
            '/sshd.*Accepted/i',
            '/New connection.*sshd/i',
        ],
    ],

    'firewall_block' => [
        'label'    => 'Firewall block',
        'color'    => '#f85149',
        'icon'     => '🛡️',
        'severity' => 4,
        'patterns' => [
            '/UFW BLOCK/i',
            '/iptables.*DROP/i',
            '/Firewall.*blocked/i',
            '/DENY.*rule/i',
            '/Windows Firewall.*blocked/i',
        ],
    ],

    'rdp_connect' => [
        'label'    => 'Connexion RDP',
        'color'    => '#58a6ff',
        'icon'     => '🖥️',
        'severity' => null,
        'patterns' => [
            '/EventID: 4778/i',             // RDP reconnect
            '/EventID: 21/i',               // TerminalServices
            '/EventID: 25/i',
            '/Remote Desktop.*connected/i',
        ],
    ],

    // ── Processus suspect ─────────────────────────────────────
    'process_suspicious' => [
        'label'    => 'Proc. suspect',
        'color'    => '#f0883e',
        'icon'     => '⚠️',
        'severity' => 3,
        'patterns' => [
            '/EventID: 4688/i',             // Process creation
            '/cmd\.exe.*\/c/i',
            '/powershell.*-enc/i',
            '/powershell.*bypass/i',
            '/wscript.*\.vbs/i',
            '/base64.*decode/i',
        ],
    ],
];

// Borne défensive contre le ReDoS sur l'ensemble des regex de détection.
@ini_set('pcre.backtrack_limit', '200000');

// Règles de classification apprises (table parse_rules), validées par un humain.
// Cache process avec TTL court : le démon long-running récupère les nouvelles
// règles sans redémarrage. Tolérant aux pannes : renvoie [] en cas d'erreur DB.
function active_parse_rules(): array {
    static $cache = null;
    static $loadedAt = 0;
    $now = time();
    if ($cache === null || ($now - $loadedAt) > 30) {
        try {
            $cache = db()->query(
                "SELECT program_match, pattern, sec_event FROM parse_rules WHERE enabled = 1"
            )->fetchAll();
        } catch (\Throwable $e) {
            $cache = $cache ?? [];      // garde l'ancien cache si dispo, sinon vide
        }
        $loadedAt = $now;
    }
    return $cache;
}

function detect_sec_event(string $message, string $program = ''): ?array {
    $text = $program . ' ' . $message;

    // 1) Motifs codés en dur — prioritaires (référence stable).
    foreach (SEC_EVENTS as $key => $def) {
        foreach ($def['patterns'] as $pattern) {
            if (preg_match($pattern, $text)) {
                return ['event' => $key, 'severity' => $def['severity']];
            }
        }
    }

    // 2) Règles apprises (additives). Restreintes à une clé SEC_EVENTS connue ;
    //    chaque regex est encadrée pour qu'une règle fautive n'impacte rien.
    foreach (active_parse_rules() as $r) {
        $pm = $r['program_match'] ?? '';
        if ($pm !== '' && stripos($program, $pm) === false) continue;
        if (!isset(SEC_EVENTS[$r['sec_event']])) continue;
        $ok = @preg_match('/' . $r['pattern'] . '/i', $message);   // pattern déjà échappé pour le délimiteur /
        if ($ok === 1) {
            return ['event' => $r['sec_event'], 'severity' => SEC_EVENTS[$r['sec_event']]['severity'] ?? null];
        }
    }
    return null;
}
