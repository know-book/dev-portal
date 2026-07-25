<?php

namespace Database\Factories;

use App\Models\ProjectManifest;
use App\Models\ProjectManifestRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectManifestRevision>
 */
class ProjectManifestRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_manifest_id' => ProjectManifest::factory(),
            'revision_number' => 1,
            'patch_snapshot' => [],
            'compiled_hash' => hash('sha256', 'compiled'),
            'status' => ProjectManifestRevision::StatusDraft,
        ];
    }
}
