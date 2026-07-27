<?php

namespace App\Data;

use App\Enums\GitOpsPublishMode;
use InvalidArgumentException;

readonly class GitOpsTarget
{
    public function __construct(
        public string $installationId,
        public string $repository,
        public string $branch,
        public string $path,
        public GitOpsPublishMode $publishMode,
    ) {}

    public function filePath(string $relativePath): string
    {
        $relativePath = trim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, '..') || str_contains($relativePath, '\\')) {
            throw new InvalidArgumentException('GitOps file paths must be safe relative paths.');
        }

        return trim($this->path, '/').'/'.$relativePath;
    }
}
