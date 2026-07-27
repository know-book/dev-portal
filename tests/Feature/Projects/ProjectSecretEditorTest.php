<?php

use App\Contracts\ProjectSecretStore;
use App\Data\SecretDocument;
use App\Exceptions\SecretVersionConflict;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->secretStore = new FakeProjectSecretStore;
    app()->instance(ProjectSecretStore::class, $this->secretStore);
});

test('secret values are not loaded when the editor opens', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);
    $this->secretStore->document = new SecretDocument(['APP_KEY' => 'hunter2'], 3);

    Livewire::actingAs($user)
        ->test('pages::projects.secrets', ['project' => $project])
        ->assertSet('revealed', false)
        ->assertDontSee('hunter2');

    expect($this->secretStore->reads)->toBe(0);
});

test('team member can reveal and save a flat json secret with vault cas', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);
    $this->secretStore->document = new SecretDocument(['APP_KEY' => 'hunter2'], 3);

    Livewire::actingAs($user)
        ->test('pages::projects.secrets', ['project' => $project])
        ->call('reveal')
        ->assertHasNoErrors()
        ->assertSet('revealed', true)
        ->assertSet('secretVersion', 3)
        ->set('secretJson', '{"DATABASE_URL":"postgres://db","APP_KEY":"rotated"}')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('secretVersion', 4);

    expect($this->secretStore->writes)->toBe(1)
        ->and($this->secretStore->lastExpectedVersion)->toBe(3)
        ->and($this->secretStore->lastValues)->toBe([
            'APP_KEY' => 'rotated',
            'DATABASE_URL' => 'postgres://db',
        ]);

    $this->assertDatabaseHas('project_secret_revisions', [
        'project_id' => $project->id,
        'vault_version' => 4,
        'updated_by' => $user->id,
    ]);
});

test('secret editor rejects json that is not a flat string object', function (string $json) {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    Livewire::actingAs($user)
        ->test('pages::projects.secrets', ['project' => $project])
        ->call('reveal')
        ->set('secretJson', $json)
        ->call('save')
        ->assertHasErrors(['secretJson']);

    expect($this->secretStore->writes)->toBe(0);
})->with([
    'invalid json' => '{',
    'top-level array' => '["value"]',
    'nested value' => '{"DATABASE":{"HOST":"db"}}',
    'numeric value' => '{"PORT":5432}',
    'invalid environment key' => '{"bad-key":"value"}',
]);

test('secret editor surfaces concurrent vault updates without writing audit metadata', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);
    $this->secretStore->document = new SecretDocument(['APP_KEY' => 'original'], 5);
    $this->secretStore->conflictOnWrite = true;

    Livewire::actingAs($user)
        ->test('pages::projects.secrets', ['project' => $project])
        ->call('reveal')
        ->set('secretJson', '{"APP_KEY":"stale"}')
        ->call('save')
        ->assertHasErrors(['secretJson'])
        ->assertSet('secretVersion', 5);

    $this->assertDatabaseMissing('project_secret_revisions', [
        'project_id' => $project->id,
    ]);
});

test('user cannot view another team project secret editor', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $otherUser->currentTeam->id]);

    $this->actingAs($user)
        ->get(route('projects.secrets', [
            'current_team' => $user->currentTeam->slug,
            'project' => $project->slug,
        ]))
        ->assertNotFound();
});

class FakeProjectSecretStore implements ProjectSecretStore
{
    public SecretDocument $document;

    public int $reads = 0;

    public int $writes = 0;

    public int $lastExpectedVersion = -1;

    /** @var array<string, string> */
    public array $lastValues = [];

    public bool $conflictOnWrite = false;

    public function __construct()
    {
        $this->document = new SecretDocument([], 0);
    }

    public function read(Project $project): SecretDocument
    {
        $this->reads++;

        return $this->document;
    }

    public function write(Project $project, array $values, int $expectedVersion): SecretDocument
    {
        $this->writes++;
        $this->lastValues = $values;
        $this->lastExpectedVersion = $expectedVersion;

        if ($this->conflictOnWrite) {
            throw new SecretVersionConflict('The secret changed after it was revealed.');
        }

        $this->document = new SecretDocument($values, $expectedVersion + 1);

        return $this->document;
    }
}
