<?php

namespace App\Jobs;

use App\Enums\GitOpsRepositoryMode;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Services\Kubernetes\K8sBuildEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessGitHubWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $event,
        public array $payload
    ) {}

    /**
     * Execute the job.
     */
    public function handle(K8sBuildEngineService $buildEngine): void
    {
        Log::info("Processing GitHub Webhook Event: {$this->event}");

        match ($this->event) {
            'installation', 'installation_repositories' => $this->handleInstallationEvent(),
            'push' => $this->handlePushEvent($buildEngine),
            'pull_request' => $this->handlePullRequestEvent($buildEngine),
            default => Log::info("Unhandled GitHub webhook event: {$this->event}"),
        };
    }

    /**
     * Handle installation created or repositories updated.
     */
    protected function handleInstallationEvent(): void
    {
        $action = $this->payload['action'] ?? null;
        $installationData = $this->payload['installation'] ?? [];
        $installationId = (string) ($installationData['id'] ?? '');

        if (empty($installationId)) {
            return;
        }

        if ($action === 'deleted') {
            GitHubInstallation::where('installation_id', $installationId)->delete();
            Log::info("GitHub installation deleted: {$installationId}");

            return;
        }

        $account = $installationData['account'] ?? [];
        $accountName = $account['login'] ?? 'Unknown';
        $avatarUrl = $account['avatar_url'] ?? null;
        $accountType = $account['type'] ?? 'User';

        GitHubInstallation::updateOrCreate(
            ['installation_id' => $installationId],
            [
                'account_name' => $accountName,
                'account_avatar_url' => $avatarUrl,
                'account_type' => $accountType,
                'permissions' => $installationData['permissions'] ?? [],
            ]
        );

        Log::info("GitHub installation synced for account: {$accountName}");
    }

    /**
     * Handle push events for automatic builds.
     */
    protected function handlePushEvent(K8sBuildEngineService $buildEngine): void
    {
        $repoFullName = $this->payload['repository']['full_name'] ?? null;
        $ref = $this->payload['ref'] ?? '';
        $branch = str_replace('refs/heads/', '', $ref);

        if (empty($repoFullName)) {
            return;
        }

        $projects = Project::where('repository', $repoFullName)
            ->where('default_branch', $branch)
            ->where('auto_deploy', true)
            ->get();

        foreach ($projects as $project) {
            if ($this->isManifestOnlyPush($project)) {
                Log::info("Skipping build for manifest-only GitOps commit on project [{$project->name}]");

                continue;
            }

            Log::info("Dispatching K8s Build Job for project [{$project->name}] (Branch: {$branch})");
            $buildEngine->dispatchBuildJob($project, $branch, $this->payload['after'] ?? 'head');
        }
    }

    protected function isManifestOnlyPush(Project $project): bool
    {
        if ($project->gitops_repository_mode !== GitOpsRepositoryMode::CoLocated) {
            return false;
        }

        $commits = $this->payload['commits'] ?? [];

        if (! is_array($commits)) {
            return false;
        }

        $changedPaths = [];

        foreach ($commits as $commit) {
            if (! is_array($commit)) {
                continue;
            }

            foreach (['added', 'modified', 'removed'] as $changeType) {
                $paths = $commit[$changeType] ?? [];

                if (! is_array($paths)) {
                    continue;
                }

                foreach ($paths as $path) {
                    if (is_string($path) && $path !== '') {
                        $changedPaths[$path] = true;
                    }
                }
            }
        }

        if ($changedPaths === []) {
            return false;
        }

        $manifestPath = trim($project->gitops_path, '/');

        foreach (array_keys($changedPaths) as $path) {
            if ($path !== $manifestPath && ! str_starts_with($path, $manifestPath.'/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Handle pull request events for preview deployments.
     */
    protected function handlePullRequestEvent(K8sBuildEngineService $buildEngine): void
    {
        $action = $this->payload['action'] ?? null;
        $prNumber = $this->payload['number'] ?? null;
        $repoFullName = $this->payload['repository']['full_name'] ?? null;

        if (! $prNumber || ! $repoFullName) {
            return;
        }

        $project = Project::where('repository', $repoFullName)->first();

        if (! $project) {
            return;
        }

        if (in_array($action, ['opened', 'synchronize', 'reopened'])) {
            Log::info("Creating Ephemeral Preview Deployment for PR #{$prNumber} in [{$project->name}]");
            $buildEngine->dispatchPreviewBuildJob($project, (int) $prNumber);
        } elseif ($action === 'closed') {
            Log::info("Tearing down Ephemeral Preview Deployment for PR #{$prNumber} in [{$project->name}]");
            $buildEngine->teardownPreviewDeployment($project, (int) $prNumber);
        }
    }
}
