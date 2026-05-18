<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    public function analyze(string $prompt): string
    {
        $key = env('GEMINI_API_KEY');

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent";

        $response = Http::timeout(30)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-goog-api-key' => $key,
            ])
            ->post($url, [
                "contents" => [
                    [
                        "parts" => [
                            ["text" => $prompt]
                        ]
                    ]
                ]
            ]);

        if (!$response->successful()) {
            Log::error('Gemini API Error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return "AI request failed. Check logs.";
        }

        return data_get(
            $response->json(),
            'candidates.0.content.parts.0.text',
            'No analysis generated'
        );
    }
}
