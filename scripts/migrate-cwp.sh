#!/usr/bin/env bash
# Pindahkan ERP ke website lain di CWP (Control Web Panel), satu perintah.
#
# Jalankan di server CWP lewat SSH (sebaiknya sebagai root):
#
#   curl -fsSL https://raw.githubusercontent.com/kraftybyte/erp/main/scripts/migrate-cwp.sh -o migrate-cwp.sh && bash migrate-cwp.sh
#
# Skrip menanyakan domain baru, folder situs, dan akses SSH ke server lama,
# lalu:
#   1. mengunduh kode terbaru dari GitHub ke folder situs;
#   2. menyalin .env, database, dan file upload (storage/app) dari server lama
#      lewat SSH. Server lama hanya DIBACA, tidak ada yang diubah di sana;
#   3. membuat database baru (otomatis bila dijalankan root), mengimpor data,
#      composer install, migrate, storage:link, izin folder;
#   4. opsional: cron jadwal (schedule:run) dan memindahkan webhook bot Telegram.
#
# APP_KEY dipertahankan supaya token yang terenkripsi (Settings → Integrasi)
# tetap terbaca. Memperbarui kode situs CWP di kemudian hari:
#
#   bash migrate-cwp.sh update /home/AKUN/public_html/FOLDER
#
# Memperbarui ERP versi lama yang sudah terpasang (data situs itu tetap):
#
#   bash migrate-cwp.sh upgrade ~/erp.domain.com    # backup → kode baru → composer update → migrate
#   bash migrate-cwp.sh rollback ~/erp.domain.com   # kembali ke kondisi sebelum upgrade terakhir
#   bash migrate-cwp.sh check ~/erp.domain.com      # bandingkan database dengan migrasi terbaru (baca saja)
#
# Opsi lewat variabel lingkungan (melewati pertanyaan): CWP_USER, DOMAIN, SITE,
# SRC (user@host server lama), SRC_PORT, SRC_PATH, DB_NAME, DB_USER, DB_PASS,
# PHP, MOVE_BOTS=y|n, ENABLE_CRON=y|n, ASSUME_YES=1.
set -euo pipefail
# Akun CWP biasa memakai umask 002, sehingga file yang dibuat skrip bisa
# ditulis grup; suPHP menolaknya dengan error 500 (lihat fix_permissions).
umask 022

REPO_TARBALL=${REPO_TARBALL:-https://codeload.github.com/kraftybyte/erp/tar.gz/refs/heads/main}
WORK=$(mktemp -d "${TMPDIR:-/tmp}/erp-migrate.XXXXXX")
cleanup() {
    [ -S "$WORK/ssh-master" ] && ssh -o "ControlPath=$WORK/ssh-master" -O exit dummy >/dev/null 2>&1 || true
    rm -rf "$WORK"
}
trap cleanup EXIT

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
info() { printf '   %s\n' "$*"; }
die()  { printf '\n\033[31mGAGAL: %s\033[0m\n' "$*" >&2; exit 1; }

ask() { # ask VAR "Pertanyaan" "default"
    local var=$1 question=$2 default=${3:-} answer
    if [ -n "${!var:-}" ]; then return; fi
    if [ "${ASSUME_YES:-}" = 1 ] && [ -n "$default" ]; then printf -v "$var" '%s' "$default"; return; fi
    read -r -p "   $question${default:+ [$default]}: " answer </dev/tty
    printf -v "$var" '%s' "${answer:-$default}"
}

yes_no() { # yes_no VAR "Pertanyaan" y|n
    local var=$1
    ask "$var" "$2 (y/n)" "$3"
    case "${!var}" in [yY]*) printf -v "$var" y ;; *) printf -v "$var" n ;; esac
}

pick_php() {
    local candidate
    for candidate in ${PHP:-} /opt/alt/php-fpm84/usr/bin/php /opt/alt/php-fpm83/usr/bin/php /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php php /usr/local/bin/php; do
        command -v "$candidate" >/dev/null 2>&1 || continue
        if "$candidate" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' 2>/dev/null; then
            echo "$candidate"
            return
        fi
    done
    die "PHP 8.3 atau lebih baru tidak ditemukan. Pasang di CWP: PHP Settings → PHP-FPM Selector (pilih 8.3/8.4), lalu jalankan lagi (atau set PHP=/path/ke/php)."
}

check_extensions() {
    local missing
    missing=$("$PHP" -r 'foreach (array_slice($argv, 1) as $e) { if (! extension_loaded($e)) { echo $e, " "; } }' \
        pdo_mysql mbstring gd zip fileinfo intl curl xml dom openssl tokenizer ctype)
    [ -z "$missing" ] || die "Ekstensi PHP belum aktif: ${missing}. Aktifkan di CWP: PHP Settings → PHP-FPM Selector → extensions."
}

# Huruf/angka acak (lewat PHP: tr </dev/urandom | head kena SIGPIPE di bawah pipefail).
random_text() {
    "$PHP" -r '$c = "abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789"; $s = ""; for ($i = 0; $i < (int) $argv[1]; $i++) { $s .= $c[random_int(0, strlen($c) - 1)]; } echo $s;' "$1"
}

