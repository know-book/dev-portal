<?php

use App\Contracts\ArgoApplicationGateway;
use App\Data\ArgoApplicationStatus;
use App\Models\Project;
use App\Models\ProjectManifest;
use App\Models\ProjectManifestRevision;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->argo = new FakeArgoApplicationGateway;
    app()->instance(ArgoApplicationGateway::class, $this->argo);
});

test('argocd page does not call the control plane when it opens', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    Livewire::actingAs($user)
        ->test('pages::projects.argocd', ['project' => $project])
        ->assertSet('statusLoaded', false)
        ->assertSet('syncStatus', 'Not checked');

    expect($this->argo->statusCalls)->toBe(0)
        ->and($this->argo->reconcileCalls)->toBe(0)
        ->and($this->argo->syncCalls)->toBe(0);
});

test('team member can reconcile refresh and sync an argocd application', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);
    publishedManifestFor($project, $user);

    Livewire::actingAs($user)
        ->test('pages::projects.argocd', ['project' => $project])
        ->call('reconcile')
        ->assertHasNoErrors()
        ->assertSet('healthStatus', 'Progressing')
        ->call('refreshStatus')
        ->assertHasNoErrors()
        ->assertSet('syncStatus', 'Synced')
        ->assertSet('healthStatus', 'Healthy')
        ->call('sync')
        ->assertHasNoErrors()
        ->assertSet('operationPhase', 'Succeeded');

    expect($this->argo->reconcileCalls)->toBe(1)
        ->and($this->argo->statusCalls)->toBe(1)
        ->and($this->argo->lastHardRefresh)->toBeTrue()
        ->and($this->argo->syncCalls)->toBe(1);
});

test('argocd reconciliation requires published manifests', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    Livewire::actingAs($user)
        ->test('pages::projects.argocd', ['project' => $project])
        ->call('reconcile')
        ->assertHasErrors(['argo']);

    expect($this->argo->reconcileCalls)->toBe(0);
});

test('user cannot view another team project argocd page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $otherUser->currentTeam->id]);

    $this->actingAs($user)
        ->get(route('projects.argocd', [
            'current_team' => $user->currentTeam->slug,
            'project' => $project->slug,
        ]))
        ->assertNotFound();
});

function publishedManifestFor(Project $project, User $user): void
{
    $manifest = ProjectManifest::factory()->create([
        'project_id' => $project->id,
        'variables' => ['namespace' => 'production'],
    ]);

    ProjectManifestRevision::factory()->create([
        'project_manifest_id' => $manifest->id,
        'status' => ProjectManifestRevision::StatusPublished,
        'created_by' => $user->id,
        'published_at' => now(),
    ]);
}

class FakeArgoApplicationGateway implements ArgoApplicationGateway
{
    public int $reconcileCalls = 0;

    public int $statusCalls = 0;

    public int $syncCalls = 0;

    public bool $lastHardRefresh = false;

    public function reconcile(Project $project): ArgoApplicationStatus
    {
        $this->reconcileCalls++;

        return new ArgoApplicationStatus('application', 'Unknown', 'Progressing');
    }

    public function status(Project $project, bool $hardRefresh = false): ?ArgoApplicationStatus
    {
        $this->statusCalls++;
        $this->lastHardRefresh = $hardRefresh;

        return new ArgoApplicationStatus('application', 'Synced', 'Healthy', 'abcdef123456');
    }

    public function sync(Project $project): ArgoApplicationStatus
    {
        $this->syncCalls++;

        return new ArgoApplicationStatus('application', 'Synced', 'Healthy', 'abcdef123456', 'Succeeded');
    }
}
