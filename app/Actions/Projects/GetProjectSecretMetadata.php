<?php

namespace App\Actions\Projects;

use App\Contracts\ProjectSecretStore;
use App\Data\SecretMetadata;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class GetProjectSecretMetadata
{
    public function __construct(private ProjectSecretStore $secrets) {}

    public function handle(Project $project, User $user): SecretMetadata
    {
        if (! $user->belongsToTeam($project->team)) {
            throw new AuthorizationException;
        }

        return $this->secrets->metadata($project);
    }
}
