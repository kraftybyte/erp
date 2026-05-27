<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GoogleCalendarService
{
    private ?string $accessToken = null;
    private ?string $refreshToken = null;
    private ?string $calendarId = null;

    const CACHE_KEY_TOKEN = 'google_calendar_token';
    const CACHE_KEY_REFRESH = 'google_calendar_refresh';
    const CACHE_TTL = 3600; // 1 hour

    public function __construct()
    {
        // Load from settings/config
        $this->accessToken = Cache::get(self::CACHE_KEY_TOKEN);
        $this->refreshToken = Cache::get(self::CACHE_KEY_REFRESH);
        $this->calendarId = config('services.google.calendar_id', 'primary');
    }

    /**
     * Check if Google Calendar is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->accessToken) && !empty($this->refreshToken);
    }

    /**
     * Set tokens (for OAuth flow)
     */
    public function setTokens(string $accessToken, string $refreshToken): void
    {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;

        Cache::put(self::CACHE_KEY_TOKEN, $accessToken, self::CACHE_TTL);
        Cache::put(self::CACHE_KEY_REFRESH, $refreshToken, 86400 * 30); // 30 days
    }

    /**
     * Refresh access token
     */
    public function refreshAccessToken(): bool
    {
        if (empty($this->refreshToken)) {
            return false;
        }

        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $this->refreshToken,
                'grant_type' => 'refresh_token',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->setTokens($data['access_token'], $this->refreshToken);
                return true;
            }
        } catch (\Exception $e) {
            Log::error('Google Calendar token refresh failed', ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Sync Event to Google Calendar
     */
    public function syncEvent(Event $event): ?array
    {
        if (!$this->isConfigured()) {
            Log::warning('Google Calendar not configured, skipping sync');
            return null;
        }

        try {
            // Refresh token if needed
            $this->refreshAccessToken();

            $startTime = Carbon::parse($event->start_time);
            $endTime = Carbon::parse($event->end_time);

            $eventData = [
                'summary' => $event->title,
                'description' => $event->description ?? '',
                'start' => [
                    'dateTime' => $startTime->toIso8601String(),
                    'timeZone' => 'Asia/Jakarta',
                ],
                'end' => [
                    'dateTime' => $endTime->toIso8601String(),
                    'timeZone' => 'Asia/Jakarta',
                ],
                'colorId' => $this->getCategoryColor($event->category ?? 'meeting'),
            ];

            // If has google_event_id, update; else create
            if ($event->google_event_id) {
                $response = Http::withToken($this->accessToken)
                    ->put("https://www.googleapis.com/calendar/v3/calendars/{$this->calendarId}/events/{$event->google_event_id}", $eventData);
            } else {
                $response = Http::withToken($this->accessToken)
                    ->post("https://www.googleapis.com/calendar/v3/calendars/{$this->calendarId}/events", $eventData);
            }

            if ($response->successful()) {
                $data = $response->json();
                $event->update(['google_event_id' => $data['id']]);
                return $data;
            }

            Log::error('Google Calendar sync failed', [
                'event_id' => $event->id,
                'response' => $response->json(),
            ]);
        } catch (\Exception $e) {
            Log::error('Google Calendar sync error', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Sync Task deadline to Google Calendar
     */
    public function syncTask(Task $task): ?array
    {
        if (!$this->isConfigured() || empty($task->selesai_task)) {
            return null;
        }

        try {
            $this->refreshAccessToken();

            $deadline = Carbon::parse($task->selesai_task);

            $eventData = [
                'summary' => '[Task] ' . $task->nama_task,
                'description' => "Task deadline\nProject: {$task->project?->nama_project}\nLink: " . config('app.url') . "/admin/tasks",
                'start' => [
                    'dateTime' => $deadline->startOfDay()->addHours(9)->toIso8601String(), // 9 AM
                    'timeZone' => 'Asia/Jakarta',
                ],
                'end' => [
                    'dateTime' => $deadline->startOfDay()->addHours(10)->toIso8601String(),
                    'timeZone' => 'Asia/Jakarta',
                ],
                'colorId' => '11', // Red
                'reminders' => [
                    'useDefault' => false,
                    'overrides' => [
                        ['method' => 'popup', 'minutes' => 24 * 60], // 1 day
                        ['method' => 'popup', 'minutes' => 60], // 1 hour
                    ],
                ],
            ];

            $response = Http::withToken($this->accessToken)
                ->post("https://www.googleapis.com/calendar/v3/calendars/{$this->calendarId}/events", $eventData);

            if ($response->successful()) {
                $data = $response->json();
                $task->update(['google_event_id' => $data['id']]);
                return $data;
            }
        } catch (\Exception $e) {
            Log::error('Google Calendar task sync error', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Fetch events from Google Calendar (two-way sync)
     */
    public function fetchEvents(Carbon $start, Carbon $end): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            $this->refreshAccessToken();

            $response = Http::withToken($this->accessToken)
                ->get("https://www.googleapis.com/calendar/v3/calendars/{$this->calendarId}/events", [
                    'timeMin' => $start->toIso8601String(),
                    'timeMax' => $end->toIso8601String(),
                    'singleEvents' => true,
                    'orderBy' => 'startTime',
                ]);

            if ($response->successful()) {
                return $response->json()['items'] ?? [];
            }
        } catch (\Exception $e) {
            Log::error('Google Calendar fetch error', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Delete event from Google Calendar
     */
    public function deleteEvent(string $googleEventId): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $this->refreshAccessToken();

            $response = Http::withToken($this->accessToken)
                ->delete("https://www.googleapis.com/calendar/v3/calendars/{$this->calendarId}/events/{$googleEventId}");

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Google Calendar delete error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get color ID based on category
     */
    private function getCategoryColor(string $category): string
    {
        $colors = [
            'shooting_visit' => '1', // Lavender
            'shooting_studio' => '2', // Sage
            'meeting' => '4', // Flamingo
            'agenda_digitalk' => '5', // Banana
            'libur' => '11', // Sage
            'default' => '7', // Peacock
        ];

        return $colors[$category] ?? $colors['default'];
    }

    /**
     * OAuth URL for authorization
     */
    public static function getAuthUrl(): string
    {
        $params = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    /**
     * Exchange code for tokens
     */
    public static function exchangeCodeForTokens(string $code): ?array
    {
        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'code' => $code,
                'redirect_uri' => config('services.google.redirect_uri'),
                'grant_type' => 'authorization_code',
            ]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::error('Google OAuth exchange failed', ['error' => $e->getMessage()]);
        }

        return null;
    }
}