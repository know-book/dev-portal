<?php

use App\Jobs\ProcessGitHubWebhookJob;
use App\Models\User;
use App\Services\GitHub\GitHubAppService;
use App\Services\Kubernetes\K8sBuildEngineService;
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
