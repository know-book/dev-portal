<?php

namespace App\Services\Kubernetes;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

class K8sBuildEngineService
{
    /**
     * Dispatch a Kubernetes Build Job for main branch deployment.
     */
    public function dispatchBuildJob(Project $project, string $branch, string $commitSha): array
    {
        $jobName = "build-{$project->slug}-".substr($commitSha, 0, 7);

        Log::info('Generating K8s Build Job Manifest', [
            'job_name' => $jobName,
            'project' => $project->name,
            'framework' => $project->framework->value,
            'repository' => $project->repository,
            'branch' => $branch,
            'commit_sha' => $commitSha,
        ]);

        // Returns simulated Kubernetes Job spec / status
        return [
            'status' => 'queued',
            'job_name' => $jobName,
            'namespace' => "team-{$project->team->slug}",
            'image' => "registry.devportal.local/{$project->team->slug}/{$project->slug}:{$commitSha}",
            'builder' => $project->framework->value === 'laravel' ? 'nixpacks' : 'dockerfile',
        ];
    }

    /**
     * Dispatch a Kubernetes Build Job for Ephemeral PR Preview deployment.
     */
    public function dispatchPreviewBuildJob(Project $project, int $prNumber): array
    {
        $jobName = "preview-{$project->slug}-pr-{$prNumber}";

        Log::info('Generating K8s Preview Build Job', [
            'job_name' => $jobName,
            'project' => $project->name,
            'pr_number' => $prNumber,
            'preview_url' => "pr-{$prNumber}-{$project->slug}.preview.devportal.local",
        ]);

        return [
            'status' => 'queued',
            'job_name' => $jobName,
            'pr_number' => $prNumber,
            'preview_url' => "pr-{$prNumber}-{$project->slug}.preview.devportal.local",
        ];
    }

    /**
     * Teardown an Ephemeral PR Preview deployment.
     */
    public function teardownPreviewDeployment(Project $project, int $prNumber): bool
    {
        Log::info('Tearing down Ephemeral Preview Deployment', [
            'project' => $project->name,
            'pr_number' => $prNumber,
        ]);

        return true;
    }
}