# Tanpa pipefail: grep -q berhenti lebih awal dan perintah di depannya kena SIGPIPE.
contains() { # contains "pola" perintah...
    local pattern=$1
    shift
    ( set +o pipefail; "$@" 2>/dev/null | grep -q -- "$pattern" )
}

# set_env FILE KEY VALUE: ganti atau tambah baris KEY= di .env (nilai dikutip bila perlu).
set_env() {
    "$PHP" -r '
        [$file, $key, $value] = array_slice($argv, 1);
        $quoted = preg_match("/^[A-Za-z0-9_.:\/@-]*$/", $value) ? $value : "\"" . addcslashes($value, "\"\\\$") . "\"";
        $lines  = file_exists($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
        $found  = false;
        foreach ($lines as $i => $line) {
            if (preg_match("/^" . preg_quote($key, "/") . "=/", $line)) { $lines[$i] = "$key=$quoted"; $found = true; }
        }
        if (! $found) { $lines[] = "$key=$quoted"; }
        file_put_contents($file, implode("\n", $lines) . "\n");
    ' "$1" "$2" "$3"
}

env_value() { # env_value FILE KEY (.env berformat Windows/CRLF juga: \r dibuang)
    grep -E "^$2=" "$1" | tail -1 | cut -d= -f2- | tr -d '\r' | sed -e 's/[[:space:]]*$//' -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

# Composer terbaru selalu diunduh: composer bawaan server bisa versi lama (1.x).
# Composer 2.9+ menolak paket yang punya security advisory, termasuk semua
# Laravel 11 yang dipakai ERP ini (juga di produksi). Penolakan itu dimatikan
# hanya untuk proses ini lewat COMPOSER_HOME sementara; pengaturan composer
# server tidak diubah.
composer_run() {
    [ -f "$WORK/composer.phar" ] || curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o "$WORK/composer.phar"
    mkdir -p "$WORK/composer-home"
    [ -f "$WORK/composer-home/config.json" ] || echo '{"config":{"policy":{"advisories":{"block":false}}}}' > "$WORK/composer-home/config.json"
    (cd "$SITE" && COMPOSER_HOME="$WORK/composer-home" COMPOSER_CACHE_DIR="${COMPOSER_CACHE_DIR:-$HOME/.cache/composer}" \
        COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 "$PHP" -d memory_limit=-1 "$WORK/composer.phar" "$@")
}

# Salin kode GitHub ke folder situs; .env, storage/, vendor/, public/storage,
# dan .htaccess situs ini tidak ditimpa.
sync_code() {
    say "Mengunduh kode terbaru dari GitHub"
    mkdir -p "$WORK/code"
    curl -fsSL "$REPO_TARBALL" | tar xz --strip-components=1 -C "$WORK/code" || die "Kode tidak bisa diunduh dari GitHub."
    if command -v rsync >/dev/null 2>&1; then
        rsync -a --exclude=/.env --exclude=/storage/ --exclude=/vendor/ --exclude=/public/storage --exclude=/.htaccess "$WORK/code/" "$SITE/"
    else
        (cd "$WORK/code" && tar cf - --exclude=./.env --exclude=./storage --exclude=./vendor --exclude=./public/storage --exclude=./.htaccess .) | (cd "$SITE" && tar xf -)
    fi
    mkdir -p "$SITE"/storage/{app/public,framework/{cache/data,sessions,views,testing},logs} "$SITE/bootstrap/cache"
    info "Kode tersalin ke $SITE"
}

# Akar situs mengarah ke public/ (seperti di Hostinger), tanpa direktif
# mod_security lama (SecFilter*) yang membuat Apache CWP error 500.
write_htaccess() {
    cat > "$SITE/.htaccess" <<'HTACCESS'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
HTACCESS
}

# vendor/ disalin dari server lama (versi paket sama persis dengan produksi;
# repo sengaja tanpa composer.lock). Bila tidak ada, atau versi PHP di sini
# tidak cocok dengannya, paket dipasang ulang dengan composer.
install_vendor() {
    if [ -f "$SITE/vendor/autoload.php" ] && ! (cd "$SITE" && contains 'Composer detected issues' "$PHP" -d display_errors=1 -r 'require "vendor/autoload.php";'); then
        composer_run dump-autoload --optimize --no-interaction > "$WORK/composer.log" 2>&1 \
            || { tail -20 "$WORK/composer.log"; die "composer dump-autoload gagal."; }
        info "Paket (vendor) siap."
        return
    fi

    info "Memasang paket dengan composer (beberapa menit)…"
    rm -rf "$SITE/vendor"
    composer_run install --no-dev --optimize-autoloader --no-interaction --no-progress > "$WORK/composer.log" 2>&1 \
        || { tail -20 "$WORK/composer.log"; die "composer install gagal."; }
}

finish_laravel() {
    say "Paket & Laravel"
    install_vendor
    (cd "$SITE" && "$PHP" artisan migrate --force > "$WORK/migrate.log" 2>&1) || { tail -60 "$WORK/migrate.log"; die "php artisan migrate gagal."; }
    grep -E 'DONE|Nothing to migrate' "$WORK/migrate.log" | sed 's/^ */   /' | tail -5 || true
    (cd "$SITE" && rm -rf public/storage && "$PHP" artisan storage:link >/dev/null 2>&1 && info "public/storage → storage/app/public")
    (cd "$SITE" && "$PHP" artisan optimize:clear >/dev/null 2>&1 && "$PHP" artisan permission:cache-reset >/dev/null 2>&1 || true)
    fix_permissions
}

# suPHP di CWP menjawab 500 untuk file PHP, atau folder di atasnya, yang bisa
# ditulis grup/orang lain. Kode dan folder situs dibuat 755/644; storage dan
# bootstrap/cache tetap bisa ditulis aplikasi.
fix_permissions() {
    if [ "$(id -u)" = 0 ] && [ -n "${CWP_USER:-}" ]; then
        chown -R "$CWP_USER:$CWP_USER" "$SITE"
    fi
    find "$SITE" \( -path "$SITE/storage" -o -path "$SITE/bootstrap/cache" \) -prune \
        -o ! -type l \( -perm -g+w -o -perm -o+w \) -exec chmod go-w {} + 2>/dev/null || true
    find "$SITE/storage" "$SITE/bootstrap/cache" -type d -exec chmod 775 {} + 2>/dev/null || true
    find "$SITE/storage" "$SITE/bootstrap/cache" -type f -exec chmod 664 {} + 2>/dev/null || true
}

# =====================================================================
# update: perbarui kode situs CWP yang sudah dipasang
# =====================================================================
if [ "${1:-}" = update ]; then
    SITE=${2:-${SITE:-}}
    [ -n "$SITE" ] && [ -f "$SITE/.env" ] && [ -f "$SITE/artisan" ] || die "Pemakaian: bash migrate-cwp.sh update /home/AKUN/public_html/FOLDER"
    SITE=$(cd "$SITE" && pwd)
    CWP_USER=${CWP_USER:-$(stat -c %U "$SITE" 2>/dev/null || true)}
    PHP=$(pick_php)
    (cd "$SITE" && "$PHP" artisan down >/dev/null 2>&1 || true)
    sync_code
    finish_laravel
    (cd "$SITE" && "$PHP" artisan up >/dev/null 2>&1 || true)
    say "SELESAI: kode di $SITE sudah versi terbaru."
    exit 0
fi

# =====================================================================
# upgrade / rollback: ERP versi lama yang sudah terpasang, datanya tetap
# =====================================================================

# Folder kode yang diganti penuh (file yang sudah dihapus di versi baru ikut
# hilang; sisa modul lama bisa membuat error). Isi lain di akar situs (.env,
# storage, backup .sql/.zip, file lain) tidak disentuh.
CODE_DIRS=(app bootstrap config database lang public resources routes stubs scripts tests)

db_env() { # db_env FILE → "host port user database" (password lewat MYSQL_PWD)
    local file=$1 port
    local host
    port=$(env_value "$file" DB_PORT)
    host=$(env_value "$file" DB_HOST)
    printf '%s\n' "${host:-localhost}" "${port:-3306}" "$(env_value "$file" DB_USERNAME)" "$(env_value "$file" DB_DATABASE)"
}

site_mysql() { # site_mysql PROGRAM [args...]: mysql/mysqldump dengan kredensial .env situs
    local prog=$1 host port user db
    shift
    { read -r host; read -r port; read -r user; read -r db; } < <(db_env "$SITE/.env")
    MYSQL_PWD=$(env_value "$SITE/.env" DB_PASSWORD) "$prog" -h "$host" -P "$port" -u "$user" "$@" "$db"
}

# PHP yang dipakai website (bukan CLI) harus 8.3+: dicek lewat file sementara.
web_php_check() {
    local url probe version
    url=$(env_value "$SITE/.env" APP_URL); url=${url%/}
    probe="erp-php-$(random_text 12).php"
    echo '<?php echo PHP_VERSION;' > "$SITE/public/$probe"
    version=$(curl -sk --max-time 20 "$url/$probe" 2>/dev/null | head -c 20 || true)
    rm -f "$SITE/public/$probe"

    if [[ ! "$version" =~ ^[0-9]+\.[0-9]+ ]]; then
        info "PHP website tidak bisa dicek lewat $url (lanjut; pastikan PHP domain ini 8.3+ di CWP)."
    elif ! "$PHP" -r 'exit(version_compare($argv[1], "8.3.0", ">=") ? 0 : 1);' "$version"; then
        die "Website $url memakai PHP $version; versi baru butuh 8.3+. Ganti di CWP: PHP Settings → PHP-FPM Selector (domain ini), lalu jalankan lagi. Belum ada yang diubah."
    else
        info "PHP website: $version"
    fi
}

restore_backup() { # restore_backup BACKUP_DIR
    local backup=$1 table
    say "Mengembalikan kondisi sebelum upgrade ($backup)"
    # public/storage (tautan ke upload) dan bootstrap/cache dibiarkan.
    for dir in "${CODE_DIRS[@]}"; do
        case "$dir" in
            public)    find "${SITE:?}/public" -mindepth 1 -maxdepth 1 ! -name storage -exec rm -rf {} + 2>/dev/null ;;
            bootstrap) find "${SITE:?}/bootstrap" -mindepth 1 -maxdepth 1 ! -name cache -exec rm -rf {} + 2>/dev/null ;;
            *)         rm -rf "${SITE:?}/$dir" ;;
        esac
    done
    rm -f "$SITE"/bootstrap/cache/*.php
    # File di akar folder yang baru ditambahkan versi baru (yang lama ada di code.tar.gz).
    if [ -f "$backup/new-root-files.txt" ]; then
        while read -r file; do [ -n "$file" ] && rm -f "${SITE:?}/$file"; done < "$backup/new-root-files.txt"
    fi
    tar xzf "$backup/code.tar.gz" -C "$SITE"
    if [ -d "$backup/vendor.old" ]; then rm -rf "$SITE/vendor"; mv "$backup/vendor.old" "$SITE/vendor"; fi
    [ -f "$backup/composer.lock" ] && cp "$backup/composer.lock" "$SITE/composer.lock"

    # Database persis seperti sebelum upgrade: tabel baru (mis. dari migrasi) ikut dihapus.
    { echo 'SET FOREIGN_KEY_CHECKS=0;'
      site_mysql mysql -N -e 'SHOW TABLES' | while read -r table; do echo "DROP TABLE IF EXISTS \`$table\`;"; done
      echo 'SET FOREIGN_KEY_CHECKS=1;'
    } | site_mysql mysql
    gunzip -c "$backup/database.sql.gz" | site_mysql mysql

    (cd "$SITE" && "$PHP" artisan optimize:clear >/dev/null 2>&1; "$PHP" artisan up >/dev/null 2>&1) || true
    fix_permissions
    info "Kode, paket, dan database kembali seperti sebelum upgrade."
}

# check <folder>: bandingkan database situs dengan migrasi versi terbaru (hanya membaca).
if [ "${1:-}" = check ]; then
    SITE=${2:-${SITE:-}}
    [ -n "$SITE" ] && [ -f "$SITE/.env" ] || die "Pemakaian: bash migrate-cwp.sh check /path/ke/folder/website"
    SITE=$(cd "$SITE" && pwd)
    PHP=$(pick_php)
    site_mysql mysql -e 'SELECT 1' >/dev/null || die "Tidak bisa masuk ke database situs dengan kredensial di .env."

    say "Cek database $(env_value "$SITE/.env" DB_DATABASE) terhadap kode terbaru (tidak ada yang diubah)"
    mkdir -p "$WORK/code"
    curl -fsSL "$REPO_TARBALL" | tar xz --strip-components=1 -C "$WORK/code" || die "Kode tidak bisa diunduh dari GitHub."
    site_mysql mysql -N -e 'SELECT migration FROM migrations' | tr -d '\r' | sort > "$WORK/ran.txt"
    site_mysql mysql -N -e 'SHOW TABLES' | tr -d '\r' | sort > "$WORK/tables.txt"

    pending=() missing=()
    for file in "$WORK"/code/database/migrations/*.php "$WORK"/code/database/settings/*.php; do
        name=$(basename "$file" .php)
        if ! grep -qxF "$name" "$WORK/ran.txt"; then
            pending+=("$name")
            continue
        fi
        for table in $(grep -oE "Schema::create\(['\"][A-Za-z0-9_]+['\"]" "$file" | sed -E "s/.*\(['\"]//; s/['\"]$//" | sort -u); do
            grep -qxF "$table" "$WORK/tables.txt" || missing+=("$table ← $name")
        done
    done

    info "Tabel di database: $(wc -l < "$WORK/tables.txt") · migrasi tercatat: $(wc -l < "$WORK/ran.txt") · migrasi belum jalan: ${#pending[@]}"
    say "Tercatat sudah jalan, tapi tabelnya tidak ada (${#missing[@]})"
    for row in "${missing[@]}"; do info "$row"; done
    say "Migrasi yang akan dijalankan saat upgrade (${#pending[@]})"
    for row in "${pending[@]}"; do info "$row"; done
    say "Tabel di database"
    tr '\n' ' ' < "$WORK/tables.txt" | fold -s -w 110 | sed 's/^/   /'
    echo

    # Kolom dibanding acuan kode terbaru (SchemaDrift; sama dengan yang diperbaiki migrasi saat upgrade).
    if [ -f "$WORK/code/app/Support/SchemaDrift.php" ] && [ -f "$WORK/code/database/schema/expected-columns.php" ]; then
        say "Kolom dibanding kode terbaru (diperbaiki otomatis saat upgrade)"
        site_mysql mysql -N -B -e "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, '<null>'), EXTRA, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()" > "$WORK/columns.tsv"
        "$PHP" -r '
            require $argv[1];
            $rows = [];
            foreach (file($argv[3], FILE_IGNORE_NEW_LINES) as $line) {
                $cells = explode("\t", rtrim($line, "\r"));
                if (count($cells) < 7) { continue; }
                $cells[4] = $cells[4] === "<null>" ? null : $cells[4];
                $rows[] = $cells;
            }
            $plan = App\Support\SchemaDrift::plan(App\Support\SchemaDrift::siteColumns($rows), require $argv[2]);
            $labels = ["added" => "Kolom hilang, akan ditambahkan", "relaxed" => "Wajib diisi tapi tidak diisi kode, akan dibuat boleh kosong", "widened" => "Akan diperlebar", "notes" => "Tipe berbeda, tidak diubah (cek bila muncul error)"];
            foreach ($labels as $key => $label) {
                printf("   %s (%d)\n", $label, count($plan[$key]));
                foreach ($plan[$key] as $item) { echo "      ", $item, "\n"; }
            }
        ' "$WORK/code/app/Support/SchemaDrift.php" "$WORK/code/database/schema/expected-columns.php" "$WORK/columns.tsv"
    fi
    exit 0
fi

if [ "${1:-}" = upgrade ] || [ "${1:-}" = rollback ]; then
    MODE=$1
    SITE=${2:-${SITE:-}}
    [ -n "$SITE" ] && [ -f "$SITE/.env" ] && [ -f "$SITE/artisan" ] || die "Pemakaian: bash migrate-cwp.sh $MODE /path/ke/folder/website"
    SITE=$(cd "$SITE" && pwd)
    CWP_USER=${CWP_USER:-$(stat -c %U "$SITE" 2>/dev/null || id -un)}
    BACKUPS="$HOME/erp-upgrade"
    PHP=$(pick_php)

    if [ "$MODE" = rollback ]; then
        BACKUP=${3:-$(ls -d "$BACKUPS"/backup-* 2>/dev/null | sort | tail -1)}
        [ -n "$BACKUP" ] && [ -f "$BACKUP/database.sql.gz" ] && [ -f "$BACKUP/code.tar.gz" ] || die "Backup upgrade tidak ditemukan di $BACKUPS."
        (cd "$SITE" && "$PHP" artisan down >/dev/null 2>&1 || true)
        restore_backup "$BACKUP"
        say "SELESAI: rollback dari $BACKUP"
        exit 0
    fi

    say "Upgrade ERP di $SITE (data tetap)"
    info "PHP: $("$PHP" -r 'echo PHP_VERSION;') ($PHP)"
    check_extensions
    for tool in mysqldump mysql rsync curl tar gzip; do
        command -v "$tool" >/dev/null 2>&1 || die "Perintah '$tool' tidak ada di server ini."
    done
    site_mysql mysql -e 'SELECT 1' >/dev/null || die "Tidak bisa masuk ke database situs dengan kredensial di .env."
    web_php_check

    say "Mengunduh kode terbaru dari GitHub"
    mkdir -p "$WORK/code"
    curl -fsSL "$REPO_TARBALL" | tar xz --strip-components=1 -C "$WORK/code" || die "Kode tidak bisa diunduh dari GitHub; belum ada yang diubah."
    [ -f "$WORK/code/artisan" ] || die "Unduhan kode tidak lengkap; belum ada yang diubah."
    root_files=()
    for file in $(ls -A "$WORK/code"); do
        [ -f "$WORK/code/$file" ] && [ "$file" != .env ] && [ "$file" != .htaccess ] && root_files+=("$file")
    done

    BACKUP="$BACKUPS/backup-$(date +%Y%m%d-%H%M%S)"
    mkdir -p "$BACKUP"
    chmod 700 "$BACKUPS" "$BACKUP"

    say "Backup (di luar folder website)"
    site_mysql mysqldump --single-transaction --quick --no-tablespaces | gzip > "$BACKUP/database.sql.gz"
    contains 'CREATE TABLE' gunzip -c "$BACKUP/database.sql.gz" || die "Backup database gagal; upgrade dibatalkan, belum ada yang diubah."
    existing=()
    for dir in "${CODE_DIRS[@]}"; do [ -e "$SITE/$dir" ] && existing+=("$dir"); done
    : > "$BACKUP/new-root-files.txt"
    for file in "${root_files[@]}"; do
        if [ -e "$SITE/$file" ]; then existing+=("$file"); else echo "$file" >> "$BACKUP/new-root-files.txt"; fi
    done
    (cd "$SITE" && tar czf "$BACKUP/code.tar.gz" --exclude=public/storage --exclude=bootstrap/cache "${existing[@]}") || die "Backup kode gagal; belum ada yang diubah."
    [ -f "$SITE/composer.lock" ] && cp "$SITE/composer.lock" "$BACKUP/composer.lock"
    info "Database: $(du -h "$BACKUP/database.sql.gz" | cut -f1) · kode: $(du -h "$BACKUP/code.tar.gz" | cut -f1) → $BACKUP"

    # Sejak sini setiap kegagalan mengembalikan kondisi semula.
    rollback_on_error() {
        local status=$?
        trap - ERR
        [ "$status" -eq 0 ] && return
        set +e   # kembalikan sebanyak mungkin walau satu langkah gagal
        printf '\n\033[31mUpgrade gagal (langkah di atas).\033[0m\n' >&2
        restore_backup "$BACKUP"
        exit "$status"
    }
    trap rollback_on_error ERR
    # Kegagalan yang terdeteksi langsung memulihkan backup (set -e di dalam
    # fungsi keluar sebelum trap ERR sempat jalan).
    die() {
        printf '\n\033[31mGAGAL: %s\033[0m\n' "$*" >&2
        trap - ERR
        set +e
        restore_backup "$BACKUP"
        exit 1
    }

    (cd "$SITE" && "$PHP" artisan down >/dev/null 2>&1 || true)

    say "Memasang kode baru"
    for dir in "${CODE_DIRS[@]}"; do
        [ -d "$WORK/code/$dir" ] || continue
        mkdir -p "$SITE/$dir"
        rsync -a --delete --exclude=/storage --exclude=/.well-known --exclude=/cache/ "$WORK/code/$dir/" "$SITE/$dir/"
    done
    # File di akar folder (artisan, composer.json, …); .env, .htaccess, dan izin folder situs tetap.
    for file in "${root_files[@]}"; do cp -p "$WORK/code/$file" "$SITE/$file"; done
    mkdir -p "$SITE"/storage/{app/public,framework/{cache/data,sessions,views,testing},logs} "$SITE/bootstrap/cache"
    rm -f "$SITE/composer.lock" "$SITE"/bootstrap/cache/*.php
    info "Kode diganti; .env, storage, dan file lain di akar folder tetap."

    say "Paket (composer update, beberapa menit)"
    mv "$SITE/vendor" "$BACKUP/vendor.old" 2>/dev/null || true
    if ! composer_run update --no-dev --optimize-autoloader --no-interaction --no-progress > "$WORK/composer.log" 2>&1; then
        tail -20 "$WORK/composer.log"
        die "composer update gagal."
    fi
    info "Paket terpasang."

    say "Database (migrate)"
    if ! (cd "$SITE" && "$PHP" artisan migrate --force > "$WORK/migrate.log" 2>&1); then
        tail -60 "$WORK/migrate.log"
        die "php artisan migrate gagal."
    fi
    grep -cE 'DONE' "$WORK/migrate.log" | sed 's/^/   Migrasi dijalankan: /' || true

    trap - ERR
    (cd "$SITE" && { [ -e public/storage ] || "$PHP" artisan storage:link >/dev/null 2>&1; }; "$PHP" artisan optimize:clear >/dev/null 2>&1; "$PHP" artisan permission:cache-reset >/dev/null 2>&1; "$PHP" artisan up >/dev/null 2>&1) || true
    fix_permissions

    say "Pemeriksaan"
    url=$(env_value "$SITE/.env" APP_URL); url=${url%/}
    code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 "$url/admin/login" 2>/dev/null || true)
    if [ "$code" = 200 ]; then
        info "$url/admin/login → 200"
    else
        info "$url/admin/login → HTTP ${code:-000}. Lihat $SITE/storage/logs/laravel.log; kembali ke versi lama: bash $0 rollback $SITE"
    fi

    say "SELESAI: ERP di $SITE sudah versi terbaru, data tetap."
    info "Backup sebelum upgrade: $BACKUP (hapus bila sudah yakin; rollback: bash $0 rollback $SITE)"
    exit 0
fi

# =====================================================================
# Migrasi
# =====================================================================
say "Migrasi ERP ke CWP"
info "Server lama hanya dibaca. Kosongkan jawaban untuk memakai nilai [bawaan]."

for tool in curl tar gzip ssh mysql; do
    command -v "$tool" >/dev/null 2>&1 || die "Perintah '$tool' tidak ada di server ini."
done

if [ "$(id -u)" = 0 ]; then
    ask CWP_USER "Akun CWP pemilik website (username di CWP)"
    id "$CWP_USER" >/dev/null 2>&1 || die "Akun '$CWP_USER' tidak ada di server ini."
else
    CWP_USER=$(id -un)
fi

ask DOMAIN "Domain website baru (mis. erp.domainbaru.com)"
DOMAIN=${DOMAIN#https://}; DOMAIN=${DOMAIN#http://}; DOMAIN=${DOMAIN%%/*}
[ -n "$DOMAIN" ] || die "Domain wajib diisi."

default_site="/home/$CWP_USER/public_html"
[ "$(tr -cd . <<<"$DOMAIN" | wc -c)" -ge 2 ] && default_site="$default_site/${DOMAIN%%.*}"
ask SITE "Folder website (document root domain di CWP)" "$default_site"

if [ -d "$SITE" ] && [ -n "$(ls -A "$SITE" | grep -vxE 'index\.html|cgi-bin|\.well-known|\.htaccess|error_log' || true)" ]; then
    die "Folder $SITE tidak kosong. Kosongkan dulu, atau pilih folder lain. (Untuk memperbarui situs yang sudah ada: bash migrate-cwp.sh update $SITE)"
fi

say "Server lama (sumber data)"
info "Isi 'local' bila website lama ada di server ini juga (pindah antar-website di CWP yang sama)."
ask SRC "SSH server lama (user@host, mis. u426040466@IP-server-Hostinger)"
if [ "$SRC" = local ]; then
    ask SRC_PATH "Folder website lama di server ini (path lengkap)"
    remote() { bash -c "$*"; }
else
    ask SRC_PORT "Port SSH server lama" "65002"
    ask SRC_PATH "Folder situs di server lama" "domains/signcare.id/public_html/erp"
    SSH_OPTS=(-p "$SRC_PORT" -o ControlMaster=auto -o "ControlPath=$WORK/ssh-master" -o ControlPersist=15m -o StrictHostKeyChecking=accept-new)
    remote() { ssh "${SSH_OPTS[@]}" "$SRC" "$@"; }
    info "Masukkan password SSH server lama bila diminta (sekali saja)."
fi

remote "test -f '$SRC_PATH/.env' && test -f '$SRC_PATH/artisan'" || die "Situs ERP tidak ditemukan di $SRC:$SRC_PATH."

PHP=$(pick_php)
info "PHP: $("$PHP" -r 'echo PHP_VERSION;') ($PHP)"
check_extensions

say "Database baru"
DB_PASS_GIVEN=${DB_PASS+x}
DB_NAME=${DB_NAME:-}; DB_USER=${DB_USER:-}; DB_PASS=${DB_PASS:-}
if [ "$(id -u)" = 0 ] && mysql -e 'SELECT 1' >/dev/null 2>&1; then
    DB_NAME=${DB_NAME:-${CWP_USER}_erp}
    DB_USER=${DB_USER:-${CWP_USER}_erp}
    DB_PASS=${DB_PASS:-$(random_text 24)}
    if mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" | grep -qv '^0$'; then
        die "Database $DB_NAME sudah berisi tabel. Hapus dulu di CWP (MySQL Manager) atau set DB_NAME lain."
    fi
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
              CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
              ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
              GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
              FLUSH PRIVILEGES;"
    info "Database $DB_NAME dan user $DB_USER dibuat (password tersimpan di .env)."
else
    info "Buat database & user dulu di CWP → MySQL Manager, lalu isi di sini."
    ask DB_NAME "Nama database"
    ask DB_USER "User database"
    [ -n "$DB_PASS" ] || [ -n "$DB_PASS_GIVEN" ] || { read -r -s -p "   Password database: " DB_PASS </dev/tty; echo; }
fi
MYSQL_PWD="$DB_PASS" mysql -h localhost -u "$DB_USER" -e "SELECT 1" "$DB_NAME" >/dev/null 2>&1 || die "Tidak bisa masuk ke database $DB_NAME dengan user $DB_USER."

yes_no MOVE_BOTS "Pindahkan bot Telegram ke website baru sekarang? Pilih y hanya bila DNS & SSL domain baru sudah aktif; bot berhenti menjawab di website lama" n
yes_no ENABLE_CRON "Aktifkan jadwal otomatis (cron schedule:run tiap menit: ringkasan pagi, slip gaji, dll.)" n

mkdir -p "$SITE"
sync_code
write_htaccess

say "Menyalin data dari server lama"
remote "cat '$SRC_PATH/.env'" > "$WORK/old.env"
grep -q '^APP_KEY=base64:' "$WORK/old.env" || die "APP_KEY tidak ditemukan di .env server lama."

remote bash -s -- "$SRC_PATH" > "$WORK/db.sql.gz" <<'REMOTE'
set -o pipefail
cd "$1" || exit 1
v() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | tr -d '\r' | sed -e 's/[[:space:]]*$//' -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
extra=""
mysqldump --help 2>/dev/null | grep -c -- '--set-gtid-purged' >/dev/null && extra="--set-gtid-purged=OFF"
port=$(v DB_PORT)
MYSQL_PWD=$(v DB_PASSWORD) mysqldump --single-transaction --quick --no-tablespaces $extra \
    -h "$(v DB_HOST)" -P "${port:-3306}" -u "$(v DB_USERNAME)" "$(v DB_DATABASE)" | gzip
REMOTE
gzip -t "$WORK/db.sql.gz" 2>/dev/null && contains 'CREATE TABLE' gunzip -c "$WORK/db.sql.gz" || die "Dump database dari server lama gagal atau kosong."
info "Database: $(du -h "$WORK/db.sql.gz" | cut -f1) (terkompresi)"

remote "cd '$SRC_PATH' && tar czf - --exclude=storage/app/livewire-tmp storage/app" | tar xzf - -C "$SITE" || die "File upload (storage/app) gagal disalin."
info "File upload: $(du -sh "$SITE/storage/app" | cut -f1)"

remote "cd '$SRC_PATH' && test -f vendor/autoload.php && tar czf - vendor" | tar xzf - -C "$SITE" 2>/dev/null \
    && info "Paket (vendor) disalin dari server lama: $(du -sh "$SITE/vendor" | cut -f1)" \
    || info "vendor tidak bisa disalin dari server lama; dipasang dengan composer."

say "Menyiapkan .env"
cp "$WORK/old.env" "$SITE/.env"
set_env "$SITE/.env" APP_URL "https://$DOMAIN"
set_env "$SITE/.env" APP_ENV production
set_env "$SITE/.env" APP_DEBUG false
set_env "$SITE/.env" DB_CONNECTION mysql
set_env "$SITE/.env" DB_HOST localhost
set_env "$SITE/.env" DB_PORT 3306
set_env "$SITE/.env" DB_DATABASE "$DB_NAME"
set_env "$SITE/.env" DB_USERNAME "$DB_USER"
set_env "$SITE/.env" DB_PASSWORD "$DB_PASS"
grep -q '^VITE_APP_URL=' "$SITE/.env" && set_env "$SITE/.env" VITE_APP_URL "https://$DOMAIN"
grep -q '^ASSET_URL=' "$SITE/.env" && set_env "$SITE/.env" ASSET_URL "https://$DOMAIN"
grep -q '^SESSION_DOMAIN=.' "$SITE/.env" && set_env "$SITE/.env" SESSION_DOMAIN ""
chmod 640 "$SITE/.env"
info "APP_KEY lama dipakai; APP_URL https://$DOMAIN"

say "Mengimpor database"
# MySQL 8 → MariaDB (umum di CWP): kolasi utf8mb4_0900_* belum dikenal MariaDB.
if contains MariaDB mysql -V; then
    gunzip -c "$WORK/db.sql.gz" | sed -e 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' -e 's/utf8mb4_0900_as_cs/utf8mb4_bin/g' \
        | MYSQL_PWD="$DB_PASS" mysql -h localhost -u "$DB_USER" "$DB_NAME"
else
    gunzip -c "$WORK/db.sql.gz" | MYSQL_PWD="$DB_PASS" mysql -h localhost -u "$DB_USER" "$DB_NAME"
fi
info "Tabel: $(MYSQL_PWD="$DB_PASS" mysql -N -h localhost -u "$DB_USER" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'")"

finish_laravel

if [ "$ENABLE_CRON" = y ]; then
    line="* * * * * PHP=$PHP bash $SITE/scripts/schedule-run.sh >/dev/null 2>&1"
    if [ "$(id -u)" = 0 ]; then
        (crontab -u "$CWP_USER" -l 2>/dev/null | grep -vF "$SITE/scripts/schedule-run.sh"; echo "$line") | crontab -u "$CWP_USER" -
    else
        (crontab -l 2>/dev/null | grep -vF "$SITE/scripts/schedule-run.sh"; echo "$line") | crontab -
    fi
    info "Cron jadwal dipasang untuk $CWP_USER."
fi

if [ "$MOVE_BOTS" = y ]; then
    say "Memindahkan bot Telegram"
    (cd "$SITE" && "$PHP" artisan telegram:webhook set 2>&1 | tail -2; "$PHP" artisan telegram:webhook set --finance 2>&1 | tail -2) || info "Webhook gagal didaftarkan; daftarkan ulang di Settings → Integrasi setelah HTTPS aktif."
fi

say "Pemeriksaan"
code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 "https://$DOMAIN/admin/login" 2>/dev/null || true)
case "${code:-000}" in
    200) info "https://$DOMAIN/admin/login → 200, website baru sudah jalan." ;;
    000) info "https://$DOMAIN belum bisa dibuka dari server ini: arahkan DNS domain ke server CWP dan aktifkan SSL (CWP → AutoSSL)." ;;
    *)   info "https://$DOMAIN/admin/login → HTTP $code. Pastikan PHP domain ini 8.3+ (CWP → PHP-FPM Selector) dan document root = $SITE. Detail error: $SITE/storage/logs/laravel.log" ;;
esac

say "SELESAI"
cat <<DONE
   Website : https://$DOMAIN  (folder $SITE)
   Database: $DB_NAME (user $DB_USER, password di $SITE/.env)
   Data    : database, file upload, dan pengaturan disalin dari $SRC:$SRC_PATH
             (server lama tidak diubah dan tetap berjalan).

   Langkah berikutnya:
   - DNS $DOMAIN → server ini, lalu SSL di CWP (AutoSSL). Login dengan akun yang sama seperti di website lama.
   - PHP domain di CWP: 8.3 atau lebih baru.
$( [ "$MOVE_BOTS" = y ] && echo "   - Bot Telegram sudah diarahkan ke website baru." || echo "   - Bot Telegram masih ke website lama. Untuk memindahkan: Settings → Integrasi → Daftarkan ulang webhook (di website baru)." )
$( [ "$ENABLE_CRON" = y ] && echo "   - Jadwal otomatis aktif." || echo "   - Jadwal otomatis belum aktif (jalankan lagi dengan ENABLE_CRON=y bila perlu)." )
   - Memperbarui kode nanti: bash migrate-cwp.sh update $SITE
DONE
