<?php

use App\Enums\GitOpsRepositoryMode;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();

    config([
        'services.github.app_id' => '12345',
        'services.github.private_key' => 'test-key',
    ]);

    Http::preventStrayRequests();
});

test('team member can configure the source repository for co-located gitops', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $installation = GitHubInstallation::create([
        'team_id' => $team->id,
        'installation_id' => '1001',
        'account_name' => 'acme',
        'account_type' => 'Organization',
        'permissions' => ['contents' => 'write'],
    ]);
    $project = Project::factory()->create([
        'team_id' => $team->id,
        'github_installation_id' => $installation->id,
        'repository' => 'acme/storefront',
        'repository_id' => '501',
        'default_branch' => 'main',
    ]);

    fakeGitHubRepositories([
        githubRepository(501, 'acme/storefront'),
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.gitops', ['project' => $project])
        ->set('gitOpsBranch', 'production')
        ->set('gitOpsPath', 'deploy/platform')
        ->call('save')
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->gitops_repository_mode)->toBe(GitOpsRepositoryMode::CoLocated)
        ->and($project->gitops_repository)->toBeNull()
        ->and($project->gitops_branch)->toBe('production')
        ->and($project->gitops_path)->toBe('deploy/platform');
});

test('team member can configure a separate writable gitops repository', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $installation = GitHubInstallation::create([
        'team_id' => $team->id,
        'installation_id' => '2002',
        'account_name' => 'platform',
        'account_type' => 'Organization',
        'permissions' => ['contents' => 'write'],
    ]);
    $project = Project::factory()->create([
        'team_id' => $team->id,
        'github_installation_id' => $installation->id,
        'repository' => 'platform/app',
        'repository_id' => '901',
    ]);

    fakeGitHubRepositories([
        githubRepository(901, 'platform/app'),
        githubRepository(902, 'platform/gitops', 'trunk'),
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.gitops', ['project' => $project])
        ->set('gitOpsRepositoryMode', GitOpsRepositoryMode::Separate->value)
        ->set('gitOpsInstallationId', (string) $installation->id)
        ->set('gitOpsRepositoryId', '902')
        ->set('gitOpsPath', 'clusters/production/storefront')
        ->call('save')
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->gitops_repository_mode)->toBe(GitOpsRepositoryMode::Separate)
        ->and($project->gitops_github_installation_id)->toBe($installation->id)
        ->and($project->gitops_repository)->toBe('platform/gitops')
        ->and($project->gitops_repository_id)->toBe('902')
        ->and($project->gitops_branch)->toBe('trunk')
        ->and($project->gitops_path)->toBe('clusters/production/storefront');
});

test('gitops settings reject installations without contents write permission', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $installation = GitHubInstallation::create([
        'team_id' => $team->id,
        'installation_id' => '3003',
        'account_name' => 'readonly',
        'account_type' => 'Organization',
        'permissions' => ['contents' => 'read'],
    ]);
    $project = Project::factory()->create([
        'team_id' => $team->id,
        'github_installation_id' => $installation->id,
        'repository' => 'readonly/app',
        'repository_id' => '333',
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.gitops', ['project' => $project])
        ->call('save')
        ->assertHasErrors(['gitOpsRepositoryMode']);
});

test('user cannot view gitops settings for another team project', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $otherUser->currentTeam->id]);

    $this->actingAs($user)
        ->get(route('projects.gitops', [
            'current_team' => $user->currentTeam->slug,
            'project' => $project->slug,
        ]))
        ->assertNotFound();
});

/**
 * @param  list<array<string, mixed>>  $repositories
 */
function fakeGitHubRepositories(array $repositories): void
{
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'installation-token']),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => $repositories]),
    ]);
}

/**
 * @return array<string, mixed>
 */
function githubRepository(int $id, string $fullName, string $defaultBranch = 'main'): array
{
    return [
        'id' => $id,
        'name' => str($fullName)->afterLast('/')->toString(),
        'full_name' => $fullName,
        'default_branch' => $defaultBranch,
        'html_url' => 'https://github.com/'.$fullName,
    ];
}
