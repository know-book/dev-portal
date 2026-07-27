<?php

namespace App\Enums;

enum GitOpsPublishMode: string
{
    case Direct = 'direct';

    case PullRequest = 'pull_request';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct commit',
            self::PullRequest => 'Pull request',
        };
    }
}
