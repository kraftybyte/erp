# Tutorial Setup Instagram OAuth (Local Testing)

## Prasyarat
- Akun Meta Developer (facebook.com/developers)
- Akun Instagram Business/Creator yang sudah connected ke Facebook Page
- Laravel app sudah running di localhost:8000

---

## Step 1: Buat App di Meta Developer Console

1. Buka [developers.facebook.com](https://developers.facebook.com)
2. Klik **Create App** → pilih type **Consumer**
3. Isi app name, email, dan klik **Create App**
4. Di Dashboard app, klik **Add Products** → pilih **Instagram API** → klik **Set Up**

---

## Step 2: Konfigurasi Instagram API

1. Di sidebar kiri: **Instagram API** → **Basic Display**
2. Scroll ke bawah, klik **Add Platform** → pilih **Website**
3. Isi:
   - Site URL: `https://your-ngrok-url.ngrok.io` (nanti diubah setelah setup ngrok)
   - Privacy Policy URL: `https://your-domain.com/privacy`
   - Redirect URL whitelist: `https://your-ngrok-url.ngrok.io/auth/instagram/callback`

---

## Step 3: Setup ngrok (untuk HTTPS)

1. Download ngrok dari [ngrok.com](https://ngrok.com/download)
2. Extract dan register (free account needed)
3. Connect account:
   ```bash
   ngrok config add-authtoken YOUR_AUTH_TOKEN
   ```
4. Start tunnel (di terminal baru, jangan stop Laravel):
   ```bash
   ngrok http 8000
   ```
5. Copy **Forwarding** URL yang starts with `https://` (misal: `https://abc123.ngrok.io`)

---

## Step 4: Update Meta Console Redirect URI

1. Kembali ke Meta Developer Console
2. **Instagram API** → **Basic Display**
3. Pada **Valid OAuth Redirect URIs**, paste:
   ```
   https://abc123.ngrok.io/auth/instagram/callback
   ```
4. Klik **Save Changes**

---

## Step 5: Konfigurasi .env Laravel

Edit file `.env` di project Laravel:

```env
APP_URL=https://abc123.ngrok.io

# Meta App Credentials (dari Meta Developer Console)
META_APP_ID=1234567890123456
META_APP_SECRET=abcdef1234567890abcdef1234567890

# Instagram OAuth
INSTAGRAM_CLIENT_ID=1234567890123456
INSTAGRAM_CLIENT_SECRET=abcdef1234567890abcdef1234567890
```

> **Note**: Untuk Basic Instagram Display API, `INSTAGRAM_CLIENT_ID` = `META_APP_ID` yang sama.

---

## Step 6: Setup OAuth di Meta Console

1. Di sidebar kiri: **Instagram API** → **Basic Display**
2. Scroll ke **Test OAuth** section
3. Tambahkan **Roles** → **Instagram Testers**:
   - Masukkan username Instagram yang mau di-test
   - Buka [instagram.com](https://instagram.com) → Settings → Apps and Websites → Tester Invites → Accept

---

## Step 7: Update Config (services.php)

File `config/services.php` otomatis read dari `.env`. Pastikan:

```php
'meta' => [
    'app_id'     => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),
],

'instagram' => [
    'client_id'     => env('INSTAGRAM_CLIENT_ID'),
    'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
    'redirect'      => env('APP_URL') . '/auth/instagram/callback',
],
```

---

## Step 8: Clear Cache Laravel

```bash
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

---

## Step 9: Testing Flow

1. Pastikan Laravel running: `php artisan serve`
2. Pastikan ngrok tunnel active
3. Buka browser: `https://abc123.ngrok.io/admin/connected-account-resource/connected-accounts`
4. Klik **Connect Instagram Account**
5. Proses OAuth akan redirect ke Meta → login → approve → callback

---

## Step 10: Production Deployment Checklist

Kalau sudah mau deploy ke server production:

### Meta Console Changes:
- [ ] HTTPS redirect URI wajib (no ngrok lagi)
- [ ] App Status → **Live** (atau submit permissions untuk review)
- [ ] Privacy Policy URL harus valid
- [ ] Terms of Service URL harus valid

### Required Permissions yang perlu di-submit untuk review:
```
instagram_basic
instagram_content_publish
instagram_manage_insights
pages_read_engagement
```

### Server Requirements:
- [ ] SSL certificate aktif
- [ ] APP_URL = `https://your-production-domain.com`
- [ ] Redirect URI di Meta Console = `https://your-production-domain.com/auth/instagram/callback`

---

## Troubleshooting

### Error: "redirect URI doesn't match"
- Pastikan APP_URL di .env persis sama dengan redirect URI di Meta Console
- Jangan ada trailing slash discrepancy

### Error: "user is not tester"
- Buka Instagram → Settings → Apps and Websites → Tester Invites → Accept invite

### Error: "App not approved"
- Untuk development, tambahkan tester users di Roles
- Untuk production, submit app untuk review

### Token expired
- Run `php artisan instagram:refresh-tokens` untuk auto-refresh
- Atur cron job: `0 * * * * php artisan schedule:run`

---

## Quick Reference

| Item | Value |
|------|-------|
| Callback URL (Basic) | `https://your-domain.com/auth/instagram/callback` |
| Callback URL (Business) | `https://your-domain.com/auth/instagram-business/callback` |
| Auth Endpoint (Basic) | `https://api.instagram.com/oauth/authorize` |
| Auth Endpoint (Business) | `https://www.facebook.com/v18.0/dialog/oauth` |
| Token Exchange | `https://api.instagram.com/oauth/access_token` |
| User Info | `https://graph.instagram.com/me` |

---

## Struktur Code yang Sudah Built

```
app/
├── Http/Controllers/Auth/
│   └── InstagramConnectController.php   ← OAuth flow handler
├── Services/
│   ├── InstagramService.php             ← API calls
│   └── InstagramSyncService.php          ← Sync logic
├── Models/
│   ├── ConnectedAccount.php              ← Account model
│   ├── SocialPost.php                    ← Posts
│   └── SocialAccountInsight.php         ← Daily metrics
├── Filament/Resources/
│   └── ConnectedAccountResource/         ← Admin panel
└── Console/Commands/
    ├── RefreshInstagramTokens.php        ← Auto token refresh
    └── SyncInstagramMetrics.php          ← Daily sync cron
```

---

Generated for local testing with ngrok. Update ngrok URL setiap kali restart ngrok (URL baru tiap restart).