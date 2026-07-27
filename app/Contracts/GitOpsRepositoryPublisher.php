<?php

namespace App\Contracts;

use App\Data\GitOpsPublication;
use App\Data\GitOpsTarget;

interface GitOpsRepositoryPublisher
{
    /**
     * @param  array<string, string>  $files
     */
    public function publish(GitOpsTarget $target, array $files, string $commitMessage): GitOpsPublication;
}
