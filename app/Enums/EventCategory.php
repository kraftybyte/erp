<?php

namespace App\Enums;

enum EventCategory: string
{
    case SHOOTING_VISIT = '#3bbeff';
    case SHOOTING_STUDIO = '#347b9e';
    case AGENDA_DIGITALK = '#ff5915';
    case MEETING = '#fcc530';
    case LIBUR = '#e53935';

    public function label(): string
    {
        return match ($this) {
            self::SHOOTING_VISIT => 'Shooting Visit',
            self::SHOOTING_STUDIO => 'Shooting Studio',
            self::AGENDA_DIGITALK => 'Agenda Digitalk',
            self::MEETING => 'Meeting',
            self::LIBUR => 'Libur',
        };
    }

    public function color(): string
    {
        return $this->value;
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }

    public static function labels(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }

    public static function label(string $color): string
    {
        return self::labels()[$color] ?? 'Agenda';
    }
}