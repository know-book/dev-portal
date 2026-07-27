<?php

namespace App\Data;

readonly class SecretMetadata
{
    public function __construct(
        public bool $exists,
        public int $version,
    ) {}
}
