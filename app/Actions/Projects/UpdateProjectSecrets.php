<?php

namespace App\Actions\Projects;

use App\Contracts\ProjectSecretStore;
use App\Data\SecretDocument;
use App\Models\Project;
use App\Models\ProjectSecretRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class UpdateProjectSecrets
{
    public function __construct(private ProjectSecretStore $secrets) {}

    /** @param array<array-key, mixed> $values */
    public function handle(Project $project, User $user, array $values, int $expectedVersion): SecretDocument
    {
        if (! $user->belongsToTeam($project->team)) {
            throw new AuthorizationException;
        }

        if ($expectedVersion < 0 || count($values) > 200) {
            throw ValidationException::withMessages([
                'secretJson' => __('The secret document is invalid.'),
            ]);
        }

        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) !== 1 || ! is_string($value)) {
                throw ValidationException::withMessages([
                    'secretJson' => __('Secrets must be a flat JSON object with environment variable names and string values.'),
                ]);
            }

            $normalized[$key] = $value;
        }

        ksort($normalized);
        $document = $this->secrets->write($project, $normalized, $expectedVersion);

        ProjectSecretRevision::query()->updateOrCreate(
            ['project_id' => $project->id, 'vault_version' => $document->version],
            ['updated_by' => $user->id],
        );

        return $document;
    }
}
