#!/usr/bin/env bash
#
# Promotion qualification -> production.
#
#   1. controles prealables : la suite de tests est jouee sur la QUALIFICATION
#   2. deploiement de la meme reference git sur la PRODUCTION
#   3. smoke test post-deploiement
#   4. rollback automatique sur la revision precedente si le smoke test echoue
#
# Usage :
#   SSH_KEY=~/ubuntu.pem OPSTRACK_API_TOKEN=xxx ./deploy/deploy.sh main
#
# Variables :
#   SSH_KEY             chemin de la cle privee SSH (obligatoire)
#   OPSTRACK_API_TOKEN  token utilise par le smoke test (optionnel mais recommande)
#   SKIP_TESTS=1        saute l'etape 1 (a n'utiliser qu'en cas d'urgence tracee)
set -euo pipefail

GIT_REF="${1:-main}"

SSH_USER="${SSH_USER:-ubuntu}"
SSH_KEY="${SSH_KEY:?SSH_KEY est obligatoire}"
QUALIF_HOST="${QUALIF_HOST:-eval-dfs-q-tpl-20265-02.it-students.fr}"
PROD_HOST="${PROD_HOST:-eval-dfs-p-tpl-20265-02.it-students.fr}"
PROD_URL="${PROD_URL:-https://eval-dfs-p-tpl-20265-02.it-students.fr}"
APP_DIR="${APP_DIR:-/var/www/opstrack}"

# Compte sous lequel les commandes applicatives sont executees sur chaque machine.
# La qualification appartient a "ubuntu", la production a "www-data" : le compte
# doit donc etre celui qui possede reellement l'arborescence, sinon git refuse
# d'ecrire dans .git.
QUALIF_RUN_AS="${QUALIF_RUN_AS:-}"
PROD_RUN_AS="${PROD_RUN_AS:-www-data}"

[ -n "$QUALIF_RUN_AS" ] && Q_AS="sudo -u $QUALIF_RUN_AS" || Q_AS=""
[ -n "$PROD_RUN_AS" ]   && P_AS="sudo -u $PROD_RUN_AS"   || P_AS=""

SSH_OPTS="-i $SSH_KEY -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15"

titre() { echo; echo "=============================================="; echo "  $1"; echo "=============================================="; }

sur_qualif() { ssh $SSH_OPTS "$SSH_USER@$QUALIF_HOST" "$@"; }
sur_prod()   { ssh $SSH_OPTS "$SSH_USER@$PROD_HOST"   "$@"; }

# ---------------------------------------------------------------------------
# Etape 1 : controles prealables sur la qualification
# ---------------------------------------------------------------------------
titre "1/4  Controles prealables (qualification)"

if [ "${SKIP_TESTS:-0}" = "1" ]; then
    echo "  ATTENTION : tests sautes sur demande explicite (SKIP_TESTS=1)."
else
    sur_qualif "set -e
        cd $APP_DIR
        $Q_AS git fetch --all --quiet
        # Etat git propre : les outils de build (npm, next) modifient des fichiers
        # suivis. Les fichiers ignores (.env, vendor, node_modules) sont preserves.
        $Q_AS git reset --hard --quiet
        $Q_AS git clean -fdq -e node_modules -e .next -e vendor -e ".env*"
        $Q_AS git checkout --quiet '$GIT_REF'
        $Q_AS git pull --quiet --ff-only origin '$GIT_REF' || true
        $Q_AS env COMPOSER_HOME=/tmp/composer composer install --no-interaction --quiet --ignore-platform-req=ext-mongodb
        $Q_AS php artisan config:clear --quiet
        $Q_AS php artisan test"
    echo "  Tests verts sur la qualification."
fi

# ---------------------------------------------------------------------------
# Etape 2 : point de rollback
# ---------------------------------------------------------------------------
titre "2/4  Point de rollback (production)"

REVISION_PRECEDENTE=$(sur_prod "cd $APP_DIR && git rev-parse HEAD" 2>/dev/null || echo "")

if [ -z "$REVISION_PRECEDENTE" ]; then
    echo "  Aucune revision precedente (premier deploiement) : rollback indisponible."
else
    echo "  Revision actuelle en production : $REVISION_PRECEDENTE"
fi

# ---------------------------------------------------------------------------
# Etape 3 : mise a jour de la production
# ---------------------------------------------------------------------------
titre "3/4  Deploiement en production ($GIT_REF)"

deployer_revision() {
    local ref="$1"

    sur_prod "set -e
        cd $APP_DIR
        $P_AS php artisan down --render='errors::503' --retry=30 || true

        $P_AS git fetch --all --quiet
        $P_AS git reset --hard --quiet
        $P_AS git clean -fdq -e node_modules -e .next -e vendor -e ".env*"
        $P_AS git checkout --quiet '$ref'
        $P_AS git pull --quiet --ff-only origin '$ref' 2>/dev/null || true

        $P_AS env COMPOSER_HOME=/tmp/composer composer install --no-dev --optimize-autoloader --no-interaction --quiet --ignore-platform-req=ext-mongodb
        $P_AS php artisan migrate --force --no-interaction

        # Microservice Next.js : build seulement si ses sources ont bouge
        cd $APP_DIR/microservices/dispatch-dashboard
        $P_AS env HOME=/var/cache/opstrack-npm npm_config_cache=/var/cache/opstrack-npm npm ci --silent --no-audit --no-fund
        $P_AS env HOME=/var/cache/opstrack-npm npm_config_cache=/var/cache/opstrack-npm npm run build --silent
        cd $APP_DIR

        $P_AS php artisan config:cache --quiet
        $P_AS php artisan route:cache --quiet
        $P_AS php artisan view:cache  --quiet

        sudo chmod -R 775 storage bootstrap/cache
        sudo systemctl restart opstrack-dispatch-dashboard
        sudo systemctl reload apache2

        $P_AS php artisan up"
}

deployer_revision "$GIT_REF"
REVISION_DEPLOYEE=$(sur_prod "cd $APP_DIR && git rev-parse HEAD")
echo "  Revision deployee : $REVISION_DEPLOYEE"

# ---------------------------------------------------------------------------
# Etape 4 : verification post-deploiement
# ---------------------------------------------------------------------------
titre "4/4  Smoke test post-deploiement"

if "$(dirname "$0")/smoke.sh" "$PROD_URL" "${OPSTRACK_API_TOKEN:-}"; then
    echo
    echo "DEPLOIEMENT REUSSI  ($GIT_REF -> $REVISION_DEPLOYEE)"
    exit 0
fi

# ---------------------------------------------------------------------------
# Rollback
# ---------------------------------------------------------------------------
titre "ECHEC DU SMOKE TEST - ROLLBACK"

if [ -z "$REVISION_PRECEDENTE" ]; then
    echo "Aucune revision precedente connue : rollback impossible."
    echo "La production est en maintenance, intervention manuelle requise."
    exit 2
fi

echo "Retour sur $REVISION_PRECEDENTE ..."
deployer_revision "$REVISION_PRECEDENTE"

if "$(dirname "$0")/smoke.sh" "$PROD_URL" "${OPSTRACK_API_TOKEN:-}"; then
    echo
    echo "ROLLBACK REUSSI : production restauree sur $REVISION_PRECEDENTE"
    exit 1
fi

echo
echo "ROLLBACK EN ECHEC : intervention manuelle requise immediatement."
exit 2
