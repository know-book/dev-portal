<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectManifest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectManifest>
 */
class ProjectManifestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'preset_key' => 'laravel',
            'preset_version' => 'v1',
            'variables' => [
                'project_slug' => 'demo-app',
                'namespace' => 'demo-app',
                'domain' => 'demo-app.example.test',
                'image_repository' => 'ghcr.io/example/demo-app',
                'image_tag' => 'latest',
            ],
            'base_hash' => hash('sha256', 'demo-app'),
            'lock_version' => 1,
        ];
    }
}
