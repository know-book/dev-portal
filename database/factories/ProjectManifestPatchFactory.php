<?php

namespace Database\Factories;

use App\Models\ProjectManifest;
use App\Models\ProjectManifestPatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectManifestPatch>
 */
class ProjectManifestPatchFactory extends Factory
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
            'path' => 'kustomization.yaml',
            'operation' => ProjectManifestPatch::OperationReplace,
            'content' => "apiVersion: kustomize.config.k8s.io/v1beta1\nkind: Kustomization\n",
            'base_content_hash' => hash('sha256', 'base'),
        ];
    }
}
