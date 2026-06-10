# Migrations de schéma

Ce dossier contient les **évolutions du schéma** appliquées **après** la base
posée par `install.sql` (la *baseline*). Au démarrage, `migrate.php` exécute
automatiquement les migrations pas encore appliquées et les enregistre dans la
table `schema_migrations`.

## Convention de nommage

```
NNN_description_courte.sql
```

- `NNN` = numéro à 3 chiffres, croissant (`001`, `002`, …) → ordre d'application.
- Appliquées **une seule fois**, dans l'ordre lexical.

Exemple : `001_add_alert_thresholds.sql`

## Règles

1. **Toujours additif / non destructif** : `CREATE TABLE IF NOT EXISTS`,
   `ALTER TABLE … ADD COLUMN`, `CREATE INDEX IF NOT EXISTS`, `INSERT IGNORE`…
   Jamais de `DROP`/`DELETE` qui perdrait des données d'utilisateurs.
2. **Idempotent autant que possible** (le runner ne réexécute pas une migration
   déjà enregistrée, mais l'idempotence sécurise les reprises après échec).
3. `install.sql` reste **figé** sur le schéma de référence : toute modification
   ultérieure passe par une nouvelle migration ici, jamais en éditant `install.sql`.

## Application

- **Docker** : automatique au démarrage du conteneur (entrypoint → `migrate.php`).
- **Manuel** : `php migrate.php` depuis la racine du projet.
