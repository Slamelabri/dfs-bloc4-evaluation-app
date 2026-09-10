#!/usr/bin/env bash
#
# Sonde de supervision OpsTrack, executee par cron toutes les 5 minutes.
# Journalise dans /var/log/opstrack/healthcheck.log et ecrit une alerte
# dans /var/log/opstrack/alerts.log en cas de probleme.
set -u

BASE_URL="${OPSTRACK_URL:-https://eval-dfs-p-tpl-20265-02.it-students.fr}"
LOG_DIR=/var/log/opstrack
LOG="$LOG_DIR/healthcheck.log"
ALERTES="$LOG_DIR/alerts.log"
SEUIL_DISQUE=85

mkdir -p "$LOG_DIR"
horodatage() { date -Iseconds; }

journaliser() { echo "$(horodatage) [$1] $2" >> "$LOG"; }

alerter() {
    echo "$(horodatage) [ALERTE] $1" | tee -a "$ALERTES" >> "$LOG"
    logger -t opstrack-healthcheck -p daemon.err "$1"
}

# --- Services systeme ---
for service in apache2 mysql mongod redis-server opstrack-dispatch-dashboard; do
    etat=$(systemctl is-active "$service" 2>/dev/null)

    case "$etat" in
        active)
            journaliser OK "service $service actif"
            ;;
        *)
            alerter "service $service dans l'etat '$etat'"
            systemctl restart "$service" 2>/dev/null && \
                journaliser INFO "service $service redemarre automatiquement"
            ;;
    esac
done

# --- Disponibilite applicative ---
code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 15 "$BASE_URL/api/health")

case "$code" in
    200)
        journaliser OK "api/health repond 200"
        ;;
    000)
        alerter "application injoignable ($BASE_URL)"
        ;;
    *)
        alerter "api/health repond $code"
        ;;
esac

# --- Certificat TLS : alerte a moins de 21 jours ---
CERT=/etc/letsencrypt/live/eval-dfs-p-tpl-20265-02.it-students.fr/fullchain.pem

if [ -f "$CERT" ]; then
    fin=$(date -d "$(openssl x509 -enddate -noout -in "$CERT" | cut -d= -f2)" +%s)
    jours=$(( (fin - $(date +%s)) / 86400 ))

    if [ "$jours" -lt 21 ]; then
        alerter "certificat TLS expire dans $jours jours"
    else
        journaliser OK "certificat TLS valide encore $jours jours"
    fi
else
    alerter "certificat TLS introuvable"
fi

# --- Espace disque ---
usage=$(df / | awk 'NR==2 {gsub("%","",$5); print $5}')

if [ "$usage" -ge "$SEUIL_DISQUE" ]; then
    alerter "espace disque a ${usage}% (seuil ${SEUIL_DISQUE}%)"
else
    journaliser OK "espace disque a ${usage}%"
fi

# --- Erreurs applicatives recentes ---
LARAVEL_LOG=/var/www/opstrack/storage/logs/laravel.log

if [ -f "$LARAVEL_LOG" ]; then
    erreurs=$(grep -c "\.ERROR\|\.CRITICAL" "$LARAVEL_LOG" 2>/dev/null || echo 0)

    if [ "$erreurs" -gt 0 ]; then
        journaliser WARN "$erreurs erreurs dans laravel.log"
    fi
fi

# --- Authentifications webhook rejetees (signal d'appels suspects) ---
REJETS=$(grep -c "auth.rejected" "$LARAVEL_LOG" 2>/dev/null || echo 0)

if [ "$REJETS" -gt 20 ]; then
    alerter "$REJETS rejets d'authentification webhook : verifier l'origine des appels"
fi

exit 0
