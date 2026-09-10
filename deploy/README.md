# Outillage de deploiement et d'exploitation OpsTrack

| Fichier | Role |
| --- | --- |
| `provision.sh` | Provisionne une machine Ubuntu 24.04 : paquets, modules Apache, base et compte MySQL applicatif dedie, clone du depot, vhost, unite systemd, droits, pare-feu. **Idempotent** |
| `deploy.sh` | Promotion qualification -> production : controles prealables, point de rollback, deploiement, smoke test, rollback automatique |
| `smoke.sh` | Controles post-deploiement, utilisable seul contre n'importe quelle URL |
| `gendoc.py` | Generation de la documentation technique a partir du code source |
| `apache/opstrack.conf` | Vhost durci. Le bloc HTTPS est ajoute et maintenu par certbot |
| `systemd/opstrack-dispatch-dashboard.service` | Microservice Next.js sous `www-data`, avec durcissement systemd |
| `.env.production.example` | Modele de configuration de production, sans aucun secret |
| `supervision/opstrack-healthcheck.sh` | Sonde executee toutes les 5 minutes |
| `supervision/opstrack-backup.sh` | Sauvegarde quotidienne MySQL, MongoDB, `.env` et `storage` |
| `supervision/opstrack-restore.sh` | Procedure de restauration |
| `supervision/opstrack-cron` | Taches planifiees, a installer dans `/etc/cron.d/opstrack` |
| `supervision/opstrack-logrotate` | Rotation des journaux, a installer dans `/etc/logrotate.d/opstrack` |

## Mise en service d'une machine

```bash
git clone https://github.com/Slamelabri/dfs-bloc4-evaluation-app.git
cd dfs-bloc4-evaluation-app
sudo -E DB_APP_PASSWORD='<genere>' APP_DOMAIN='<domaine>' ./deploy/provision.sh
# renseigner .env a partir de deploy/.env.production.example
sudo -u www-data php artisan key:generate --force
sudo -u www-data php artisan migrate --force --seed
sudo certbot --apache -d <domaine> --redirect --agree-tos -m <contact> -n
./deploy/smoke.sh https://<domaine> "$OPSTRACK_API_TOKEN"
```

## Deploiement

```bash
SSH_KEY=~/ubuntu.pem OPSTRACK_API_TOKEN=<token> ./deploy/deploy.sh main
```

| Code de sortie | Signification | Urgence |
| --- | --- | --- |
| 0 | Deploiement reussi et verifie | Aucune |
| 1 | Deploiement refuse, production restauree et verifiee | Heures ouvrees |
| 2 | Rollback en echec, production potentiellement degradee | Immediate |

Le meme script est appele par `.github/workflows/deploy.yml`
(declenchement `workflow_dispatch`).

## Supervision

```bash
sudo install -m 750 deploy/supervision/*.sh /opt/opstrack/supervision/
sudo install -m 644 deploy/supervision/opstrack-cron      /etc/cron.d/opstrack
sudo install -m 644 deploy/supervision/opstrack-logrotate /etc/logrotate.d/opstrack
sudo mkdir -p /var/log/opstrack /var/backups/opstrack && sudo chmod 700 /var/backups/opstrack
```

Journaux : `/var/log/opstrack/healthcheck.log` (tous les controles),
`/var/log/opstrack/alerts.log` (**anomalies uniquement, a consulter en premier**),
`/var/log/opstrack/backup.log`.

## Generation de la documentation technique

```bash
python3 deploy/gendoc.py . <repertoire_de_sortie>
php artisan route:list --except-vendor
```

`gendoc.py` analyse `app/` et `tests/` et produit une reference Markdown des
classes (namespaces, constantes, proprietes, methodes publiques et internes,
blocs de documentation), regroupee par couche applicative. Les dependances
tierces sont exclues.

## Point d'attention : extension MongoDB

Les machines fournies embarquent `php8.4-mongodb 2.1.4`, alors que
`mongodb/mongodb 2.2.0` (fige dans `composer.lock`) declare `ext-mongodb: ^2.2`.

La contrainte n'est donc pas satisfaite au sens de Composer, mais la
bibliotheque fonctionne a l'execution avec le pilote 2.1.4 (verifie sur la
qualification : connexion et `listDatabases()` operationnels).

Les scripts utilisent donc `--ignore-platform-req=ext-mongodb`, de facon
explicite et identique sur les deux environnements.

Resolution durable, hors epreuve : mettre a jour l'extension
(`pecl install mongodb-2.2.0` apres installation de `php8.4-dev` et
`php-pear`), puis retirer le drapeau des scripts.

## Point d'attention : npm sous www-data

Le `HOME` de `www-data` (`/var/www`) n'est pas inscriptible : `npm` echoue avec
une erreur de journalisation peu explicite. Les scripts forcent donc
`HOME=/var/cache/opstrack-npm` et `npm_config_cache=/var/cache/opstrack-npm`.
