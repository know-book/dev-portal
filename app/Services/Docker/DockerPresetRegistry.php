<?php

namespace App\Services\Docker;

use App\Enums\GitOpsRepositoryMode;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DockerPresetRegistry
{
    /**
     * @var array<string, array{label: string, description: string, images: list<string>}>
     */
    private const Presets = [
        'laravel' => [
            'label' => 'Laravel',
            'description' => 'PHP-FPM and Nginx production images with Composer and frontend asset builds.',
            'images' => ['app', 'nginx'],
        ],
        'nextjs' => [
            'label' => 'Next.js',
            'description' => 'A multi-stage Node.js production image that supports npm, Yarn, and pnpm lockfiles.',
            'images' => ['web'],
        ],
    ];

    public function __construct(private Filesystem $files) {}

    /**
     * @return array<string, array{label: string, description: string, images: list<string>}>
     */
    public function presets(): array
    {
        return self::Presets;
    }

    /**
     * @return array<string, string>
     */
    public function filesForProject(Project $project, string $preset): array
    {
        if (! array_key_exists($preset, self::Presets)) {
            throw new InvalidArgumentException("Docker preset [{$preset}] does not exist.");
        }

        $root = resource_path("docker/presets/{$preset}/v1");

        if (! $this->files->isDirectory($root)) {
            throw new InvalidArgumentException("Docker preset [{$preset}:v1] does not exist.");
        }

        $variables = $this->variablesForProject($project);
        $renderedFiles = [];

        foreach ($this->files->allFiles($root, true) as $file) {
            $content = $this->files->get($file->getPathname());

            foreach ($variables as $key => $value) {
                $content = Str::replace(["{{ {$key} }}", "{{{$key}}}"], $value, $content);
            }

            $renderedFiles[$file->getRelativePathname()] = $content;
        }

        ksort($renderedFiles);

        return $renderedFiles;
    }

    /**
     * @return array<string, string>
     */
    protected function variablesForProject(Project $project): array
    {
        if (blank($project->repository)) {
            throw new InvalidArgumentException('The project source repository is not configured.');
        }

        return [
            'default_branch' => $project->default_branch,
            'gitops_path' => trim($project->gitops_path, '/'),
            'image_repository' => 'ghcr.io/'.Str::lower($project->repository),
            'update_gitops' => $project->gitops_repository_mode === GitOpsRepositoryMode::CoLocated ? 'true' : 'false',
        ];
    }
}
