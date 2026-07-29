<?php

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\PublishProjectManifests;
use App\Contracts\GitOpsRepositoryPublisher;
use App\Data\GitOpsPublication;
use App\Data\GitOpsTarget;
use App\Enums\GitOpsPublishMode;
use App\Enums\ProjectFramework;
use App\Exceptions\GitOpsRepositoryException;
use App\Models\GitHubInstallation;
use App\Models\ProjectManifestRevision;
use App\Models\User;
use App\Services\GitHub\GitHubGitOpsRepositoryPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();

    config([
        'services.github.app_id' => '12345',
        'services.github.private_key' => 'test-key',
    ]);

    Http::preventStrayRequests();
});

test('publisher atomically commits changed manifest files', function () {
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

    $publication = app(GitOpsRepositoryPublisher::class)->publish(
        gitOpsTarget(),
        [
            'kustomization.yaml' => "resources:\n  - deployment.yaml\n",
            '.devportal.json' => "{\"managed_files\":[\"kustomization.yaml\"]}\n",
        ],
        'chore(deploy): sync manifests',
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
                'deploy/k8s/.devportal.json',
                'deploy/k8s/kustomization.yaml',
            ];
    });
});

test('publisher skips commits when managed files are unchanged', function () {
    $manifest = "resources:\n  - deployment.yaml\n";
    $marker = "{\"managed_files\":[\"kustomization.yaml\"]}\n";

    Http::fake(function (Request $request) use ($manifest, $marker) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/access_tokens') => Http::response(['token' => 'installation-token']),
            str_contains($url, '/git/ref/heads/main') => Http::response(['object' => ['sha' => 'existing-commit']]),
            str_contains($url, '/git/commits/existing-commit') => Http::response(['tree' => ['sha' => 'existing-tree']]),
            str_contains($url, '/git/trees/existing-tree') => Http::response([
                'tree' => [
                    ['path' => 'deploy/k8s/kustomization.yaml', 'type' => 'blob', 'sha' => gitBlobSha($manifest)],
                    ['path' => 'deploy/k8s/.devportal.json', 'type' => 'blob', 'sha' => gitBlobSha($marker)],
                ],
                'truncated' => false,
            ]),
            str_contains($url, '/git/blobs/'.gitBlobSha($marker)) => Http::response([
                'encoding' => 'base64',
                'content' => base64_encode($marker),
            ]),
            default => Http::response([], 404),
        };
    });

    $publication = app(GitHubGitOpsRepositoryPublisher::class)->publish(
        gitOpsTarget(),
        [
            'kustomization.yaml' => $manifest,
            '.devportal.json' => $marker,
        ],
        'chore(deploy): sync manifests',
    );

    expect($publication->changed)->toBeFalse()
        ->and($publication->commitSha)->toBe('existing-commit');

    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['POST', 'PATCH'], true)
        && ! str_contains($request->url(), '/access_tokens'));
});

test('publisher preserves deployed image tags while restoring preset formatting', function () {
    $currentManifest = "resources:\n- deployment.yaml\nimages:\n- name: ghcr.io/acme/storefront\n  newName: ghcr.io/acme/storefront\n  newTag: sha-1234567\n";
    $generatedManifest = "resources:\n  - deployment.yaml\nimages:\n  - name: ghcr.io/acme/storefront\n    newTag: latest\n";
    $marker = "{\"managed_files\":[\"kustomization.yaml\"]}\n";

    Http::fake(function (Request $request) use ($currentManifest, $marker) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/access_tokens') => Http::response(['token' => 'installation-token']),
            str_contains($url, '/git/ref/heads/main') => Http::response(['object' => ['sha' => 'existing-commit']]),
            str_contains($url, '/git/commits/existing-commit') => Http::response(['tree' => ['sha' => 'existing-tree']]),
            str_contains($url, '/git/trees/existing-tree') => Http::response([
                'tree' => [
                    ['path' => 'deploy/k8s/kustomization.yaml', 'type' => 'blob', 'sha' => gitBlobSha($currentManifest)],
                    ['path' => 'deploy/k8s/.devportal.json', 'type' => 'blob', 'sha' => gitBlobSha($marker)],
                ],
                'truncated' => false,
            ]),
            str_contains($url, '/git/blobs/'.gitBlobSha($currentManifest)) => Http::response([
                'encoding' => 'base64',
                'content' => base64_encode($currentManifest),
            ]),
            str_contains($url, '/git/blobs/'.gitBlobSha($marker)) => Http::response([
                'encoding' => 'base64',
                'content' => base64_encode($marker),
            ]),
            $request->method() === 'POST' && str_contains($url, '/git/trees') => Http::response(['sha' => 'new-tree'], 201),
            $request->method() === 'POST' && str_contains($url, '/git/commits') => Http::response(['sha' => 'new-commit'], 201),
            $request->method() === 'PATCH' && str_contains($url, '/git/refs/heads/main') => Http::response(['object' => ['sha' => 'new-commit']]),
            default => Http::response([], 404),
        };
    });

    app(GitOpsRepositoryPublisher::class)->publish(
        gitOpsTarget(),
        [
            'kustomization.yaml' => $generatedManifest,
            '.devportal.json' => $marker,
        ],
        'chore(deploy): sync manifests',
    );

    $publishedManifest = null;

    Http::assertSent(function (Request $request) use (&$publishedManifest): bool {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/git/trees')) {
            return false;
        }

        $entry = collect($request->data()['tree'])->firstWhere('path', 'deploy/k8s/kustomization.yaml');
        $publishedManifest = $entry['content'] ?? null;

        return true;
    });

    expect($publishedManifest)
        ->toContain("resources:\n  - deployment.yaml")
        ->toContain('newTag: sha-1234567')
        ->not->toContain('newName:', 'newTag: latest');
});

