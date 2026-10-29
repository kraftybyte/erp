<?php

namespace App\Exceptions;

use BezhanSalleh\FilamentExceptions\FilamentExceptions;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if ($this->shouldReport($e)) {
                FilamentExceptions::report($e);
            }
        });

        // 405 tidak pernah tercatat (HttpException tidak dilaporkan), padahal
        // penyebabnya hanya terlihat dari metode, alamat, dan halaman asalnya.
        $this->renderable(function (MethodNotAllowedHttpException $e, Request $request) {
            Log::warning('405 Method Not Allowed', [
                'method'   => $request->method(),
                'path'     => '/' . ltrim($request->path(), '/'),
                'referer'  => $request->headers->get('referer'),
                'livewire' => $request->hasHeader('X-Livewire'),
                'user_id'  => rescue(fn () => $request->user()?->id, report: false),
                'agent'    => Str::limit((string) $request->userAgent(), 150),
            ]);

            return null; // tampilan errors/405 tetap dipakai
        });
    }
}
