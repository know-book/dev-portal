<?php

namespace App\Services\Manifests;

use App\Enums\ProjectFramework;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ManifestPresetRegistry
{
    public function __construct(private Filesystem $files) {}

    /**
     * @return array{key: string, version: string}
     */
    public function presetForProject(Project $project): array
    {
        return [
            'key' => match ($project->framework) {
                ProjectFramework::Laravel => 'laravel',
                ProjectFramework::NextJs => 'nextjs',
                ProjectFramework::Other => 'docker',
            },
            'version' => 'v1',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function variablesForProject(Project $project): array
    {
        $project->loadMissing('team');

        $namespace = $this->kubernetesName($project->team->slug.'-'.$project->slug);
        $imageRepository = $project->repository
            ? 'ghcr.io/'.Str::lower($project->repository)
            : 'ghcr.io/'.$this->kubernetesName($project->team->slug).'/'.$this->kubernetesName($project->slug);

        return [
            'project_name' => $project->name,
            'project_slug' => $this->kubernetesName($project->slug),
            'team_slug' => $this->kubernetesName($project->team->slug),
            'namespace' => $namespace,
            'domain' => $project->slug.'.example.test',
            'image_repository' => $imageRepository,
            'image_tag' => 'latest',
            'secret_name' => 'app-secrets',
            'secret_store_name' => 'platform-vault',
            'secret_store_kind' => 'ClusterSecretStore',
            'secret_key' => $project->team->slug.'/'.$project->slug.'/app',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function filesForProject(Project $project): array
    {
        $preset = $this->presetForProject($project);

        return $this->renderPreset($preset['key'], $preset['version'], $this->variablesForProject($project));
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    public function renderPreset(string $presetKey, string $presetVersion, array $variables): array
    {
        $root = resource_path("manifests/presets/{$presetKey}/{$presetVersion}");

        if (! $this->files->isDirectory($root)) {
            throw new InvalidArgumentException("Manifest preset [{$presetKey}:{$presetVersion}] does not exist.");
        }

        $renderedFiles = [];

        foreach ($this->files->allFiles($root) as $file) {
            $relativePath = $file->getRelativePathname();
            $content = $this->files->get($file->getPathname());

            foreach ($variables as $key => $value) {
                $content = Str::replace(["{{ {$key} }}", "{{{$key}}}"], $value, $content);
            }

            $renderedFiles[$relativePath] = $content;
        }

        ksort($renderedFiles);

        return $renderedFiles;
    }

    /**
     * @param  array<string, string>  $files
     */
    public function hashFiles(array $files): string
    {
        ksort($files);

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }

    protected function kubernetesName(string $value): string
    {
        $name = Str::slug(Str::lower($value)) ?: 'app';
        $name = rtrim(Str::limit($name, 63, ''), '-');

        return $name !== '' ? $name : 'app';
    }
}
