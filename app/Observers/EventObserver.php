<?php

namespace App\Observers;

use App\Models\Event;
use App\Models\Task;
use App\Services\GoogleCalendarService;

class EventObserver
{
    public function created(Event $event): void
    {
        // Auto-sync to Google Calendar
        if (app(GoogleCalendarService::class)->isConfigured()) {
            try {
                app(GoogleCalendarService::class)->syncEvent($event);
            } catch (\Exception $e) {
                \Log::error('Google Calendar sync failed on create', [
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function updated(Event $event): void
    {
        // Sync update to Google Calendar
        if ($event->google_event_id && app(GoogleCalendarService::class)->isConfigured()) {
            try {
                app(GoogleCalendarService::class)->syncEvent($event);
            } catch (\Exception $e) {
                \Log::error('Google Calendar sync failed on update', [
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function deleted(Event $event): void
    {
        // Delete from Google Calendar
        if ($event->google_event_id && app(GoogleCalendarService::class)->isConfigured()) {
            try {
                app(GoogleCalendarService::class)->deleteEvent($event->google_event_id);
            } catch (\Exception $e) {
                \Log::error('Google Calendar delete failed', [
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}