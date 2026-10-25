#!/usr/bin/env bash
#
# Deploy ERP ke hosting yang foldernya bukan checkout git (Hostinger shared).
#
# API Hostinger tidak menyediakan SSH, jadi skrip ini dijalankan lewat cron
# sekali jalan. Kode diambil dari tarball GitHub pada commit tertentu lalu
# disalin ke folder situs. Yang TIDAK pernah disentuh: .env, storage/
# (termasuk gambar upload), public/storage, vendor/, bootstrap/cache/, dan
# file di server yang tidak ada di repo (tidak ada penghapusan).
#
# Pemakaian:
#   deploy-hostinger.sh <folder_situs> <commit> plan    # hanya memeriksa
#   deploy-hostinger.sh <folder_situs> <commit> apply   # backup, sinkron, migrate
#
# Hasil kerja dan backup ada di ~/erp-deploy (di luar public_html).

set -euo pipefail

SITE=${1:?folder situs}
SHA=${2:?commit}
ACTION=${3:?plan atau apply}
REPO=${REPO:-kraftybyte/erp}

WORK="$HOME/erp-deploy"
STAGE="$WORK/staging-$SHA"
BACKUP="$WORK/backup-$SHA"

EXCLUDES=(
    --exclude=.git
    --exclude=.env
    --exclude=/storage/
    --exclude=/public/storage
    --exclude=/public/hot
    --exclude=/public/Archive.zip
    --exclude=/vendor/
    --exclude=/node_modules/
    --exclude=/bootstrap/cache/
)

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

fetch() {
    [ -d "$STAGE" ] && return
    mkdir -p "$WORK"
    local tmp
    tmp=$(mktemp -d "$WORK/tmp.XXXXXX")
    curl -fsSL "https://codeload.github.com/$REPO/tar.gz/$SHA" | tar -xz -C "$tmp" --strip-components=1
    mv "$tmp" "$STAGE"
}

changed_files() {
    rsync -rlcn --out-format='%n' "${EXCLUDES[@]}" "$STAGE/" "$SITE/" | grep -v '/$' || true
}

