<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $brand_name;
    public ?string $brand_logo;
    public string $brand_logoHeight;
    public bool $site_active;
    public ?string $site_favicon;
    public array $site_theme;

    // Event categories with colors
    public array $event_categories = [];

    public static function group(): string
    {
        return 'general';
    }

    public static function defaults(): array
    {
        return [
            'event_categories' => [
                ['color' => '#3bbeff', 'label' => 'Shooting Visit'],
                ['color' => '#347b9e', 'label' => 'Shooting Studio'],
                ['color' => '#ff5915', 'label' => 'Agenda Digitalk'],
                ['color' => '#fcc530', 'label' => 'Meeting'],
                ['color' => '#e53935', 'label' => 'Libur'],
            ],
        ];
    }

    public function getEventCategoryLabel(string $color): string
    {
        foreach ($this->event_categories as $cat) {
            if ($cat['color'] === $color) {
                return $cat['label'];
            }
        }
        return 'Agenda';
    }

    public function getEventCategoryOptions(): array
    {
        $options = [];
        foreach ($this->event_categories as $cat) {
            $options[$cat['color']] = $cat['label'];
        }
        return $options;
    }

    public function getEventCategoryLabels(): array
    {
        $labels = [];
        foreach ($this->event_categories as $cat) {
            $labels[$cat['color']] = $cat['label'];
        }
        return $labels;
    }
}
