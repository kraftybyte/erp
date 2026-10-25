# Migrasi Laravel ERP ke cPanel Shared Hosting

> **Catatan keamanan**
>
> Berkas ini ikut masuk version control, jadi JANGAN menuliskan nilai kredensial
> yang sebenarnya di sini — cukup nama variabelnya. Nilai asli hanya boleh ada di
> `.env` pada masing-masing server, yang sudah diabaikan git.
>
> Riwayat berkas ini pernah memuat APP_KEY dan META_APP_SECRET asli; keduanya
> harus dianggap bocor dan sudah dirotasi.


## Overview

Target: `erp.getkrafty.id` → cPanel hosting
Database: MySQL di cPanel
File deployment: Git atau manual upload

---

## Step 1: Persiapan di cPanel

### 1.1 Buat Database
1. Login cPanel → **MySQL Database Wizard**
2. Buat database: `erp_digitalk`
3. Buat user + password (simpan, perlu untuk `.env`)
4. Assign user ke database + centang ALL PRIVILEGES

### 1.2 Buat Subdomain
1. cPanel → **Subdomains**
2. Buat: `erp` → document root: `/public_html/erp` (atau sesuai preference)
3. Pastikan SSL aktif (cPanel → SSL/TLS → AutoSSL biasanya auto)

### 1.3 Cek PHP Version
1. cPanel → **MultiPHP Manager** atau **Select PHP Version**
2. Pastikan PHP 8.2+ (Laravel 11 butuh minimal 8.2)

---

## Step 2: Persiapan Local

### 2.1 Update `.env` untuk production
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.getkrafty.id

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u426040466_erp_digitalk  # sesuai nama db di cPanel
DB_USERNAME=u426040466_erp_user     # user db cPanel
DB_PASSWORD=your_db_password_here

SESSION_DOMAIN=.erp.getkrafty.id
SESSION_SECURE_COOKIE=true
```

### 2.2 Buat optimized .env (production ready)
```env
APP_NAME="ERP - Krafty"
APP_VERSION=1.15.0
APP_ENV=production
APP_KEY=                      # isi dengan: php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://erp.getkrafty.id

LOG_CHANNEL=daily
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u426040466_erp_digitalk
DB_USERNAME=u426040466_erp_user
DB_PASSWORD=YOUR_DB_PASSWORD

CACHE_DRIVER=file
FILESYSTEM_DISK=public
QUEUE_CONNECTION=database
SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=.erp.getkrafty.id

# Social Media API (Instagram/Meta)
META_APP_ID=                  # ambil dari Meta for Developers
META_APP_SECRET=              # ambil dari Meta for Developers > App > Settings > Basic
META_BUSINESS_ID=             # ambil dari Meta Business Suite
INSTAGRAM_CLIENT_ID=          # sama dengan META_APP_ID
INSTAGRAM_CLIENT_SECRET=      # sama dengan META_APP_SECRET
```

### 2.3 Optimasi sebelum upload
```bash
# Clear semua cache
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

# Optimize
php artisan config:cache
php artisan route:cache

# Export database schema
mysqldump -u root -p --no-data u426040466_erp_digitalk > schema.sql
```

---

## Step 3: Upload File ke cPanel

### Option A: Git Deploy (Recommended)

1. Di cPanel → **Git Version Control** → Create
2. Clone repo: `https://github.com/your-repo/erp.git`
3. Set deployment path: `/public_html/erp`
4. Checkout branch `main`

