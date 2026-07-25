<?php

use App\Actions\Projects\CreateProject;
use App\Enums\ProjectFramework;
use App\Jobs\InitializeProjectManifests;
use App\Models\Project;
use App\Models\User;
use App\Services\Manifests\ManifestCompiler;
use App\Services\Manifests\ManifestPresetRegistry;
use Symfony\Component\Yaml\Yaml;

test('project creation initializes a laravel manifest workspace', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $project = app(CreateProject::class)->handle($team, [
        'name' => 'My Laravel App',
        'framework' => ProjectFramework::Laravel->value,
        'repository' => 'myorg/my-laravel-app',
        'repository_id' => '987654',
        'default_branch' => 'main',
        'description' => 'A deployable Laravel application',
    ])->refresh();

    expect($project->initialization_status)->toBe(Project::InitializationReady)
        ->and($project->initialized_at)->not->toBeNull();

    $manifest = $project->manifest()->firstOrFail();
    $files = app(ManifestCompiler::class)->compile($manifest);

    expect($manifest->preset_key)->toBe('laravel')
        ->and($manifest->preset_version)->toBe('v1')
        ->and($manifest->revisions()->where('revision_number', 1)->exists())->toBeTrue()
        ->and($files)->toHaveKeys([
            'kustomization.yaml',
            'workloads/web-deployment.yaml',
            'components/worker/deployment.yaml',
            'components/horizon/deployment.yaml',
            'components/scheduler/cronjob.yaml',
            'secrets/external-secret.yaml',
        ])
        ->and($files['workloads/web-deployment.yaml'])->toContain('my-laravel-app')
        ->and($files['secrets/external-secret.yaml'])->toContain('platform-vault');

    assertYamlFilesParse($files);
});

test('nextjs projects initialize with a node web manifest preset', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $project = Project::factory()->nextjs()->create([
        'team_id' => $team->id,
        'name' => 'Next Store',
        'repository' => 'acme/next-store',
    ]);

    (new InitializeProjectManifests($project))->handle(app(ManifestPresetRegistry::class));

    $manifest = $project->refresh()->manifest()->firstOrFail();
    $files = app(ManifestCompiler::class)->compile($manifest);

    expect($project->initialization_status)->toBe(Project::InitializationReady)
        ->and($manifest->preset_key)->toBe('nextjs')
        ->and($files)->toHaveKeys([
            'kustomization.yaml',
            'workloads/web-deployment.yaml',
            'services/web-service.yaml',
            'secrets/external-secret.yaml',
            'autoscaling/hpa.yaml',
        ])
        ->and($files['workloads/web-deployment.yaml'])->toContain('containerPort: 3000')
        ->and($files['workloads/web-deployment.yaml'])->toContain('HOSTNAME')
        ->and($files['kustomization.yaml'])->not->toContain('autoscaling/hpa.yaml');

    assertYamlFilesParse($files);
});

/**
 * @param  array<string, string>  $files
 */
function assertYamlFilesParse(array $files): void
{
    $yamlFileCount = 0;

    foreach ($files as $path => $content) {
        if (! str_ends_with($path, '.yaml')) {
            continue;
        }

        Yaml::parse($content);
        $yamlFileCount++;
    }

    expect($yamlFileCount)->toBeGreaterThan(0);
}
