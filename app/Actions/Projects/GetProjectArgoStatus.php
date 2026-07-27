<?php

namespace App\Actions\Projects;

use App\Contracts\ArgoApplicationGateway;
use App\Data\ArgoApplicationStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class GetProjectArgoStatus
{
    public function __construct(private ArgoApplicationGateway $argo) {}

    public function handle(Project $project, User $user, bool $hardRefresh = false): ?ArgoApplicationStatus
    {
        if (! $user->belongsToTeam($project->team)) {
            throw new AuthorizationException;
        }

        return $this->argo->status($project, $hardRefresh);
    }
}
