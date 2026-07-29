<?php

namespace App\Actions\Projects;

use App\Data\KubernetesDeploymentStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\Kubernetes\KubernetesDeploymentClient;
use Illuminate\Auth\Access\AuthorizationException;

class GetProjectDeploymentStatus
{
    public function __construct(private KubernetesDeploymentClient $deployments) {}

    /** @return list<KubernetesDeploymentStatus> */
    public function handle(Project $project, User $user): array
    {
        if (! $user->belongsToTeam($project->team)) {
            throw new AuthorizationException;
        }

        return $this->deployments->status($project);
    }
}
