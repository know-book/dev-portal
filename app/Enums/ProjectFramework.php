<?php

namespace App\Enums;

enum ProjectFramework: string
{
    case Laravel = 'laravel';
    case NextJs = 'nextjs';
    case Other = 'other';

    /**
     * Get the display label for the framework.
     */
    public function label(): string
    {
        return match ($this) {
            self::Laravel => 'Laravel',
            self::NextJs => 'Next.js',
            self::Other => 'Other',
        };
    }
}
