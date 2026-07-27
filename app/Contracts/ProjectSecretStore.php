<?php

namespace App\Contracts;

use App\Data\SecretDocument;
use App\Data\SecretMetadata;
use App\Models\Project;

interface ProjectSecretStore
{
    public function read(Project $project): SecretDocument;

    public function metadata(Project $project): SecretMetadata;

    /** @param array<string, string> $values */
    public function write(Project $project, array $values, int $expectedVersion): SecretDocument;
}
