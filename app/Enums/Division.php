<?php

namespace App\Enums;

enum Division: string
{
    case SocialMediaSpecialist = 'Social Media Specialist';
    case Finance = 'Finance';
    case HR = 'HR';
    case GraphicDesign = 'Graphic Design';
    case VideoEditor = 'Video Editor';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}