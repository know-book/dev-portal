<?php

namespace App\Enums;

enum GitOpsRepositoryMode: string
{
    case CoLocated = 'co_located';

    case Separate = 'separate';

    public function label(): string
    {
        return match ($this) {
            self::CoLocated => 'Same repository',
            self::Separate => 'Separate GitOps repository',
        };
    }
}
