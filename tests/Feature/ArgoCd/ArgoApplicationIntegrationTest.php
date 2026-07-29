<?php

use App\Contracts\ArgoApplicationGateway;
use App\Enums\GitOpsRepositoryMode;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectManifest;
use App\Models\User;
use App\Services\ArgoCd\ArgoApplicationDefinitionFactory;
use App\Services\ArgoCd\ArgoCdApiClient;
use App\Services\ArgoCd\KubernetesApplicationClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.kubernetes.url' => 'https://kubernetes.test',
        'services.kubernetes.token' => 'kubernetes-token',
        'services.kubernetes.verify_tls' => true,
        'services.argocd.url' => 'https://argocd.test',
        'services.argocd.token' => 'argocd-token',
        'services.argocd.namespace' => 'argocd',
        'services.argocd.project' => 'platform',
        'services.argocd.destination_server' => 'https://kubernetes.default.svc',
        'services.argocd.auto_prune' => true,
        'services.argocd.self_heal' => true,
    ]);

    Http::preventStrayRequests();
});

test('application definition follows the co-located gitops target', function () {
    $project = argoProject();

    $definition = app(ArgoApplicationDefinitionFactory::class)->make($project);

    expect(data_get($definition, 'metadata.name'))->toBe($project->team->slug.'-'.$project->slug)
        ->and(data_get($definition, 'metadata.namespace'))->toBe('argocd')
        ->and(data_get($definition, 'spec.project'))->toBe('platform')
        ->and(data_get($definition, 'spec.source.repoURL'))->toBe('https://github.com/acme/storefront.git')
        ->and(data_get($definition, 'spec.source.targetRevision'))->toBe('production')
        ->and(data_get($definition, 'spec.source.path'))->toBe('deploy/k8s')
        ->and(data_get($definition, 'spec.destination.namespace'))->toBe('shop-production')
        ->and(data_get($definition, 'spec.syncPolicy.automated.prune'))->toBeTrue()
        ->and(data_get($definition, 'spec.syncPolicy.automated.selfHeal'))->toBeTrue();
});

test('application definition follows a separate gitops repository target', function () {
    $project = argoProject();
    $project->update([
        'gitops_repository_mode' => GitOpsRepositoryMode::Separate,
        'gitops_github_installation_id' => $project->github_installation_id,
        'gitops_repository' => 'platform/deployments',
        'gitops_repository_id' => '902',
        'gitops_branch' => 'main',
        'gitops_path' => 'clusters/prod/storefront',
    ]);

    $definition = app(ArgoApplicationDefinitionFactory::class)->make($project->refresh());

    expect(data_get($definition, 'spec.source.repoURL'))->toBe('https://github.com/platform/deployments.git')
        ->and(data_get($definition, 'spec.source.targetRevision'))->toBe('main')
        ->and(data_get($definition, 'spec.source.path'))->toBe('clusters/prod/storefront');
});

test('kubernetes client reconciles the application crd using server-side apply', function () {
    $project = argoProject();
    $definition = app(ArgoApplicationDefinitionFactory::class)->make($project);

    Http::fake([
        'kubernetes.test/*' => Http::response($definition),
    ]);

    $response = app(KubernetesApplicationClient::class)->apply($definition);

    expect(data_get($response, 'metadata.name'))->toBe(data_get($definition, 'metadata.name'));

    Http::assertSent(function (Request $request) use ($definition): bool {
        return $request->method() === 'PATCH'
            && str_contains($request->url(), '/apis/argoproj.io/v1alpha1/namespaces/argocd/applications/')
            && str_contains($request->url(), 'fieldManager=dev-portal')
            && str_contains($request->url(), 'force=true')
            && $request->hasHeader('Authorization', 'Bearer kubernetes-token')
            && $request->hasHeader('Content-Type', 'application/apply-patch+yaml')
            && json_decode($request->body(), true) === $definition;
    });
});

test('argocd api reads hard-refreshed status and requests sync', function () {
    $project = argoProject();

    Http::fake(function (Request $request) use ($project) {
        if ($request->method() === 'POST') {
            return Http::response(['metadata' => ['name' => $project->team->slug.'-'.$project->slug]]);
        }

        return Http::response([
            'metadata' => ['name' => $project->team->slug.'-'.$project->slug],
            'status' => [
                'sync' => ['status' => 'Synced', 'revision' => 'abcdef123456'],
                'health' => ['status' => 'Healthy'],
                'operationState' => ['phase' => 'Succeeded', 'message' => 'successfully synced'],
            ],
        ]);
    });

    $client = app(ArgoCdApiClient::class);
    $status = $client->status($project, hardRefresh: true);
    $synced = $client->sync($project);

    expect($status?->syncStatus)->toBe('Synced')
        ->and($status?->healthStatus)->toBe('Healthy')
        ->and($status?->revision)->toBe('abcdef123456')
        ->and($synced->operationPhase)->toBe('Succeeded');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'appNamespace=argocd')
        && str_contains($request->url(), 'project=platform')
        && str_contains($request->url(), 'refresh=hard')
        && $request->hasHeader('Authorization', 'Bearer argocd-token'));

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/sync')
            && $request['appNamespace'] === 'argocd'
            && $request['project'] === 'platform'
            && $request['prune'] === true;
    });
});

test('application gateway checks and syncs through Kubernetes when Argo CD REST is not configured', function () {
    $project = argoProject();

    config([
        'services.argocd.url' => null,
        'services.argocd.token' => null,
    ]);

    Http::fake(fn () => Http::response([
        'metadata' => ['name' => $project->team->slug.'-'.$project->slug],
        'status' => [
            'sync' => ['status' => 'Synced', 'revision' => 'kubernetes-revision'],
            'health' => ['status' => 'Healthy'],
            'operationState' => ['phase' => 'Succeeded'],
        ],
    ]));

    $gateway = app(ArgoApplicationGateway::class);
    $status = $gateway->status($project, hardRefresh: true);
    $synced = $gateway->sync($project);

    expect($status?->syncStatus)->toBe('Synced')
        ->and($status?->healthStatus)->toBe('Healthy')
        ->and($synced->operationPhase)->toBe('Succeeded');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && str_contains($request->url(), '/apis/argoproj.io/v1alpha1/namespaces/argocd/applications/')
        && (json_decode($request->body(), true)['metadata']['annotations']['argocd.argoproj.io/refresh'] ?? null) === 'hard'
        && $request->hasHeader('Authorization', 'Bearer kubernetes-token'));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && data_get(json_decode($request->body(), true), 'operation.sync.prune') === true
        && data_get(json_decode($request->body(), true), 'operation.sync.syncOptions.0') === 'CreateNamespace=true');
});

function argoProject(): Project
{
    $user = User::factory()->create();
    $installation = GitHubInstallation::create([
        'team_id' => $user->currentTeam->id,
        'installation_id' => '1001',
        'account_name' => 'acme',
        'account_type' => 'Organization',
        'permissions' => ['contents' => 'write'],
    ]);
    $project = Project::factory()->create([
        'team_id' => $user->currentTeam->id,
        'github_installation_id' => $installation->id,
        'repository' => 'acme/storefront',
        'repository_id' => '501',
        'default_branch' => 'main',
        'gitops_branch' => 'production',
        'gitops_path' => 'deploy/k8s',
        'auto_deploy' => true,
    ]);

    ProjectManifest::factory()->create([
        'project_id' => $project->id,
        'variables' => ['namespace' => 'shop-production'],
    ]);

    return $project->refresh();
}