**Setup post-deploy hook** (cPanel → Git™ Version Control → Manage → Hooks):
```bash
cd /home/username/public_html/erp
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Option B: Manual Upload (FileZilla/WinSCP)

1. **EXCLUDE** sebelum upload:
   ```
   node_modules/
   vendor/  ← composer install dulu via SSH
   .env
   storage/logs/*.log
   .git/
   .gitignore
   *.md
   ```

2. Upload semua file ke `/public_html/erp/`

3. Via cPanel **Terminal** atau SSH:
   ```bash
   cd /home/username/public_html/erp
   composer install --no-dev --optimize-autoloader
   ```

---

## Step 4: Setup Document Root

Laravel butuh `/public` sebagai document root (bukan `/public_html`).

### Via cPanel → Subdomain:
```
Document Root: /public_html/erp/public
```

### Atau via .htaccess di /public_html/erp:
```apache
RewriteEngine On
RewriteRule ^(.*)$ public/$1 [L]
```

---

## Step 5: File Permissions

Via cPanel Terminal / SSH:

```bash
cd /home/username/public_html/erp

# Directories
chmod 755 storage
chmod 755 storage/app
chmod 755 storage/app/public
chmod 755 storage/framework
chmod 755 storage/framework/cache
chmod 755 storage/framework/sessions
chmod 755 storage/framework/views
chmod 755 storage/logs
chmod 755 bootstrap/cache

# Make writable
chmod -R 775 storage/app/public
chmod -R 775 storage/framework/cache
chmod -R 775 bootstrap/cache

# Public files
chmod 644 public/index.php
chmod 644 .env
```

---

## Step 6: Migration Database

### Option A: PhpMyAdmin (Manual)

1. Export local DB: `mysqldump -u root -p u426040466_erp_digitalk > backup.sql`
2. Import: cPanel → PhpMyAdmin → pilih database → Import → upload `.sql`

### Option B: SSH/Terminal

```bash
# Local: export
mysqldump -u root -p u426040466_erp_digitalk > backup.sql

# Upload ke hosting (FileZilla), lalu:

# Remote SSH:
mysql -u erp_user -p erp_database < backup.sql
```

---

## Step 7: Cron Job Setup

cPanel → **Cron Jobs** → Advanced (cron email)

```bash
# Sync setiap 1 jam
0 * * * * cd /home/username/public_html/erp && php artisan schedule:run >> /dev/null 2>&1

# Backup database tiap hari 2AM
0 2 * * * mysqldump -u erp_user -p'PASSWORD' erp_database > /home/username/backups/erp_$(date +\%Y\%m\%d).sql
```

---

## Step 8: SSL & HTTPS

cPanel → **SSL/TLS** → Manage SSL Sites

Atau AutoSSL biasanya auto-generate untuk subdomain.

**Force HTTPS** - edit `/public_html/erp/public/.htaccess`:

```apache
# Force HTTPS
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

## Step 9: Post-Deployment Checklist

```bash
# Verify installation
php artisan --version
php artisan route:list
php artisan config:show app

# Clear & rebuild caches
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Test login
curl -I https://erp.getkrafty.id
curl -I https://erp.getkrafty.id/admin
```

---

## Step 10: Troubleshooting

### Error 500 - Blank Page
```bash
# Check storage/logs
tail -f storage/logs/laravel.log

# Fix permissions
chmod -R 775 storage bootstrap/cache
```

### Error "No input file specified"
→ Document root salah. Ubah ke `/public_html/erp/public`

### Error Database Connection
→ Cek DB credentials di `.env`, pastikan `DB_HOST=localhost`

### Error Class not found / Missing vendor
```bash
composer install --no-dev --optimize-autoloader
```

### Error 403 Forbidden
```bash
# Fix permissions public directory
chmod 755 public
chmod 644 public/*.php public/.htaccess
```

### Error CSRF / Session
```env
SESSION_DOMAIN=.erp.getkrafty.id
SANCTUM_STATEFUL_DOMAINS=erp.getkrafty.id
```

---

## Quick Commands Reference

```bash
# Deploy via git pull
cd /home/username/public_html/erp && git pull && composer install --no-dev && php artisan migrate --force && php artisan config:cache

# Rollback if broken
git reset --hard HEAD
git pull

# Clear all caches
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan optimize

# Check errors
tail -100 storage/logs/laravel.log
```

---

## Security Checklist Production

- [ ] `APP_DEBUG=false`
- [ ] `.env` not publicly accessible
- [ ] Strong DB password
- [ ] SSL aktif
- [ ] `APP_KEY` tidak di-hardcode di version control
- [ ] File permissions benar
- [ ] Logrotate configured
- [ ] Backup strategy active