<?php

use App\Actions\Projects\ImportProjectDockerTemplate;
use App\Contracts\SourceRepositoryPublisher;
use App\Data\GitOpsPublication;
use App\Data\SourceRepositoryTarget;
use App\Enums\GitOpsRepositoryMode;
use App\Exceptions\SourceRepositoryException;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use App\Services\Docker\DockerPresetRegistry;
use App\Services\GitHub\GitHubSourceRepositoryPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    Cache::flush();

    config([
        'services.github.app_id' => '12345',
        'services.github.private_key' => 'test-key',
    ]);

    Http::preventStrayRequests();
});

test('docker presets render Laravel and Next.js GHCR workflows', function () {
    $user = User::factory()->create();
    $registry = app(DockerPresetRegistry::class);
    $laravelProject = Project::factory()->laravel()->create([
        'team_id' => $user->currentTeam->id,
        'repository' => 'Acme/Laravel-App',
        'default_branch' => 'production',
    ]);
    $nextProject = Project::factory()->nextjs()->create([
        'team_id' => $user->currentTeam->id,
        'repository' => 'Acme/Next-App',
        'default_branch' => 'main',
        'gitops_repository_mode' => GitOpsRepositoryMode::Separate,
    ]);

    $laravelFiles = $registry->filesForProject($laravelProject, 'laravel');
    $nextFiles = $registry->filesForProject($nextProject, 'nextjs');
    $laravelWorkflow = Yaml::parse($laravelFiles['.github/workflows/docker-build.yaml']);
    $nextWorkflow = Yaml::parse($nextFiles['.github/workflows/docker-build.yaml']);

    expect($laravelFiles)->toHaveKeys([
        '.dockerignore',
        '.github/workflows/docker-build.yaml',
        'docker/production/nginx/Dockerfile',
        'docker/production/nginx/nginx.conf',
        'docker/production/php-fpm/Dockerfile',
        'docker/production/php-fpm/entrypoint.sh',
    ])
        ->and($laravelFiles['.github/workflows/docker-build.yaml'])
        ->toContain('branches:', '- production', 'IMAGE_REPOSITORY: ghcr.io/acme/laravel-app', '${{ env.IMAGE_REPOSITORY }}/${{ matrix.name }}')
        ->not->toContain('{{ default_branch }}')
        ->and($nextFiles)->toHaveKeys([
            '.dockerignore',
            '.github/workflows/docker-build.yaml',
            'docker/production/Dockerfile',
        ])
        ->and($nextFiles['.github/workflows/docker-build.yaml'])
        ->toContain('packages: write', 'IMAGE_REPOSITORY: ghcr.io/acme/next-app', '${{ env.IMAGE_REPOSITORY }}', '&& false')
        ->and($laravelFiles['.github/workflows/docker-build.yaml'])->toContain('&& true')
        ->and($laravelWorkflow['jobs'])->toHaveKeys(['build-and-push', 'update-gitops'])
        ->and($nextWorkflow['jobs'])->toHaveKeys(['build-and-push', 'update-gitops']);
});

test('project imports a selected Docker preset into its source repository', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $installation = GitHubInstallation::create([
        'team_id' => $team->id,
        'installation_id' => '7007',
        'account_name' => 'acme',
        'account_type' => 'Organization',
        'permissions' => ['contents' => 'write', 'workflows' => 'write'],
    ]);
    $project = Project::factory()->nextjs()->create([
        'team_id' => $team->id,
        'github_installation_id' => $installation->id,
        'repository' => 'acme/storefront',
        'default_branch' => 'trunk',
    ]);
    $fakePublisher = new class implements SourceRepositoryPublisher
    {
        public ?SourceRepositoryTarget $target = null;

        /** @var array<string, string> */
        public array $files = [];

        public string $commitMessage = '';

        public function publish(SourceRepositoryTarget $target, array $files, string $commitMessage): GitOpsPublication
        {
            $this->target = $target;
            $this->files = $files;
            $this->commitMessage = $commitMessage;

            return new GitOpsPublication(changed: true, commitSha: 'docker-commit');
        }
    };
    $this->app->instance(SourceRepositoryPublisher::class, $fakePublisher);

    $publication = app(ImportProjectDockerTemplate::class)->handle($project, $user, 'nextjs');
    $marker = json_decode($fakePublisher->files['.devportal/docker.json'], true, flags: JSON_THROW_ON_ERROR);

    expect($publication->commitSha)->toBe('docker-commit')
        ->and($fakePublisher->target?->repository)->toBe('acme/storefront')
        ->and($fakePublisher->target?->branch)->toBe('trunk')
        ->and($fakePublisher->target?->markerPath)->toBe('.devportal/docker.json')
        ->and($fakePublisher->files)->toHaveKeys([
            '.dockerignore',
            '.github/workflows/docker-build.yaml',
            '.devportal/docker.json',
            'docker/production/Dockerfile',
        ])
        ->and($marker['preset'])->toBe('nextjs/v1')
        ->and($marker['managed_files'])->not->toContain('.devportal/docker.json')
        ->and($fakePublisher->commitMessage)->toBe('chore(ci): import nextjs Docker build');
});

