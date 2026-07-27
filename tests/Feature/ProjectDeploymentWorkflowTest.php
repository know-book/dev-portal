<?php

use App\Actions\Projects\CreateProject;
use App\Contracts\ArgoApplicationGateway;
use App\Contracts\GitOpsRepositoryPublisher;
use App\Contracts\ProjectSecretStore;
use App\Data\ArgoApplicationStatus;
use App\Data\ExternalSecretStatus;
use App\Data\GitOpsPublication;
use App\Data\GitOpsTarget;
use App\Data\SecretDocument;
use App\Data\SecretMetadata;
use App\Enums\ProjectFramework;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use App\Services\Kubernetes\KubernetesExternalSecretClient;
use Livewire\Livewire;

beforeEach(function () {
    $this->publisher = new WorkflowGitOpsPublisher;
    $this->secretStore = new WorkflowSecretStore;
    $this->argo = new WorkflowArgoGateway;
    $this->externalSecrets = new WorkflowExternalSecretClient;

    app()->instance(GitOpsRepositoryPublisher::class, $this->publisher);
    app()->instance(ProjectSecretStore::class, $this->secretStore);
    app()->instance(ArgoApplicationGateway::class, $this->argo);
    app()->instance(KubernetesExternalSecretClient::class, $this->externalSecrets);
});

test('deployment workflow advances only from verified source-system states', function () {
    [$user, $project] = deploymentProject();

    $component = Livewire::actingAs($user)
        ->test('pages::projects.deploy', ['project' => $project])
        ->assertSet('manifestReady', true)
        ->assertSet('manifestsPublished', false)
        ->assertSet('vaultState', 'unchecked')
        ->assertSee('Step 2 of 6');

    expect($this->secretStore->metadataReads)->toBe(0)
        ->and($this->argo->statusCalls)->toBe(0)
        ->and($this->externalSecrets->statusCalls)->toBe(0);

    $component
        ->call('publish')
        ->assertHasNoErrors()
        ->assertSet('manifestsPublished', true)
        ->assertSee('Step 3 of 6');

    $this->secretStore->exists = true;
    $this->secretStore->version = 2;

    $component
        ->call('checkVault')
        ->assertHasNoErrors()
        ->assertSet('vaultState', 'exists')
        ->assertSet('vaultVersion', 2)
        ->assertSee('Step 4 of 6')
        ->call('reconcileArgo')
        ->assertHasNoErrors()
        ->assertSet('argoState', 'exists')
        ->assertSet('argoSyncStatus', 'OutOfSync')
        ->assertSee('Step 5 of 6')
        ->call('syncArgo')
        ->assertHasNoErrors()
        ->assertSet('argoSyncStatus', 'Synced')
        ->assertSee('Step 6 of 6');

    $this->externalSecrets->status = new ExternalSecretStatus(
        exists: true,
        ready: true,
        reason: 'SecretSynced',
        message: 'Secret was synced',
        refreshTime: '2026-07-27T12:00:00Z',
    );

    $component
        ->call('refreshExternalSecret')
        ->assertHasNoErrors()
        ->assertSet('externalSecretState', 'ready')
        ->assertSee('Deployment workflow complete');

    expect($this->publisher->publishes)->toBe(1)
        ->and($this->secretStore->metadataReads)->toBe(2)
        ->and($this->argo->reconcileCalls)->toBe(1)
        ->and($this->argo->syncCalls)->toBe(1)
        ->and($this->externalSecrets->refreshCalls)->toBe(1);
});

test('deployment workflow blocks argo application creation until vault secret exists', function () {
    [$user, $project] = deploymentProject();

    Livewire::actingAs($user)
        ->test('pages::projects.deploy', ['project' => $project])
        ->call('publish')
        ->call('checkVault')
        ->assertSet('vaultState', 'missing')
        ->call('reconcileArgo')
        ->assertHasErrors(['argo']);

    expect($this->argo->reconcileCalls)->toBe(0);
});

test('project show deploy button opens the deployment workflow', function () {
    [$user, $project] = deploymentProject();

    $this->actingAs($user)
        ->get(route('projects.show', [
            'current_team' => $user->currentTeam->slug,
            'project' => $project->slug,
        ]))
        ->assertOk()
        ->assertSee(route('projects.deploy', ['project' => $project->slug]), escape: false);
});

test('user cannot view another team project deployment workflow', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $otherUser->currentTeam->id]);

    $this->actingAs($user)
        ->get(route('projects.deploy', [
            'current_team' => $user->currentTeam->slug,
            'project' => $project->slug,
        ]))
        ->assertNotFound();
});

/** @return array{User, Project} */
function deploymentProject(): array
{
    $user = User::factory()->create();
    $installation = GitHubInstallation::create([
        'team_id' => $user->currentTeam->id,
        'installation_id' => '7007',
        'account_name' => 'acme',
        'account_type' => 'Organization',
        'permissions' => ['contents' => 'write'],
    ]);
    $project = app(CreateProject::class)->handle($user->currentTeam, [
        'name' => 'Workflow App',
        'framework' => ProjectFramework::Laravel->value,
        'github_installation_id' => $installation->id,
        'repository' => 'acme/workflow-app',
        'repository_id' => '707',
        'default_branch' => 'main',
    ])->refresh();

    return [$user, $project];
}

class WorkflowGitOpsPublisher implements GitOpsRepositoryPublisher
{
    public int $publishes = 0;

    public function publish(GitOpsTarget $target, array $files, string $commitMessage): GitOpsPublication
    {
        $this->publishes++;

        return new GitOpsPublication(changed: true, commitSha: 'workflow-commit');
    }
}

class WorkflowSecretStore implements ProjectSecretStore
{
    public bool $exists = false;

    public int $version = 0;

    public int $metadataReads = 0;

    public function read(Project $project): SecretDocument
    {
        return new SecretDocument([], $this->version);
    }

    public function metadata(Project $project): SecretMetadata
    {
        $this->metadataReads++;

        return new SecretMetadata($this->exists, $this->version);
    }

    public function write(Project $project, array $values, int $expectedVersion): SecretDocument
    {
        return new SecretDocument($values, $expectedVersion + 1);
    }
}

class WorkflowArgoGateway implements ArgoApplicationGateway
{
    public int $reconcileCalls = 0;

    public int $statusCalls = 0;

    public int $syncCalls = 0;

    public function reconcile(Project $project): ArgoApplicationStatus
    {
        $this->reconcileCalls++;

        return new ArgoApplicationStatus('workflow-app', 'OutOfSync', 'Missing');
    }

    public function status(Project $project, bool $hardRefresh = false): ?ArgoApplicationStatus
    {
        $this->statusCalls++;

        return new ArgoApplicationStatus('workflow-app', 'OutOfSync', 'Progressing');
    }

    public function sync(Project $project): ArgoApplicationStatus
    {
        $this->syncCalls++;

        return new ArgoApplicationStatus('workflow-app', 'Synced', 'Healthy');
    }
}

class WorkflowExternalSecretClient extends KubernetesExternalSecretClient
{
    public int $statusCalls = 0;

    public int $refreshCalls = 0;

    public ExternalSecretStatus $status;

    public function __construct()
    {
        $this->status = new ExternalSecretStatus(false, false, 'NotFound');
    }

    public function status(Project $project): ExternalSecretStatus
    {
        $this->statusCalls++;

        return $this->status;
    }

    public function refresh(Project $project): ExternalSecretStatus
    {
        $this->refreshCalls++;

        return $this->status;
    }
}
