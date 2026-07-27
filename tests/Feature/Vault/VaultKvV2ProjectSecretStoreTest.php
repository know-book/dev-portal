<?php

use App\Exceptions\SecretStoreException;
use App\Exceptions\SecretVersionConflict;
use App\Models\Project;
use App\Models\User;
use App\Services\Vault\VaultKvV2ProjectSecretStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

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

test('it reports an actionable forbidden write without exposing vault error content', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);
    $loggedContext = null;

    expectVaultFailureLog(function (array $context) use (&$loggedContext): void {
        $loggedContext = $context;
    });

    Http::fake(['vault.test/*' => Http::response([
        'request_id' => 'vault-request-123',
        'errors' => ['token vault-root-token rejected'],
    ], 403)]);

    try {
        app(VaultKvV2ProjectSecretStore::class)->write($project, ['APP_KEY' => 'super-secret-value'], 0);
    } catch (SecretStoreException $exception) {
        expect($exception)->not->toBeInstanceOf(SecretVersionConflict::class)
            ->and($exception->getMessage())->toContain('Vault denied the secret write (HTTP 403)')
            ->and($exception->getMessage())->toContain('Reference:')
            ->and($exception->getMessage())->not->toContain('vault-root-token')
            ->and($exception->getMessage())->not->toContain('super-secret-value');
    }

    expect($loggedContext)->toMatchArray([
        'integration' => 'vault',
        'operation' => 'write',
        'project_id' => $project->id,
        'team_id' => $project->team_id,
        'http_status' => 403,
        'vault_request_id' => 'vault-request-123',
        'failure_reason' => 'forbidden',
    ])->and(json_encode($loggedContext))->not->toContain('vault-root-token')
        ->and(json_encode($loggedContext))->not->toContain('super-secret-value');
});

test('it distinguishes a bad vault request from a cas conflict', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    expectVaultFailureLog();

    Http::fake(['vault.test/*' => Http::response([
        'errors' => ['invalid request'],
    ], 400)]);

    expect(fn () => app(VaultKvV2ProjectSecretStore::class)->write($project, ['APP_KEY' => 'value'], 0))
        ->toThrow(
            SecretStoreException::class,
            'Vault rejected the secret write (HTTP 400)',
        );
});

test('it reports connection diagnostics without exposing request values', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);
    $loggedContext = null;

    expectVaultFailureLog(function (array $context) use (&$loggedContext): void {
        $loggedContext = $context;
    });

    Http::fake(['vault.test/*' => Http::failedConnection()]);

    expect(fn () => app(VaultKvV2ProjectSecretStore::class)->write($project, ['APP_KEY' => 'do-not-log'], 0))
        ->toThrow(
            SecretStoreException::class,
            'Vault connection failed during the secret write',
        );

    expect($loggedContext)->toMatchArray([
        'operation' => 'write',
        'failure_reason' => 'connection_failed',
    ])->and(json_encode($loggedContext))->not->toContain('do-not-log');
});

/** @param (Closure(array<string, mixed>): void)|null $assertContext */
function expectVaultFailureLog(?Closure $assertContext = null): void
{
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($assertContext): bool {
            expect($message)->toBe('Vault integration failure: {message}')
                ->and($context)->toHaveKey('diagnostic_id')
                ->and($context)->toHaveKey('message');

            $assertContext?->__invoke($context);

            return true;
        });

    Log::shouldReceive('channel')
        ->once()
        ->with('stderr')
        ->andReturn($logger);
}