test('Docker import requires workflow write permission', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $installation = GitHubInstallation::create([
        'team_id' => $team->id,
        'installation_id' => '8008',
        'account_name' => 'acme',
        'account_type' => 'Organization',
        'permissions' => ['contents' => 'write', 'workflows' => 'read'],
    ]);
    $project = Project::factory()->create([
        'team_id' => $team->id,
        'github_installation_id' => $installation->id,
        'repository' => 'acme/api',
    ]);

    expect(fn () => app(ImportProjectDockerTemplate::class)->handle($project, $user, 'laravel'))
        ->toThrow(SourceRepositoryException::class, 'Workflows: Read and write');
});

test('source publisher atomically commits Docker files', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/access_tokens') => Http::response(['token' => 'installation-token']),
            $request->method() === 'GET' && str_contains($url, '/git/ref/heads/main') => Http::response(['object' => ['sha' => 'base-commit']]),
            $request->method() === 'GET' && str_contains($url, '/git/commits/base-commit') => Http::response(['tree' => ['sha' => 'base-tree']]),
            $request->method() === 'GET' && str_contains($url, '/git/trees/base-tree') => Http::response(['tree' => [], 'truncated' => false]),
            $request->method() === 'POST' && str_contains($url, '/git/trees') => Http::response(['sha' => 'new-tree'], 201),
            $request->method() === 'POST' && str_contains($url, '/git/commits') => Http::response(['sha' => 'new-commit'], 201),
            $request->method() === 'PATCH' && str_contains($url, '/git/refs/heads/main') => Http::response(['object' => ['sha' => 'new-commit']]),
            default => Http::response([], 404),
        };
    });

    $publication = app(GitHubSourceRepositoryPublisher::class)->publish(
        sourceRepositoryTarget(),
        [
            'docker/production/Dockerfile' => "FROM node:22-alpine\n",
            '.github/workflows/docker-build.yaml' => "name: Docker\n",
            '.devportal/docker.json' => "{\"managed_files\":[]}\n",
        ],
        'chore(ci): import Docker build',
    );

    expect($publication)->toEqual(new GitOpsPublication(changed: true, commitSha: 'new-commit'));

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/git/trees')) {
            return false;
        }

        return collect($request->data()['tree'])
            ->pluck('path')
            ->sort()
            ->values()
            ->all() === [
                '.devportal/docker.json',
                '.github/workflows/docker-build.yaml',
                'docker/production/Dockerfile',
            ];
    });
});

test('source publisher refuses to overwrite unmanaged Docker files', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/access_tokens') => Http::response(['token' => 'installation-token']),
            str_contains($url, '/git/ref/heads/main') => Http::response(['object' => ['sha' => 'existing-commit']]),
            str_contains($url, '/git/commits/existing-commit') => Http::response(['tree' => ['sha' => 'existing-tree']]),
            str_contains($url, '/git/trees/existing-tree') => Http::response([
                'tree' => [
                    ['path' => '.github/workflows/docker-build.yaml', 'type' => 'blob', 'sha' => 'user-workflow'],
                ],
                'truncated' => false,
            ]),
            default => Http::response([], 404),
        };
    });

    expect(fn () => app(GitHubSourceRepositoryPublisher::class)->publish(
        sourceRepositoryTarget(),
        [
            '.github/workflows/docker-build.yaml' => "name: Docker\n",
            '.devportal/docker.json' => "{\"managed_files\":[]}\n",
        ],
        'chore(ci): import Docker build',
    ))->toThrow(SourceRepositoryException::class, 'already exists and is not managed');
});

test('Docker page defaults to the project framework and blocks other teams', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->nextjs()->create(['team_id' => $user->currentTeam->id]);
    $otherProject = Project::factory()->create(['team_id' => $otherUser->currentTeam->id]);

    Livewire::actingAs($user)
        ->test('pages::projects.docker', ['project' => $project])
        ->assertSet('template', 'nextjs')
        ->assertSee('Import to Repository');

    $this->actingAs($user)
        ->get(route('projects.docker', [
            'current_team' => $user->currentTeam->slug,
            'project' => $otherProject->slug,
        ]))
        ->assertNotFound();
});

function sourceRepositoryTarget(): SourceRepositoryTarget
{
    return new SourceRepositoryTarget(
        installationId: '1001',
        repository: 'acme/storefront',
        branch: 'main',
        markerPath: '.devportal/docker.json',
    );
}
