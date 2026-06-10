# Politique de sécurité

## Signaler une vulnérabilité

Merci de **ne pas** ouvrir d'issue publique pour une faille de sécurité.

Utilisez plutôt les **GitHub Security Advisories** de ce dépôt
(*Security → Advisories → Report a vulnerability*) pour un signalement privé.

Objectif de réponse : sous 72 h, avec correction prioritaire des failles critiques.

## À faire impérativement après l'installation

LogFlow est livré avec des valeurs par défaut **destinées à être changées** :

- Changez les identifiants par défaut **`admin` / `logflow2026`** via *Setup → Sécurité*.
- Définissez un mot de passe MariaDB fort dans `config.php` (jamais `CHANGE_ME`).
- Régénérez la **clé API** par défaut dans *Setup* ; ne la committez jamais.
- Servez LogFlow derrière **HTTPS** (reverse proxy) et n'exposez pas le port
  applicatif directement sur Internet.
- `config.php` n'est jamais versionné (voir `.gitignore`).

## Périmètre

- L'endpoint `POST /api/receive.php` est authentifié par en-tête `X-Api-Key`.
- Les pages d'administration sont protégées par session ; seuls
  `login.php`, `logout.php`, `api/receive.php` et `agents/download.php` sont publics.

## Versions supportées

Seule la dernière version publiée reçoit les correctifs de sécurité.
