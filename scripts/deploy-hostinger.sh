#!/usr/bin/env bash
#
# Deploy ERP ke hosting yang foldernya bukan checkout git (Hostinger shared).
#
# API Hostinger tidak menyediakan SSH, jadi skrip ini dijalankan lewat cron
# sekali jalan. Kode diambil dari tarball GitHub pada commit tertentu lalu
# disalin ke folder situs. Yang TIDAK pernah disentuh: .env, storage/
# (termasuk gambar upload), public/storage, vendor/, bootstrap/cache/, dan
# file di server yang tidak ada di repo. Satu-satunya penghapusan: file kode
# yang tercantum di scripts/deploy-remove.txt (di-backup dulu).
#
# Pemakaian:
#   deploy-hostinger.sh <folder_situs> <commit> plan         # hanya memeriksa
#   deploy-hostinger.sh <folder_situs> <commit> apply        # backup, sinkron, migrate
#   deploy-hostinger.sh <folder_situs> <commit> rollback     # kembalikan kode
#   deploy-hostinger.sh <folder_situs> <commit> rollback-db  # kembalikan kode + database
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
    --exclude=/.claude/
    --exclude=.env
    --exclude=/storage/
    --exclude=/public/storage
    --exclude=/public/hot
    --exclude=/public/Archive.zip
    --exclude=/vendor/
    --exclude=/node_modules/
    --exclude=/bootstrap/cache/
)

# PHP web (LiteSpeed lsphp) menyimpan file PHP yang sudah diterjemahkan di
# OPcache. File baru langsung terbaca, tetapi file lama yang diubah bisa tetap
# memakai versi lama sampai prosesnya diganti — deploy tampak "tidak berubah".
# artisan optimize:clear tidak menjangkaunya (CLI punya OPcache sendiri), jadi
# proses lsphp akun ini dihentikan; LiteSpeed membuat yang baru di request
# berikutnya. Berlaku untuk semua situs di akun hosting ini (sekejap).
restart_php() {
    pkill -u "$(id -un)" -f lsphp && echo "   PHP web di-restart (OPcache bersih)" || echo "   Tidak ada proses PHP web yang berjalan"
}

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

