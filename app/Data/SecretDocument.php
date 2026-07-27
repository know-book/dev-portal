<?php

namespace App\Data;

readonly class SecretDocument
{
    /** @param array<string, string> $values */
    public function __construct(
        public array $values,
        public int $version,
    ) {}
}
