<?php

namespace App\Http\Middleware;

use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → General → Site Status "Not Active": panel ERP ditutup untuk
 * perbaikan. Yang boleh membuka Settings → General (izin Shield
 * page_ManageGeneral; super_admin selalu boleh) tetap bisa masuk, supaya
 * situs bisa diaktifkan lagi. Bot Telegram dan jadwal otomatis tidak terpengaruh.
 */
class EnsureSiteActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (static::active() || $request->user()?->can('page_ManageGeneral')) {
            return $next($request);
        }

        // Tampilan error bawaan Laravel (errors::minimal) didaftarkan di sini,
        // sama seperti saat exception handler merender halaman error.
        (new RegisterErrorViewPaths)();

        return response()->view('errors.site-inactive', [], 503);
    }

    public static function active(): bool
    {
        // Nilai kosong (pengaturan belum ada) dianggap aktif.
        return ! in_array(app(GeneralSettings::class)->site_active, [0, '0', false], true);
    }
}
