<?php

use App\Exceptions\SecretStoreException;
use App\Exceptions\SecretVersionConflict;
use App\Models\Project;
use App\Models\User;
use App\Services\Vault\VaultKvV2ProjectSecretStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.vault.url' => 'https://vault.test',
        'services.vault.token' => 'vault-token',
        'services.vault.namespace' => 'engineering',
        'services.vault.mount' => 'kv',
    ]);

    Http::preventStrayRequests();
});

test('it reads a project secret from vault kv v2', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    Http::fake([
        'vault.test/*' => Http::response([
            'data' => [
                'data' => ['Z_LAST' => 'last', 'APP_KEY' => 'secret'],
                'metadata' => ['version' => 7],
            ],
        ]),
    ]);

    $document = app(VaultKvV2ProjectSecretStore::class)->read($project);

    expect($document->values)->toBe(['APP_KEY' => 'secret', 'Z_LAST' => 'last'])
        ->and($document->version)->toBe(7);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === "https://vault.test/v1/kv/data/{$project->team->slug}/{$project->slug}/app"
        && $request->hasHeader('X-Vault-Token', 'vault-token')
        && $request->hasHeader('X-Vault-Namespace', 'engineering'));
});

test('it treats a missing vault secret as a new document', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    Http::fake(['vault.test/*' => Http::response([], 404)]);

    $document = app(VaultKvV2ProjectSecretStore::class)->read($project);

    expect($document->values)->toBe([])
        ->and($document->version)->toBe(0);
});

test('it writes a project secret using the revealed vault version as cas', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    Http::fake(['vault.test/*' => Http::response(['data' => ['version' => 8]])]);

    $document = app(VaultKvV2ProjectSecretStore::class)->write(
        $project,
        ['APP_KEY' => 'rotated'],
        7,
    );

    expect($document->version)->toBe(8)
        ->and($document->values)->toBe(['APP_KEY' => 'rotated']);

    Http::assertSent(function (Request $request): bool {
        $data = (array) $request['data'];

        return $request->method() === 'POST'
            && $request['options']['cas'] === 7
            && $data['APP_KEY'] === 'rotated';
    });
});

test('it reports a vault cas mismatch as a version conflict', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    Http::fake(['vault.test/*' => Http::response([
        'errors' => ['check-and-set parameter did not match the current version'],
    ], 400)]);

    expect(fn () => app(VaultKvV2ProjectSecretStore::class)->write($project, ['APP_KEY' => 'stale'], 2))
        ->toThrow(SecretVersionConflict::class);
});

test('it does not expose vault error response content', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    Http::fake(['vault.test/*' => Http::response(['errors' => ['token vault-root-token rejected']], 403)]);

    try {
        app(VaultKvV2ProjectSecretStore::class)->read($project);
    } catch (SecretStoreException $exception) {
        expect($exception->getMessage())->toBe('Vault could not read this project secret.')
            ->and($exception->getMessage())->not->toContain('vault-root-token');
    }
});
