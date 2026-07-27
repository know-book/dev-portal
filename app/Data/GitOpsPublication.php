<?php

namespace App\Data;

readonly class GitOpsPublication
{
    public function __construct(
        public bool $changed,
        public string $commitSha,
    ) {}
}
