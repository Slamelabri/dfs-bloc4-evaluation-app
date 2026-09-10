#!/usr/bin/env bash
#
# Smoke test post-deploiement.
# Usage : ./deploy/smoke.sh <base_url> <api_token>
#   ./deploy/smoke.sh https://eval-dfs-p-tpl-20265-02.it-students.fr "$OPSTRACK_API_TOKEN"
#
# Sortie 0 = tous les controles passent, 1 = au moins un echec (declenche le rollback).
set -u

BASE_URL="${1:-http://localhost:8000}"
API_TOKEN="${2:-}"

OK=0
KO=0

code_http() { curl -s -o /dev/null -w "%{http_code}" --max-time 15 "$@"; }

verifier() {
    local nom="$1" obtenu="$2" attendu="$3"

    if [ "$obtenu" = "$attendu" ]; then
        echo "  OK   $nom (HTTP $obtenu)"
        OK=$((OK + 1))
    else
        echo "  KO   $nom (attendu $attendu, obtenu $obtenu)"
        KO=$((KO + 1))
    fi
}

echo "== Smoke test : $BASE_URL =="
echo

verifier "Tableau de bord /"        "$(code_http "$BASE_URL/")"            "200"
verifier "Healthcheck /up"          "$(code_http "$BASE_URL/up")"          "200"
verifier "API /api/health"          "$(code_http "$BASE_URL/api/health")"  "200"
verifier "API sans token"           "$(code_http "$BASE_URL/api/v1/tickets")" "401"
verifier "API mauvais token"        "$(code_http -H 'Authorization: Bearer token-bidon' "$BASE_URL/api/v1/tickets")" "401"
verifier "Webhook sans auth"        "$(code_http -X POST "$BASE_URL/hooks.php")" "401"
verifier "Microservice Next.js"     "$(code_http "$BASE_URL/dispatch-dashboard")" "200"

# Fichiers sensibles : ne doivent jamais etre servis
verifier "/.env non expose"         "$(code_http "$BASE_URL/.env")"        "403"
verifier "/storage/logs non expose" "$(code_http "$BASE_URL/storage/logs/laravel.log")" "404"

if [ -n "$API_TOKEN" ]; then
    verifier "API bon token" \
        "$(code_http -H "Authorization: Bearer $API_TOKEN" "$BASE_URL/api/v1/tickets")" "200"

    CORPS=$(curl -s --max-time 15 -H "Authorization: Bearer $API_TOKEN" "$BASE_URL/api/v1/tickets")

    case "$CORPS" in
        *'"data"'*)
            echo "  OK   Contrat JSON : cle \"data\" presente"
            OK=$((OK + 1))
            ;;
        *)
            echo "  KO   Contrat JSON : cle \"data\" absente"
            KO=$((KO + 1))
            ;;
    esac
else
    echo "  --   Tests avec token ignores (aucun token fourni)"
fi

# HTTPS : uniquement si l'URL est en https
case "$BASE_URL" in
    https://*)
        if curl -s --max-time 15 -o /dev/null "$BASE_URL/"; then
            echo "  OK   Certificat TLS valide"
            OK=$((OK + 1))
        else
            echo "  KO   Certificat TLS invalide ou expire"
            KO=$((KO + 1))
        fi

        REDIR=$(code_http "http://${BASE_URL#https://}/")
        case "$REDIR" in
            30*)
                echo "  OK   Redirection HTTP -> HTTPS ($REDIR)"
                OK=$((OK + 1))
                ;;
            *)
                echo "  KO   Pas de redirection HTTP -> HTTPS (obtenu $REDIR)"
                KO=$((KO + 1))
                ;;
        esac
        ;;
    *)
        echo "  --   Controles TLS ignores (URL en http)"
        ;;
esac

echo
echo "== Resultat : $OK OK / $KO KO =="

if [ "$KO" -gt 0 ]; then
    exit 1
fi

exit 0
