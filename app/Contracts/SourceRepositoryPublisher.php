<?php

namespace App\Contracts;

use App\Data\GitOpsPublication;
use App\Data\SourceRepositoryTarget;

interface SourceRepositoryPublisher
{
    /**
     * @param  array<string, string>  $files
     */
    public function publish(SourceRepositoryTarget $target, array $files, string $commitMessage): GitOpsPublication;
}