# File di scripts/deploy-remove.txt yang masih ada di server. Hanya file kode
# di app/, config/, resources/, routes/ yang sudah tidak ada di repo, jadi
# .env, storage/ dan vendor/ tidak mungkin terhapus lewat daftar ini.
removed_files() {
    local list="$STAGE/scripts/deploy-remove.txt" f
    [ -f "$list" ] || return 0
    while read -r f || [ -n "$f" ]; do
        case "$f" in ''|'#'*|/*|*..*) continue ;; esac
        case "$f" in app/*|config/*|resources/*|routes/*) ;; *) continue ;; esac
        if [ -e "$STAGE/$f" ]; then continue; fi
        if [ -f "$SITE/$f" ]; then echo "$f"; fi
    done < "$list"
}

# Migration yang akan dijalankan `artisan migrate` setelah sinkron: semua
# file di folder migration (dari repo maupun yang hanya ada di server) yang
# belum tercatat di tabel migrations.
pending_migrations() {
    local ran
    ran=$(cd "$SITE" && "$PHP" artisan tinker --execute='echo implode(PHP_EOL, DB::table("migrations")->pluck("migration")->all());' 2>/dev/null)

    if [ -z "$ran" ]; then
        echo "GAGAL membaca tabel migrations di database server" >&2
        exit 1
    fi

    # File sementara, bukan <( ... ): hosting CloudLinux tidak menyediakan /dev/fd.
    printf '%s\n' "$ran" > "$WORK/ran-migrations.txt"

    {
        (cd "$STAGE" && ls database/migrations/*.php database/settings/*.php 2>/dev/null)
        (cd "$SITE" && ls database/migrations/*.php database/settings/*.php 2>/dev/null)
    } | xargs -n1 basename | sed 's/\.php$//' | sort -u \
        | grep -vxF -f "$WORK/ran-migrations.txt" || true
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

    local changed total=0 new=0 f
    changed=$(changed_files)
    while read -r f; do
        [ -n "$f" ] || continue
        total=$((total + 1))
        if [ ! -e "$SITE/$f" ]; then new=$((new + 1)); fi
    done <<< "$changed"
    echo
    echo "== File yang akan diubah/ditambah: $total (baru: $new)"

    echo
    echo "== File yang dihapus dari server (scripts/deploy-remove.txt):"
    removed_files | sed 's/^/   /'

    echo
    echo "== File di server yang tidak ada di repo (dibiarkan, tidak dihapus):"
    (cd "$SITE" && find app config database routes resources lang -type f 2>/dev/null | sort) > "$WORK/server-files.txt"
    (cd "$STAGE" && find app config database routes resources lang -type f 2>/dev/null | sort) > "$WORK/repo-files.txt"
    comm -23 "$WORK/server-files.txt" "$WORK/repo-files.txt" | sed 's/^/   /'

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
    : > "$BACKUP/overwritten-files.txt"
    : > "$BACKUP/new-files.txt"
    while read -r f; do
        if [ -e "$SITE/$f" ]; then
            echo "$f" >> "$BACKUP/overwritten-files.txt"
        else
            echo "$f" >> "$BACKUP/new-files.txt"
        fi
    done < "$BACKUP/changed-files.txt"
    (cd "$SITE" && tar -czf "$BACKUP/code-before.tar.gz" -T "$BACKUP/overwritten-files.txt")
    removed_files > "$BACKUP/removed-files.txt"
    if [ -s "$BACKUP/removed-files.txt" ]; then
        (cd "$SITE" && tar -czf "$BACKUP/removed-before.tar.gz" -T "$BACKUP/removed-files.txt")
    fi
    echo "   $(wc -l < "$BACKUP/overwritten-files.txt") file ditimpa, $(wc -l < "$BACKUP/new-files.txt") file baru, $(wc -l < "$BACKUP/removed-files.txt") file dihapus"

    echo "== 3/6 Maintenance mode"
    (cd "$SITE" && "$PHP" artisan down --retry=60)

    # Bila langkah berikutnya gagal, situs dibiarkan dalam maintenance supaya
    # tidak tampil rusak; pulihkan dengan backup di $BACKUP.
    echo "== 4/6 Sinkron kode"
    rsync -rlc "${EXCLUDES[@]}" "$STAGE/" "$SITE/"
    # Sebelum dump-autoload, supaya classmap tidak lagi memuat kelas yang dihapus.
    while read -r f; do
        rm -f "$SITE/$f"
        (cd "$SITE" && rmdir -p "$(dirname "$f")" 2>/dev/null) || true
    done < "$BACKUP/removed-files.txt"

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
    restart_php

    touch "$WORK/applied-$SHA"
    echo "SELESAI: commit $SHA aktif. Backup: $BACKUP"
}

# Kembalikan kode (dan database, bila diminta) ke kondisi sebelum apply.
rollback() {
    local with_db=$1 f

    if [ ! -f "$BACKUP/code-before.tar.gz" ]; then
        echo "Tidak ada backup untuk $SHA di $BACKUP" >&2
        exit 1
    fi

    (cd "$SITE" && "$PHP" artisan down --retry=60) || true

    echo "== Kembalikan file yang ditimpa"
    (cd "$SITE" && tar -xzf "$BACKUP/code-before.tar.gz")

    if [ -f "$BACKUP/removed-before.tar.gz" ]; then
        echo "== Kembalikan file yang dihapus apply"
        (cd "$SITE" && tar -xzf "$BACKUP/removed-before.tar.gz")
    fi

    echo "== Hapus file yang ditambahkan apply"
    while read -r f; do
        # Hanya path relatif hasil rsync; jangan pernah keluar dari folder situs.
        case "$f" in ''|/*|*..*) continue ;; esac
        rm -f "$SITE/$f"
        # Folder yang dibuat apply ikut dihapus selama kosong; berhenti di
        # folder pertama yang masih berisi.
        (cd "$SITE" && rmdir -p "$(dirname "$f")" 2>/dev/null) || true
    done < "$BACKUP/new-files.txt"

    if [ "$with_db" = 1 ]; then
        echo "== Pulihkan database"
        local port
        port=$(env_value DB_PORT)
        gunzip -c "$BACKUP/database.sql.gz" | MYSQL_PWD=$(env_value DB_PASSWORD) mysql \
            -h "$(env_value DB_HOST)" -P "${port:-3306}" -u "$(env_value DB_USERNAME)" "$(env_value DB_DATABASE)"
    fi

    if [ -f "$SITE/composer.phar" ]; then
        (cd "$SITE" && "$PHP" composer.phar dump-autoload -o --no-interaction 2>&1 | tail -1)
    else
        (cd "$SITE" && composer dump-autoload -o --no-interaction 2>&1 | tail -1)
    fi
    (cd "$SITE" && "$PHP" artisan optimize:clear)
    (cd "$SITE" && "$PHP" artisan up)
    restart_php

    rm -f "$WORK/attempted-$SHA" "$WORK/applied-$SHA"
    echo "ROLLBACK SELESAI"
}

PHP=$(pick_php)

case "$ACTION" in
    plan)        plan ;;
    apply)       apply ;;
    rollback)    rollback 0 ;;
    rollback-db) rollback 1 ;;
    *)           echo "Aksi tidak dikenal: $ACTION (plan | apply | rollback | rollback-db)" >&2; exit 1 ;;
esac
