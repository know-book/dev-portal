<?php

use App\Models\Project;
use App\Models\ProjectManifest;
use App\Models\User;
use App\Services\Kubernetes\KubernetesDeploymentClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.kubernetes.url' => 'https://kubernetes.test',
        'services.kubernetes.token' => 'kubernetes-token',
        'services.kubernetes.verify_tls' => true,
    ]);

    Http::preventStrayRequests();
});

test('kubernetes client reports live project Deployment replicas and images', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);
    ProjectManifest::factory()->create([
        'project_id' => $project->id,
        'variables' => ['namespace' => 'team-storefront'],
    ]);

    Http::fake([
        'kubernetes.test/apis/apps/v1/namespaces/team-storefront/deployments' => Http::response([
            'items' => [[
                'metadata' => ['name' => 'storefront'],
                'spec' => [
                    'replicas' => 1,
                    'template' => [
                        'spec' => [
                            'containers' => [
                                ['name' => 'web', 'image' => 'ghcr.io/acme/storefront:sha-1234567'],
                            ],
                        ],
                    ],
                ],
                'status' => [
                    'readyReplicas' => 1,
                    'availableReplicas' => 1,
                    'updatedReplicas' => 1,
                ],
            ]],
        ]),
    ]);

    $statuses = app(KubernetesDeploymentClient::class)->status($project);

    expect($statuses)->toHaveCount(1)
        ->and($statuses[0]->name)->toBe('storefront')
        ->and($statuses[0]->ready())->toBeTrue()
        ->and($statuses[0]->images)->toBe(['ghcr.io/acme/storefront:sha-1234567']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_ends_with($request->url(), '/apis/apps/v1/namespaces/team-storefront/deployments')
        && $request->hasHeader('Authorization', 'Bearer kubernetes-token'));
});

test('kubernetes client returns an empty list when the project Deployments do not exist', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);
    ProjectManifest::factory()->create([
        'project_id' => $project->id,
        'variables' => ['namespace' => 'team-empty'],
    ]);

    Http::fake([
        'kubernetes.test/*' => Http::response([], 404),
    ]);

    expect(app(KubernetesDeploymentClient::class)->status($project))->toBe([]);
});
