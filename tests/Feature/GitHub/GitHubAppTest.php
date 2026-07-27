<?php

use App\Enums\GitOpsRepositoryMode;
use App\Jobs\ProcessGitHubWebhookJob;
use App\Models\Project;
use App\Models\User;
use App\Services\GitHub\GitHubAppService;
use App\Services\Kubernetes\K8sBuildEngineService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('github app service generates jwt and verifies signature', function () {
    $service = new GitHubAppService;
    $jwt = $service->generateAppJwt('12345', 'test-key');

    expect($jwt)->toBeString();
    expect(count(explode('.', $jwt)))->toBe(3);

    $payload = '{"test":true}';
    $secret = 'webhook-secret-key';
    $signature = 'sha256='.hash_hmac('sha256', $payload, $secret);

    expect($service->verifyWebhookSignature($payload, $signature, $secret))->toBeTrue();
});

test('github app service lists installation repositories with pagination', function () {
    Cache::flush();

    config([
        'services.github.app_id' => '12345',
        'services.github.private_key' => 'test-key',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'installation-token']),
        'api.github.com/installation/repositories*page=1' => Http::response([
            'repositories' => [
                [
                    'id' => 1,
                    'name' => 'alpha',
                    'full_name' => 'acme/alpha',
                    'default_branch' => 'main',
                    'html_url' => 'https://github.com/acme/alpha',
                ],
            ],
        ], 200, ['Link' => '<https://api.github.com/installation/repositories?per_page=100&page=2>; rel="next"']),
        'api.github.com/installation/repositories*page=2' => Http::response([
            'repositories' => [
                [
                    'id' => 2,
                    'name' => 'beta',
                    'full_name' => 'acme/beta',
                    'default_branch' => 'trunk',
                    'html_url' => 'https://github.com/acme/beta',
                ],
            ],
        ]),
    ]);

    $repositories = (new GitHubAppService)->getInstallationRepositories('999888');

    expect($repositories)->toHaveCount(2)
        ->and($repositories[0]['full_name'])->toBe('acme/alpha')
        ->and($repositories[1]['default_branch'])->toBe('trunk');
});

test('webhook endpoint accepts push event and dispatches job', function () {
    Queue::fake();

    $payload = json_encode(['repository' => ['full_name' => 'test/repo']]);
    $secret = config('services.github.webhook_secret') ?: 'secret';
    $signature = 'sha256='.hash_hmac('sha256', $payload, $secret);

    $response = $this->call(
        'POST',
        route('webhooks.github'),
        [],
        [],
        [],
        [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload
    );

    $response->assertOk();
    $response->assertJson(['status' => 'webhook_received', 'event' => 'push']);

    Queue::assertPushed(ProcessGitHubWebhookJob::class);
});

test('process github webhook job syncs installation event to database', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = [
        'action' => 'created',
        'installation' => [
            'id' => 999888,
            'account' => [
                'login' => 'my-org',
                'type' => 'Organization',
                'avatar_url' => 'https://github.com/avatar.png',
            ],
            'permissions' => ['contents' => 'read'],
        ],
    ];

    $job = new ProcessGitHubWebhookJob('installation', $payload);
    $buildEngine = new K8sBuildEngineService;
    $job->handle($buildEngine);

    $this->assertDatabaseHas('github_installations', [
        'installation_id' => '999888',
        'account_name' => 'my-org',
        'account_type' => 'Organization',
    ]);
});

test('co-located manifest-only pushes do not trigger another image build', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'team_id' => $user->currentTeam->id,
        'repository' => 'acme/storefront',
        'default_branch' => 'main',
        'gitops_repository_mode' => GitOpsRepositoryMode::CoLocated,
        'gitops_path' => 'deploy/k8s',
    ]);
    $payload = [
        'repository' => ['full_name' => $project->repository],
        'ref' => 'refs/heads/main',
        'after' => 'manifest-commit',
        'commits' => [[
            'added' => ['deploy/k8s/.devportal.json'],
            'modified' => ['deploy/k8s/kustomization.yaml'],
            'removed' => [],
        ]],
    ];
    $buildEngine = Mockery::mock(K8sBuildEngineService::class);
    $buildEngine->shouldNotReceive('dispatchBuildJob');

    (new ProcessGitHubWebhookJob('push', $payload))->handle($buildEngine);
});

test('source changes still trigger a build when manifests also changed', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'team_id' => $user->currentTeam->id,
        'repository' => 'acme/storefront',
        'default_branch' => 'main',
        'gitops_repository_mode' => GitOpsRepositoryMode::CoLocated,
        'gitops_path' => 'deploy/k8s',
    ]);
    $payload = [
        'repository' => ['full_name' => $project->repository],
        'ref' => 'refs/heads/main',
        'after' => 'source-commit',
        'commits' => [[
            'added' => [],
            'modified' => ['app/Service.php', 'deploy/k8s/kustomization.yaml'],
            'removed' => [],
        ]],
    ];
    $buildEngine = Mockery::mock(K8sBuildEngineService::class);
    $buildEngine->shouldReceive('dispatchBuildJob')
        ->once()
        ->withArgs(fn (Project $target, string $branch, string $sha): bool => $target->is($project)
            && $branch === 'main'
            && $sha === 'source-commit')
        ->andReturn(['status' => 'queued']);

    (new ProcessGitHubWebhookJob('push', $payload))->handle($buildEngine);
});
