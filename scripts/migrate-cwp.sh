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
# Opsi lewat variabel lingkungan (melewati pertanyaan): CWP_USER, DOMAIN, SITE,
# SRC (user@host server lama), SRC_PORT, SRC_PATH, DB_NAME, DB_USER, DB_PASS,
# PHP, MOVE_BOTS=y|n, ENABLE_CRON=y|n, ASSUME_YES=1.
set -euo pipefail

REPO_TARBALL=https://codeload.github.com/kraftybyte/erp/tar.gz/refs/heads/main
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

env_value() { # env_value FILE KEY
    grep -E "^$2=" "$1" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

composer_run() {
    if command -v composer >/dev/null 2>&1 && composer --version >/dev/null 2>&1; then
        (cd "$SITE" && COMPOSER_ALLOW_SUPERUSER=1 "$PHP" "$(command -v composer)" "$@")
    else
        [ -f "$WORK/composer.phar" ] || curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o "$WORK/composer.phar"
        (cd "$SITE" && COMPOSER_ALLOW_SUPERUSER=1 "$PHP" "$WORK/composer.phar" "$@")
    fi
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
    (cd "$SITE" && "$PHP" artisan migrate --force > "$WORK/migrate.log" 2>&1) || { tail -20 "$WORK/migrate.log"; die "php artisan migrate gagal."; }
    grep -E 'DONE|Nothing to migrate' "$WORK/migrate.log" | sed 's/^ */   /' | tail -5 || true
    (cd "$SITE" && rm -rf public/storage && "$PHP" artisan storage:link >/dev/null 2>&1 && info "public/storage → storage/app/public")
    (cd "$SITE" && "$PHP" artisan optimize:clear >/dev/null 2>&1 && "$PHP" artisan permission:cache-reset >/dev/null 2>&1 || true)
    fix_permissions
}

fix_permissions() {
    if [ "$(id -u)" = 0 ] && [ -n "${CWP_USER:-}" ]; then
        chown -R "$CWP_USER:$CWP_USER" "$SITE"
    fi
    find "$SITE/storage" "$SITE/bootstrap/cache" -type d -exec chmod 775 {} + 2>/dev/null || true
    find "$SITE/storage" "$SITE/bootstrap/cache" -type f -exec chmod 664 {} + 2>/dev/null || true
}

# =====================================================================
# update: perbarui kode situs CWP yang sudah dipasang
# =====================================================================
if [ "${1:-}" = update ]; then
    SITE=${2:-${SITE:-}}
    [ -n "$SITE" ] && [ -f "$SITE/.env" ] && [ -f "$SITE/artisan" ] || die "Pemakaian: bash migrate-cwp.sh update /home/AKUN/public_html/FOLDER"
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
    DB_PASS=${DB_PASS:-$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)}
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
v() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
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
