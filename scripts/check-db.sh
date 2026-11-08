#!/usr/bin/env bash
#
# Cek kesehatan data live, hanya membaca (scripts/check-db.php): migrasi,
# Gemini, status advance, quotation, penomoran proforma, pelunasan, dan
# pemakaian bot. Hanya angka, status, dan nomor dokumen. Tidak mengubah file
# situs, jadi bisa dijalankan tanpa deploy.
#
# Pemakaian (lewat cron sekali jalan, seperti deploy-hostinger.sh):
#   check-db.sh <folder_situs>
# CHECK_DB_PHP=<path> memakai check-db.php lokal alih-alih unduhan dari GitHub.

set -uo pipefail

SITE=${1:?folder situs}

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

PHP=$(pick_php)
SCRIPT=${CHECK_DB_PHP:-}

if [ -z "$SCRIPT" ]; then
    SCRIPT=$(mktemp)
    trap 'rm -f "$SCRIPT"' EXIT
    curl -fsSLo "$SCRIPT" https://raw.githubusercontent.com/kraftybyte/erp/main/scripts/check-db.php || { echo "Gagal mengunduh check-db.php"; exit 1; }
fi

echo "== Waktu server: $(date '+%F %T %Z')"
"$PHP" "$SCRIPT" "$SITE" 2>&1
