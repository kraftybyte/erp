<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PwaMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Add PWA headers
        $response = $next($request);

        // Don't cache the manifest or service worker
        if ($request->is('manifest.json') || $request->is('sw.js')) {
            $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        // Add Android theme color
        $response->headers->set('X-Theme-Color', '#6366f1');

        return $response;
    }
}