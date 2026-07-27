<?php

namespace App\Contracts;

use App\Data\SecretDocument;
use App\Models\Project;

interface ProjectSecretStore
{
    public function read(Project $project): SecretDocument;

    /** @param array<string, string> $values */
    public function write(Project $project, array $values, int $expectedVersion): SecretDocument;
}
