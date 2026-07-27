<?php

use App\Models\Project;
use App\Models\User;
use App\Services\Vault\VaultKvV2ProjectSecretStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.vault.url' => 'https://vault.test',
        'services.vault.token' => 'vault-token',
        'services.vault.mount' => 'secret',
    ]);

    Http::preventStrayRequests();
});

test('vault metadata check confirms a project secret without reading values', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    Http::fake([
        'vault.test/*' => Http::response([
            'data' => [
                'current_version' => 4,
                'updated_time' => '2026-07-27T12:00:00Z',
            ],
        ]),
    ]);

    $metadata = app(VaultKvV2ProjectSecretStore::class)->metadata($project);

    expect($metadata->exists)->toBeTrue()
        ->and($metadata->version)->toBe(4);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/v1/secret/metadata/')
        && ! str_contains($request->url(), '/v1/secret/data/'));
});

test('vault metadata check reports a missing project secret', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    Http::fake(['vault.test/*' => Http::response([], 404)]);

    $metadata = app(VaultKvV2ProjectSecretStore::class)->metadata($project);

    expect($metadata->exists)->toBeFalse()
        ->and($metadata->version)->toBe(0);
});
