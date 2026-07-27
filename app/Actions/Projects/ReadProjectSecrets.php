<?php

namespace App\Actions\Projects;

use App\Contracts\ProjectSecretStore;
use App\Data\SecretDocument;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ReadProjectSecrets
{
    public function __construct(private ProjectSecretStore $secrets) {}

    public function handle(Project $project, User $user): SecretDocument
    {
        if (! $user->belongsToTeam($project->team)) {
            throw new AuthorizationException;
        }

        return $this->secrets->read($project);
    }
}
