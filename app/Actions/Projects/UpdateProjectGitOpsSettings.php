<?php

namespace App\Actions\Projects;

use App\Enums\GitOpsPublishMode;
use App\Enums\GitOpsRepositoryMode;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use App\Services\GitHub\GitHubAppService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class UpdateProjectGitOpsSettings
{
    public function __construct(private GitHubAppService $gitHub) {}

    /**
     * @param  array{repository_mode: string, installation_id?: int|null, repository_id?: string|null, branch?: string|null, path: string, publish_mode: string}  $attributes
     */
    public function handle(Project $project, User $user, array $attributes): Project
    {
        if (! $user->belongsToTeam($project->team)) {
            throw new AuthorizationException;
        }

        $repositoryMode = GitOpsRepositoryMode::from($attributes['repository_mode']);
        $publishMode = GitOpsPublishMode::from($attributes['publish_mode']);
        $path = trim($attributes['path'], '/');

        if ($repositoryMode === GitOpsRepositoryMode::CoLocated) {
            return $this->configureCoLocated($project, $path, $publishMode, $attributes['branch'] ?? null);
        }

        return $this->configureSeparate($project, $attributes, $path, $publishMode);
    }

    protected function configureCoLocated(Project $project, string $path, GitOpsPublishMode $publishMode, ?string $branch): Project
    {
        $installation = $project->githubInstallation;

        if (! $installation || blank($project->repository_id)) {
            throw ValidationException::withMessages([
                'gitOpsRepositoryMode' => __('Connect the source repository through a GitHub App installation first.'),
            ]);
        }

        $repository = $this->writableRepository($installation, (string) $project->repository_id, 'gitOpsRepositoryMode');

        $project->update([
            'repository' => $repository['full_name'],
            'gitops_repository_mode' => GitOpsRepositoryMode::CoLocated,
            'gitops_github_installation_id' => null,
            'gitops_repository' => null,
            'gitops_repository_id' => null,
            'gitops_branch' => $branch ?: $repository['default_branch'],
            'gitops_path' => $path,
            'gitops_publish_mode' => $publishMode,
        ]);

        return $project->refresh();
    }

    /**
     * @param  array{repository_mode: string, installation_id?: int|null, repository_id?: string|null, branch?: string|null, path: string, publish_mode: string}  $attributes
     */
    protected function configureSeparate(Project $project, array $attributes, string $path, GitOpsPublishMode $publishMode): Project
    {
        $sourceInstallation = $project->githubInstallation;

        if (! $sourceInstallation || blank($project->repository_id)) {
            throw ValidationException::withMessages([
                'gitOpsRepositoryMode' => __('Connect the source repository through a GitHub App installation first.'),
            ]);
        }

        $sourceRepository = $this->writableRepository($sourceInstallation, (string) $project->repository_id, 'gitOpsRepositoryMode');
        $installation = $project->team->githubInstallations()->find($attributes['installation_id'] ?? null);

        if (! $installation) {
            throw ValidationException::withMessages([
                'gitOpsInstallationId' => __('Select a GitHub installation connected to this team.'),
            ]);
        }

        $repositoryId = (string) ($attributes['repository_id'] ?? '');
        $repository = $this->writableRepository($installation, $repositoryId, 'gitOpsRepositoryId');

        $project->update([
            'repository' => $sourceRepository['full_name'],
            'gitops_repository_mode' => GitOpsRepositoryMode::Separate,
            'gitops_github_installation_id' => $installation->id,
            'gitops_repository' => $repository['full_name'],
            'gitops_repository_id' => (string) $repository['id'],
            'gitops_branch' => ($attributes['branch'] ?? null) ?: $repository['default_branch'],
            'gitops_path' => $path,
            'gitops_publish_mode' => $publishMode,
        ]);

        return $project->refresh();
    }

    /**
     * @return array{id: int, name: string, full_name: string, default_branch: string, html_url: string, permissions?: array<string, bool>}
     */
    protected function writableRepository(GitHubInstallation $installation, string $repositoryId, string $errorKey): array
    {
        if (! $installation->canWriteRepositoryContents()) {
            throw ValidationException::withMessages([
                $errorKey => __('The GitHub App installation requires Contents: Read and write permission.'),
            ]);
        }

        $repository = $this->gitHub->findInstallationRepository($installation, $repositoryId);

        if (! $repository) {
            throw ValidationException::withMessages([
                $errorKey => __('The selected repository is no longer accessible to this GitHub App installation.'),
            ]);
        }

        return $repository;
    }
}
