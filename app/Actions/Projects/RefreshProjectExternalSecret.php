<?php

namespace App\Actions\Projects;

use App\Data\ExternalSecretStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\Kubernetes\KubernetesExternalSecretClient;
use Illuminate\Auth\Access\AuthorizationException;

class RefreshProjectExternalSecret
{
    public function __construct(private KubernetesExternalSecretClient $externalSecrets) {}

    public function handle(Project $project, User $user): ExternalSecretStatus
    {
        if (! $user->belongsToTeam($project->team)) {
            throw new AuthorizationException;
        }

        return $this->externalSecrets->refresh($project);
    }
}
