<?php

namespace App\Actions\Projects;

use App\Contracts\SourceRepositoryPublisher;
use App\Data\GitOpsPublication;
use App\Data\SourceRepositoryTarget;
use App\Exceptions\SourceRepositoryException;
use App\Models\Project;
use App\Models\User;
use App\Services\Docker\DockerPresetRegistry;
use Illuminate\Auth\Access\AuthorizationException;

class ImportProjectDockerTemplate
{
    public function __construct(
        private DockerPresetRegistry $presets,
        private SourceRepositoryPublisher $publisher,
    ) {}

    public function handle(Project $project, User $user, string $preset): GitOpsPublication
    {
        if (! $user->belongsToTeam($project->team)) {
            throw new AuthorizationException;
        }

        $project->loadMissing('githubInstallation');
        $installation = $project->githubInstallation;

        if (! $installation || $installation->team_id !== $project->team_id) {
            throw new SourceRepositoryException('The source repository is not connected through this team.');
        }

        if (! $installation->canWriteRepositoryContents()) {
            throw new SourceRepositoryException('The GitHub App requires Contents: Read and write permission.');
        }

        if (! $installation->canWriteRepositoryWorkflows()) {
            throw new SourceRepositoryException('The GitHub App requires Workflows: Read and write permission to import GitHub Actions.');
        }

        if (blank($project->repository) || blank($project->default_branch)) {
            throw new SourceRepositoryException('The source repository target is incomplete.');
        }

        $files = $this->presets->filesForProject($project, $preset);
        $managedFiles = array_keys($files);
        sort($managedFiles);
        $markerPath = '.devportal/docker.json';
        $files[$markerPath] = json_encode([
            'schema_version' => 1,
            'managed_by' => 'dev-portal',
            'project' => $project->slug,
            'preset' => $preset.'/v1',
            'managed_files' => $managedFiles,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        return $this->publisher->publish(
            new SourceRepositoryTarget(
                installationId: $installation->installation_id,
                repository: $project->repository,
                branch: $project->default_branch,
                markerPath: $markerPath,
            ),
            $files,
            "chore(ci): import {$preset} Docker build",
        );
    }
}
