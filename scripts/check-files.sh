#!/usr/bin/env bash
#
# Pemakaian inode (jumlah file + folder) di akun hosting, hanya membaca:
# total, per folder, dan tempat yang biasa menumpuk (backup deploy, cache,
# sesi, log, node_modules, cache composer/npm).
#
# Pemakaian (lewat cron sekali jalan, seperti deploy-hostinger.sh):
#   check-files.sh [folder_awal, default $HOME] [folder_situs_erp]

set -uo pipefail

ROOT=${1:-$HOME}
SITE=${2:-$HOME/domains/signcare.id/public_html/erp}

count() {
    find "$1" -xdev 2>/dev/null | wc -l | tr -d ' '
}

size() {
    du -sh "$1" 2>/dev/null | cut -f1
}

cd "$ROOT" || exit 1

echo "== Waktu server: $(date '+%F %T %Z')"
echo "== Total inode di $ROOT: $(count .) · ukuran $(size .)"

echo
echo "== Inode per folder (kedalaman 3, terbanyak)"
find . -xdev -mindepth 1 -printf '%h/%f\n' 2>/dev/null | cut -d/ -f1-4 | sort | uniq -c | sort -rn | head -25

echo
echo "== Inode per folder (kedalaman 6, terbanyak)"
find . -xdev -mindepth 1 -printf '%h/%f\n' 2>/dev/null | cut -d/ -f1-7 | sort | uniq -c | sort -rn | head -30

echo
echo "== Backup deploy (~/erp-deploy)"
if [ -d "$HOME/erp-deploy" ]; then
    echo "$(find "$HOME/erp-deploy" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' ') backup · $(count "$HOME/erp-deploy") inode · $(size "$HOME/erp-deploy")"
    for backup in $(ls -1dt "$HOME"/erp-deploy/backup-* 2>/dev/null | head -40); do
        printf '   %-28s %8s inode  %6s  %s\n' "$(basename "$backup")" "$(count "$backup")" "$(size "$backup")" "$(date -r "$backup" '+%F')"
    done
else
    echo "Tidak ada."
fi

echo
echo "== Situs ERP ($SITE)"
for dir in vendor node_modules storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public storage/app/livewire-tmp bootstrap/cache public/build .git; do
    [ -e "$SITE/$dir" ] && printf '   %-30s %8s inode  %6s\n' "$dir" "$(count "$SITE/$dir")" "$(size "$SITE/$dir")"
done

echo
echo "== node_modules di mana pun"
find . -xdev -type d -name node_modules -prune 2>/dev/null | while read -r dir; do
    printf '   %8s inode  %6s  %s\n' "$(count "$dir")" "$(size "$dir")" "$dir"
done | sort -rn | head -15

echo
echo "== Cache alat (composer, npm, dll.)"
for dir in .cache .npm .composer .config/composer .local/share/composer .wp-cli .trash .Trash tmp; do
    [ -e "$ROOT/$dir" ] && printf '   %-28s %8s inode  %6s\n' "$dir" "$(count "$ROOT/$dir")" "$(size "$ROOT/$dir")"
done

echo
echo "== Log sisa deploy/cek di $ROOT"
echo "   $(ls -1 "$ROOT"/*.log 2>/dev/null | wc -l | tr -d ' ') file *.log"
