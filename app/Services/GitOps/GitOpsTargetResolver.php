<?php

namespace App\Services\GitOps;

use App\Data\GitOpsTarget;
use App\Enums\GitOpsRepositoryMode;
use App\Exceptions\GitOpsRepositoryException;
use App\Models\Project;

class GitOpsTargetResolver
{
    public function resolve(Project $project): GitOpsTarget
    {
        $project->loadMissing(['githubInstallation', 'gitOpsGitHubInstallation']);

        $installation = $project->gitops_repository_mode === GitOpsRepositoryMode::Separate
            ? $project->gitOpsGitHubInstallation
            : $project->githubInstallation;

        $repository = $project->gitops_repository_mode === GitOpsRepositoryMode::Separate
            ? $project->gitops_repository
            : $project->repository;

        $branch = $project->gitops_branch ?: $project->default_branch;

        if (! $installation || $installation->team_id !== $project->team_id) {
            throw new GitOpsRepositoryException('The GitOps repository is not connected through this team.');
        }

        if (! $installation->canWriteRepositoryContents()) {
            throw new GitOpsRepositoryException('The GitHub App requires Contents: Read and write permission.');
        }

        if (blank($repository) || blank($branch) || blank($project->gitops_path)) {
            throw new GitOpsRepositoryException('The GitOps repository target is incomplete.');
        }

        return new GitOpsTarget(
            installationId: $installation->installation_id,
            repository: (string) $repository,
            branch: $branch,
            path: $project->gitops_path,
            publishMode: $project->gitops_publish_mode,
        );
    }
}
