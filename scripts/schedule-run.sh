#!/usr/bin/env bash
# Jadwal Laravel (app/Console/Kernel.php): ringkasan pagi, pengingat deadline
# dan follow-up lead, financial:analyze. Dijalankan cron Hostinger setiap menit:
#
#   bash ~/domains/signcare.id/public_html/erp/scripts/schedule-run.sh
#
# Lewat skrip ini karena cron Hostinger yang berisi "php artisan" langsung tidak
# pernah jalan. PHP dipilih seperti di deploy-hostinger.sh. Log hanya mencatat
# menit yang benar-benar menjalankan perintah: storage/logs/schedule.log.
set -u
cd "$(dirname "$0")/.." || exit 1

LOG=storage/logs/schedule.log

for php in ${PHP:-} php /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /usr/local/bin/php; do
    command -v "$php" >/dev/null 2>&1 || continue
    "$php" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' 2>/dev/null || continue

    output=$("$php" artisan schedule:run 2>&1)
    status=$?
    if ! grep -q 'No scheduled commands are ready' <<<"$output"; then
        printf '%s\n%s\n' "$(date '+%F %T')" "$output" >> "$LOG"
    fi
    exit $status
done

echo "$(date '+%F %T') PHP >= 8.3 tidak ditemukan" >> "$LOG"
exit 1
