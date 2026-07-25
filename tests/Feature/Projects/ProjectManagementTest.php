<?php

use App\Enums\ProjectFramework;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('guests are redirected from projects page', function () {
    $response = $this->get('/some-team/projects');

    $response->assertRedirect(route('login'));
});

test('authenticated team members can view projects index', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $project = Project::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee($project->name);
});

test('team member can create a new project via livewire', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->set('name', 'My Laravel App')
        ->set('framework', 'laravel')
        ->set('repository', 'myorg/my-laravel-app')
        ->set('description', 'Test Laravel deployment project')
        ->call('createProject')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('projects', [
        'team_id' => $team->id,
        'name' => 'My Laravel App',
        'slug' => 'my-laravel-app',
        'framework' => 'laravel',
        'repository' => 'myorg/my-laravel-app',
    ]);
});

test('team member can create a project from a selected github repository', function () {
    config([
        'services.github.app_id' => '12345',
        'services.github.private_key' => 'test-key',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'installation-token']),
        'api.github.com/installation/repositories*' => Http::response([
            'repositories' => [
                [
                    'id' => 987654,
                    'name' => 'my-laravel-app',
                    'full_name' => 'myorg/my-laravel-app',
                    'default_branch' => 'main',
                    'html_url' => 'https://github.com/myorg/my-laravel-app',
                ],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $installation = GitHubInstallation::create([
        'team_id' => $team->id,
        'installation_id' => '999888',
        'account_name' => 'myorg',
        'account_type' => 'Organization',
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->set('name', 'My Laravel App')
        ->set('framework', 'laravel')
        ->set('selectedInstallationId', (string) $installation->id)
        ->set('selectedRepositoryId', '987654')
        ->call('createProject')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('projects', [
        'team_id' => $team->id,
        'github_installation_id' => $installation->id,
        'repository' => 'myorg/my-laravel-app',
        'repository_id' => '987654',
        'default_branch' => 'main',
    ]);
});

test('team member cannot create a project from another team github installation', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $installation = GitHubInstallation::create([
        'team_id' => $otherUser->currentTeam->id,
        'installation_id' => '111222',
        'account_name' => 'other-org',
        'account_type' => 'Organization',
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->set('name', 'Blocked App')
        ->set('framework', 'laravel')
        ->set('selectedInstallationId', (string) $installation->id)
        ->call('createProject')
        ->assertHasErrors(['selectedInstallationId']);
});

test('team member can view project details', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $project = Project::factory()->create(['team_id' => $team->id, 'name' => 'Next App', 'framework' => ProjectFramework::NextJs]);

    $this->actingAs($user)
        ->get(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertOk()
        ->assertSee('Next App')
        ->assertSee('Next.js');
});

test('user cannot view project from a team they do not belong to', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $team2 = $user2->currentTeam;

    $project2 = Project::factory()->create(['team_id' => $team2->id]);

    $this->actingAs($user1)
        ->get(route('projects.show', ['current_team' => $team2->slug, 'project' => $project2->slug]))
        ->assertForbidden();
});

test('team member can delete a project', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $project = Project::factory()->create(['team_id' => $team->id]);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('deleteProject', $project->id)
        ->assertHasNoErrors();

    $this->assertSoftDeleted('projects', [
        'id' => $project->id,
    ]);
});