# Migration (termasuk settings migration) di commit tujuan yang belum
# tercatat di tabel migrations database server.
pending_migrations() {
    local ran
    ran=$(cd "$SITE" && "$PHP" artisan tinker --execute='echo implode(PHP_EOL, DB::table("migrations")->pluck("migration")->all());' 2>/dev/null)
    (cd "$STAGE" && ls database/migrations/*.php database/settings/*.php 2>/dev/null) \
        | xargs -n1 basename | sed 's/\.php$//' | sort \
        | grep -vxF -f <(printf '%s\n' "$ran") || true
}

env_value() {
    grep -E "^$1=" "$SITE/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

plan() {
    fetch
    echo "== PHP: $("$PHP" -r 'echo PHP_VERSION;') ($PHP)"
    echo "== Laravel: $(cd "$SITE" && "$PHP" artisan --version 2>&1 | head -1)"
    echo "== APP_URL: $(env_value APP_URL) | APP_ENV: $(env_value APP_ENV)"
    echo "== Alat: rsync=$(command -v rsync || echo TIDAK ADA) mysqldump=$(command -v mysqldump || echo TIDAK ADA)"
    if [ -L "$SITE/public/storage" ]; then
        echo "== public/storage: symlink -> $(readlink "$SITE/public/storage")"
    else
        echo "== public/storage: $( [ -d "$SITE/public/storage" ] && echo folder biasa || echo tidak ada)"
    fi

    local changed
    changed=$(changed_files)
    echo
    echo "== File yang akan diubah/ditambah: $(printf '%s\n' "$changed" | grep -c . || true)"
    echo "   (baru: $(printf '%s\n' "$changed" | while read -r f; do [ -n "$f" ] && [ ! -e "$SITE/$f" ] && echo "$f"; done | grep -c . || true))"

    echo
    echo "== File di server yang tidak ada di repo (dibiarkan, tidak dihapus):"
    comm -23 \
        <(cd "$SITE" && find app config database routes resources lang -type f 2>/dev/null | sort) \
        <(cd "$STAGE" && find app config database routes resources lang -type f 2>/dev/null | sort) \
        | sed 's/^/   /'

    echo
    echo "== Migration yang akan dijalankan:"
    pending_migrations | sed 's/^/   /'
}

apply() {
    fetch
    if [ -f "$WORK/applied-$SHA" ]; then
        echo "Commit $SHA sudah diterapkan sebelumnya."
        return
    fi

    # Cron bisa memanggil skrip ini lagi semenit kemudian. Percobaan kedua
    # akan menimpa backup dengan kondisi yang sudah setengah tersinkron, jadi
    # apply hanya boleh dicoba sekali per commit.
    if [ -f "$WORK/attempted-$SHA" ]; then
        echo "Apply untuk $SHA sudah pernah dicoba dan belum selesai. Periksa $WORK sebelum mencoba lagi." >&2
        exit 1
    fi
    mkdir -p "$WORK"
    touch "$WORK/attempted-$SHA"

    mkdir -p "$BACKUP"

    echo "== 1/6 Backup database"
    local port
    port=$(env_value DB_PORT)
    MYSQL_PWD=$(env_value DB_PASSWORD) mysqldump --single-transaction --quick --no-tablespaces \
        -h "$(env_value DB_HOST)" -P "${port:-3306}" -u "$(env_value DB_USERNAME)" "$(env_value DB_DATABASE)" \
        | gzip > "$BACKUP/database.sql.gz"
    echo "   $(du -h "$BACKUP/database.sql.gz" | cut -f1) -> $BACKUP/database.sql.gz"

    echo "== 2/6 Backup file yang akan ditimpa"
    changed_files > "$BACKUP/changed-files.txt"
    (cd "$SITE" && while read -r f; do [ -e "$f" ] && echo "$f"; done < "$BACKUP/changed-files.txt") > "$BACKUP/overwritten-files.txt"
    (cd "$SITE" && while read -r f; do [ -e "$f" ] || echo "$f"; done < "$BACKUP/changed-files.txt") > "$BACKUP/new-files.txt"
    (cd "$SITE" && tar -czf "$BACKUP/code-before.tar.gz" -T "$BACKUP/overwritten-files.txt")
    echo "   $(wc -l < "$BACKUP/overwritten-files.txt") file ditimpa, $(wc -l < "$BACKUP/new-files.txt") file baru"

    echo "== 3/6 Maintenance mode"
    (cd "$SITE" && "$PHP" artisan down --retry=60)

    # Bila langkah berikutnya gagal, situs dibiarkan dalam maintenance supaya
    # tidak tampil rusak; pulihkan dengan backup di $BACKUP.
    echo "== 4/6 Sinkron kode"
    rsync -rlc "${EXCLUDES[@]}" "$STAGE/" "$SITE/"

    echo "== 5/6 Autoload & migrate"
    if [ -f "$SITE/composer.phar" ]; then
        (cd "$SITE" && "$PHP" composer.phar dump-autoload -o --no-interaction 2>&1 | tail -3)
    else
        (cd "$SITE" && composer dump-autoload -o --no-interaction 2>&1 | tail -3)
    fi
    (cd "$SITE" && "$PHP" artisan migrate --force --no-interaction)

    echo "== 6/6 Bersihkan cache & nyalakan situs"
    (cd "$SITE" && "$PHP" artisan optimize:clear)
    (cd "$SITE" && "$PHP" artisan filament:optimize-clear) || true
    (cd "$SITE" && "$PHP" artisan settings:clear-discovered) || true
    (cd "$SITE" && "$PHP" artisan up)

    touch "$WORK/applied-$SHA"
    echo "SELESAI: commit $SHA aktif. Backup: $BACKUP"
}

PHP=$(pick_php)

case "$ACTION" in
    plan)  plan ;;
    apply) apply ;;
    *)     echo "Aksi tidak dikenal: $ACTION (plan | apply)" >&2; exit 1 ;;
esac
