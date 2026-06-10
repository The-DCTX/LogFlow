<div align="center">

# 🛰️ LogFlow

**Collecte, visualisation et analyse de logs syslog multi-OS — léger, auto-hébergé, sans framework.**

![License](https://img.shields.io/badge/license-AGPL--3.0-blue)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-11-003545?logo=mariadb&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)

</div>

---

LogFlow centralise les journaux de vos serveurs et postes (Linux, Windows, macOS)
dans une interface dark moderne : tableau de bord à widgets, détection
d'événements de sécurité, alertes, et rapports — le tout déployable en **une commande**.

> 📸 _Captures d'écran à venir._
<!-- Déposez vos images dans docs/screenshots/ puis décommentez :
<div align="center">
  <img src="docs/screenshots/dashboard.png" width="80%" alt="Tableau de bord">
  <img src="docs/screenshots/logs.png" width="80%" alt="Viewer de logs">
</div>
-->

## ✨ Fonctionnalités

- **Agents multi-OS** générés à la volée (Linux `tail`, Windows `Get-WinEvent`/Sysmon, macOS `log stream`) — vous choisissez les sources à collecter.
- **Tableau de bord à widgets** personnalisable (drag-and-drop) : compteurs, timelines, camemberts, top-N, tables.
- **Détection de sécurité** : 18 types d'événements (brute-force, sudo, comptes, pare-feu…) + **recatégorisation de sévérité** par règles.
- **Alertes** Discord (file asynchrone) et **e-mail** (client SMTP natif, sans dépendance).
- **Rétention automatique** (purge cron) et **rapport de sécurité imprimable** (PDF).
- **Viewer temps réel** avec recherche full-text, filtres et raccourcis clavier.
- **Authentification** web par session ; API d'ingestion protégée par clé.

## 🚀 Démarrage rapide (Docker)

```bash
git clone https://github.com/<votre-compte>/logflow.git && cd logflow
cp .env.example .env        # adaptez les mots de passe
docker compose up -d
```

Ouvrez **http://localhost:8085** — le tableau de bord est déjà peuplé d'un jeu de
données de démonstration. Connexion par défaut : **`admin` / `logflow2026`**
(à changer immédiatement dans _Setup → Sécurité_).

Détails et personnalisation : voir [`DOCKER.md`](DOCKER.md).

## 🔧 Installation manuelle

Prérequis : PHP 8.4 (`pdo_mysql`), MariaDB/MySQL, Apache (ou nginx).

```bash
cp config.php.example config.php          # renseignez vos identifiants DB
mysql -u logflow -p logflow < install.sql # crée le schéma
```

Servez le dossier via votre vhost (le répertoire `includes/` doit être protégé,
voir `docker/apache-logflow.conf` pour un exemple).

## 🖥️ Déployer un agent

Depuis la page **Setup**, générez un **token d'installation** (révocable), puis
choisissez l'OS et les sources : LogFlow génère une commande prête à coller.
Exemple Linux :

```bash
curl -fsSL "http://<serveur>:8085/agents/download.php?os=linux&token=VOTRE_TOKEN&sources=auth_log,secure,ufw" | sudo bash
```

> 🔐 Le token d'enrôlement protège la clé API : `download.php` ne sert jamais
> d'agent sans un token valide, et chaque token est révocable depuis _Setup_.

Les logs peuvent aussi être envoyés directement à `POST /api/receive.php`
(en-tête `X-Api-Key`).

## 🔒 Sécurité

Avant toute mise en production, lisez [`SECURITY.md`](SECURITY.md) :
changez les identifiants par défaut, définissez un mot de passe MariaDB fort,
régénérez la clé API et placez LogFlow derrière HTTPS.

## 🧱 Stack

PHP 8.4 vanilla · MariaDB (PDO) · Apache/nginx · Bootstrap 5.3 (dark) · Chart.js 4.
Aucun framework, aucune dépendance Composer obligatoire.

## 📄 Licence

Distribué sous licence **AGPL-3.0**. Voir [`LICENSE`](LICENSE).
