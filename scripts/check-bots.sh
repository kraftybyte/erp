#!/usr/bin/env bash
#
# Cek kesehatan bot Telegram di server, hanya membaca: status webhook bot
# pengajuan dan bot keuangan (getMe/getWebhookInfo), lalu error terbaru di
# laravel.log. Token bot yang ikut tercatat di log disamarkan.
#
# Pemakaian (lewat cron sekali jalan, seperti deploy-hostinger.sh):
#   check-bots.sh <folder_situs> [jumlah_hari, default 3]

set -uo pipefail

SITE=${1:?folder situs}
DAYS=${2:-3}

pick_php() {
    local candidate
    for candidate in ${PHP:-} php /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /usr/local/bin/php; do
        command -v "$candidate" >/dev/null 2>&1 || continue
        if "$candidate" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' 2>/dev/null; then
            echo "$candidate"
            return
        fi
    done
    echo "PHP >= 8.3 tidak ditemukan" >&2
    exit 1
}

mask() {
    sed -E 's#bot[0-9]+:[A-Za-z0-9_-]+#bot<TOKEN>#g'
}

PHP=$(pick_php)
LOG="$SITE/storage/logs/laravel.log"
DATES=$(for i in $(seq 0 $((DAYS - 1))); do date -d "-$i day" +%F; done | paste -sd'|')

cd "$SITE" || exit 1

echo "== Bot pengajuan"
"$PHP" artisan telegram:webhook info 2>&1 | mask

echo
echo "== Bot keuangan (overview)"
"$PHP" artisan telegram:webhook info --finance 2>&1 | mask

echo
echo "== Error $DAYS hari terakhir yang menyebut bot/Telegram"
grep -E "^\[($DATES)" "$LOG" | grep -iE "telegram|financebot|finance-webhook|webhook" | cut -c1-400 | mask | tail -40

echo
echo "== Semua error $DAYS hari terakhir (judul saja, terbaru di bawah)"
grep -E "^\[($DATES)[^]]*\] [a-z]+\.(ERROR|CRITICAL|ALERT|EMERGENCY)" "$LOG" | cut -c1-250 | mask | tail -30
