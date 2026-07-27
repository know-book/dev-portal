<?php

namespace App\Actions\Projects;

use App\Contracts\ArgoApplicationGateway;
use App\Contracts\ProjectSecretStore;
use App\Data\ArgoApplicationStatus;
use App\Models\Project;
use App\Models\ProjectManifestRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ReconcileProjectArgoApplication
{
    public function __construct(
        private ArgoApplicationGateway $argo,
        private ProjectSecretStore $secrets,
    ) {}

    public function handle(Project $project, User $user): ArgoApplicationStatus
    {
        if (! $user->belongsToTeam($project->team)) {
            throw new AuthorizationException;
        }

        $project->loadMissing('manifest');
        $hasPublishedManifest = $project->manifest?->revisions()
            ->where('status', ProjectManifestRevision::StatusPublished)
            ->exists() ?? false;

        if (! $hasPublishedManifest) {
            throw ValidationException::withMessages([
                'argo' => __('Publish the project manifests to Git before creating the Argo CD Application.'),
            ]);
        }

        if (! $this->secrets->metadata($project)->exists) {
            throw ValidationException::withMessages([
                'argo' => __('Create the project secret in Vault before creating the Argo CD Application.'),
            ]);
        }

        return $this->argo->reconcile($project);
    }
}
