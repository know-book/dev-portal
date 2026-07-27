<?php

use App\Models\Project;
use App\Models\ProjectManifest;
use App\Models\User;
use App\Services\Kubernetes\KubernetesExternalSecretClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.kubernetes.url' => 'https://kubernetes.test',
        'services.kubernetes.token' => 'kubernetes-token',
        'services.kubernetes.external_secrets_api_version' => 'v1',
    ]);

    Http::preventStrayRequests();
});

test('external secret status reads the ready condition without reading kubernetes secret data', function () {
    $project = projectWithExternalSecretIdentity();

    Http::fake([
        'kubernetes.test/*' => Http::response([
            'metadata' => ['name' => 'storefront-env'],
            'status' => [
                'refreshTime' => '2026-07-27T12:00:00Z',
                'conditions' => [[
                    'type' => 'Ready',
                    'status' => 'True',
                    'reason' => 'SecretSynced',
                    'message' => 'Secret was synced',
                ]],
            ],
        ]),
    ]);

    $status = app(KubernetesExternalSecretClient::class)->status($project);

    expect($status->exists)->toBeTrue()
        ->and($status->ready)->toBeTrue()
        ->and($status->reason)->toBe('SecretSynced')
        ->and($status->refreshTime)->toBe('2026-07-27T12:00:00Z');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://kubernetes.test/apis/external-secrets.io/v1/namespaces/acme-storefront/externalsecrets/storefront-env'
        && $request->hasHeader('Authorization', 'Bearer kubernetes-token'));
});

test('external secret status reports a resource that has not been deployed', function () {
    $project = projectWithExternalSecretIdentity();

    Http::fake(['kubernetes.test/*' => Http::response([], 404)]);

    $status = app(KubernetesExternalSecretClient::class)->status($project);

    expect($status->exists)->toBeFalse()
        ->and($status->ready)->toBeFalse()
        ->and($status->reason)->toBe('NotFound');
});

test('kubernetes requests use the projected service account token file', function () {
    $project = projectWithExternalSecretIdentity();
    $tokenFile = tempnam(sys_get_temp_dir(), 'kubernetes-api-token-');

    if ($tokenFile === false) {
        throw new RuntimeException('Unable to create a temporary Kubernetes token file.');
    }

    file_put_contents($tokenFile, "projected-token\n");
    config([
        'services.kubernetes.token' => '',
        'services.kubernetes.token_file' => $tokenFile,
    ]);

    Http::fake(['kubernetes.test/*' => Http::response([], 404)]);

    try {
        app(KubernetesExternalSecretClient::class)->status($project);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Bearer projected-token',
        ));
    } finally {
        unlink($tokenFile);
    }
});

test('external secret refresh patches the force-sync annotation', function () {
    $project = projectWithExternalSecretIdentity();

    Http::fake([
        'kubernetes.test/*' => Http::response([
            'metadata' => ['name' => 'storefront-env'],
            'status' => [
                'conditions' => [[
                    'type' => 'Ready',
                    'status' => 'False',
                    'reason' => 'SecretSyncedError',
                ]],
            ],
        ]),
    ]);

    $status = app(KubernetesExternalSecretClient::class)->refresh($project);

    expect($status->exists)->toBeTrue()
        ->and($status->ready)->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->method() === 'PATCH'
            && $request->hasHeader('Content-Type', 'application/merge-patch+json')
            && is_string(data_get($body, 'metadata.annotations.force-sync'));
    });
});

function projectWithExternalSecretIdentity(): Project
{
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    ProjectManifest::factory()->create([
        'project_id' => $project->id,
        'variables' => [
            'namespace' => 'acme-storefront',
            'project_slug' => 'storefront',
        ],
    ]);

    return $project->refresh();
}