test('publisher refuses to overwrite an unmanaged manifest path', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/access_tokens') => Http::response(['token' => 'installation-token']),
            str_contains($url, '/git/ref/heads/main') => Http::response(['object' => ['sha' => 'existing-commit']]),
            str_contains($url, '/git/commits/existing-commit') => Http::response(['tree' => ['sha' => 'existing-tree']]),
            str_contains($url, '/git/trees/existing-tree') => Http::response([
                'tree' => [
                    ['path' => 'deploy/k8s/kustomization.yaml', 'type' => 'blob', 'sha' => 'user-managed-blob'],
                ],
                'truncated' => false,
            ]),
            default => Http::response([], 404),
        };
    });

    expect(fn () => app(GitOpsRepositoryPublisher::class)->publish(
        gitOpsTarget(),
        ['kustomization.yaml' => "resources: []\n"],
        'chore(deploy): sync manifests',
    ))->toThrow(GitOpsRepositoryException::class, 'not managed by Dev Portal');
});

test('publishing compiled manifests records the git target and marker', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $installation = GitHubInstallation::create([
        'team_id' => $team->id,
        'installation_id' => '4004',
        'account_name' => 'acme',
        'account_type' => 'Organization',
        'permissions' => ['contents' => 'write'],
    ]);
    $project = app(CreateProject::class)->handle($team, [
        'name' => 'Published App',
        'framework' => ProjectFramework::Laravel->value,
        'github_installation_id' => $installation->id,
        'repository' => 'acme/published-app',
        'repository_id' => '404',
        'default_branch' => 'main',
    ]);
    $fakePublisher = new class implements GitOpsRepositoryPublisher
    {
        /** @var array<string, string> */
        public array $files = [];

        public function publish(GitOpsTarget $target, array $files, string $commitMessage): GitOpsPublication
        {
            $this->files = $files;

            return new GitOpsPublication(changed: true, commitSha: 'published-commit');
        }
    };
    $this->app->instance(GitOpsRepositoryPublisher::class, $fakePublisher);

    $publication = app(PublishProjectManifests::class)->handle($project, $user);
    $marker = json_decode($fakePublisher->files['.devportal.json'], true, flags: JSON_THROW_ON_ERROR);
    $revision = $project->manifest->revisions()->where('git_commit_sha', 'published-commit')->firstOrFail();

    expect($publication->changed)->toBeTrue()
        ->and($marker['managed_by'])->toBe('dev-portal')
        ->and($marker['managed_files'])->toContain('kustomization.yaml')
        ->and($revision->status)->toBe(ProjectManifestRevision::StatusPublished)
        ->and($revision->git_repository)->toBe('acme/published-app')
        ->and($revision->git_branch)->toBe('main')
        ->and($revision->git_path)->toBe('deploy/k8s');
});

function gitOpsTarget(): GitOpsTarget
{
    return new GitOpsTarget(
        installationId: '1001',
        repository: 'acme/storefront',
        branch: 'main',
        path: 'deploy/k8s',
        publishMode: GitOpsPublishMode::Direct,
    );
}

function gitBlobSha(string $content): string
{
    return hash('sha1', 'blob '.strlen($content)."\0".$content);
}
