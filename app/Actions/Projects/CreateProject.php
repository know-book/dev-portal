<?php

namespace App\Actions\Projects;

use App\Jobs\InitializeProjectManifests;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class CreateProject
{
    /**
     * @param  array{name: string, framework: string, github_installation_id?: int|null, repository?: string|null, repository_id?: string|null, default_branch?: string|null, description?: string|null}  $attributes
     */
    public function handle(Team $team, array $attributes): Project
    {
        return DB::transaction(function () use ($team, $attributes): Project {
            $project = $team->projects()->create([
                'name' => $attributes['name'],
                'framework' => $attributes['framework'],
                'github_installation_id' => $attributes['github_installation_id'] ?? null,
                'repository' => $attributes['repository'] ?? null,
                'repository_id' => $attributes['repository_id'] ?? null,
                'default_branch' => $attributes['default_branch'] ?? 'main',
                'description' => $attributes['description'] ?? null,
                'initialization_status' => Project::InitializationPending,
            ]);

            InitializeProjectManifests::dispatch($project)->afterCommit();

            return $project;
        });
    }
}
