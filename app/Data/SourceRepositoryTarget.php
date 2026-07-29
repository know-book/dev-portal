<?php

namespace App\Data;

use InvalidArgumentException;

readonly class SourceRepositoryTarget
{
    public function __construct(
        public string $installationId,
        public string $repository,
        public string $branch,
        public string $markerPath,
    ) {}

    public function filePath(string $path): string
    {
        $path = trim($path, '/');

        if ($path === '' || str_contains($path, '\\') || str_contains('/'.$path.'/', '/../')) {
            throw new InvalidArgumentException('Source repository file paths must be safe relative paths.');
        }

        return $path;
    }
}
