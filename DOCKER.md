# Déploiement avec Docker

LogFlow se lance entièrement (PHP 8.4 + Apache + MariaDB) en une commande.

## Démarrage rapide

```bash
git clone <url-du-repo> logflow && cd logflow
cp .env.example .env          # adaptez les mots de passe
docker compose up -d
```

Ouvrez ensuite **http://localhost:8085**.

- Login par défaut : **`admin` / `logflow2026`** → changez-le dans *Setup → Sécurité*.
- Le dashboard est pré-rempli avec un **jeu de données de démonstration** (~400 logs).

## Composition

| Service | Image | Rôle |
|---------|-------|------|
| `app`   | build local (`Dockerfile`, `php:8.4-apache`) | Interface web + API |
| `db`    | `mariadb:11` | Stockage des logs (volume `db_data`) |

Le schéma (`install.sql`) et la démo (`db/demo-data.sql`) sont chargés
automatiquement à la **première** création du volume de base de données.

## Personnalisation

Tout passe par `.env` :

| Variable | Défaut | Description |
|----------|--------|-------------|
| `APP_PORT` | `8085` | Port HTTP exposé sur l'hôte |
| `DB_NAME` / `DB_USER` / `DB_PASS` | `logflow` | Identifiants MariaDB de l'app |
| `MARIADB_ROOT_PASSWORD` | — | Mot de passe root MariaDB |
| `SERVER_URL` | `http://localhost:8085` | URL publique utilisée par les agents ; injectée au 1er démarrage (ne remplace pas une valeur changée ensuite dans Setup) |

## Mise à jour

**Image pré-construite (recommandé)** — aucune compilation locale :

```bash
docker compose pull && docker compose up -d
```

**Build local** (fork / développement) :

```bash
docker compose up -d --build
```

> Les évolutions du **schéma** sont appliquées **automatiquement au démarrage**
> du conteneur (`migrate.php` + dossier `migrations/`, tracées dans la table
> `schema_migrations`). Un simple `docker compose pull && docker compose up -d`
> met à jour le **code et le schéma** sans perte de données.
> Pour ajouter une évolution : voir [`migrations/README.md`](migrations/README.md).

## Repartir d'une base vierge (sans démo)

```bash
docker compose down -v        # supprime le volume db_data
# retirez le montage de db/demo-data.sql dans docker-compose.yml, puis :
docker compose up -d
```

Ou videz simplement la table : `TRUNCATE TABLE logs;`

## Envoyer de vrais logs

Depuis la page **Setup**, générez un **token d'installation**, puis copiez la
commande d'agent proposée (elle utilise ce token, jamais la clé API en clair).
Les logs peuvent aussi être envoyés directement à `POST /api/receive.php`
(en-tête `X-Api-Key`).
