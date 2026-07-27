<?php

namespace App\Data;

readonly class ExternalSecretStatus
{
    public function __construct(
        public bool $exists,
        public bool $ready,
        public ?string $reason = null,
        public ?string $message = null,
        public ?string $refreshTime = null,
    ) {}
}
