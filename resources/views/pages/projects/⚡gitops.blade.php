<?php

use App\Actions\Projects\PublishProjectManifests;
use App\Actions\Projects\UpdateProjectGitOpsSettings;
use App\Enums\GitOpsRepositoryMode;
use App\Exceptions\GitOpsRepositoryException;
use App\Models\Project;
use App\Models\ProjectManifestRevision;
use App\Services\GitHub\GitHubAppService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Project GitOps')] class extends Component {
    public Project $project;

    public string $gitOpsRepositoryMode = 'co_located';

    public string $gitOpsInstallationId = '';

    public string $gitOpsRepositoryId = '';

    public string $gitOpsBranch = 'main';

    public string $gitOpsPath = 'deploy/k8s';

    protected GitHubAppService $gitHub;

    public function boot(GitHubAppService $gitHub): void
    {
        $this->gitHub = $gitHub;
    }

    public function mount(Project $project): void
    {
        $team = Auth::user()->currentTeam;

        abort_if($project->team_id !== $team->id, 404);

        $this->project = $project->loadMissing(['team', 'githubInstallation', 'gitOpsGitHubInstallation', 'manifest.revisions']);
        $this->fillFromProject();
    }

    public function updatedGitOpsRepositoryMode(): void
    {
        $this->resetErrorBag();

        if ($this->gitOpsRepositoryMode === GitOpsRepositoryMode::CoLocated->value) {
            $this->gitOpsInstallationId = '';
            $this->gitOpsRepositoryId = '';
            $this->gitOpsBranch = $this->project->gitops_branch ?: $this->project->default_branch;
        }
    }

    public function updatedGitOpsInstallationId(): void
    {
        $this->reset(['gitOpsRepositoryId']);
        $this->gitOpsBranch = 'main';
        $this->resetErrorBag();
    }

    public function updatedGitOpsRepositoryId(): void
    {
        $repository = collect($this->repositories)->firstWhere('id', (int) $this->gitOpsRepositoryId);

        if ($repository) {
            $this->gitOpsBranch = $repository['default_branch'];
        }
    }

    public function save(UpdateProjectGitOpsSettings $updateSettings): void
    {
        $validated = $this->validate([
            'gitOpsRepositoryMode' => ['required', Rule::enum(GitOpsRepositoryMode::class)],
            'gitOpsInstallationId' => [
                Rule::requiredIf($this->gitOpsRepositoryMode === GitOpsRepositoryMode::Separate->value),
                'nullable',
                Rule::exists('github_installations', 'id')->where('team_id', $this->project->team_id),
            ],
            'gitOpsRepositoryId' => [
                Rule::requiredIf($this->gitOpsRepositoryMode === GitOpsRepositoryMode::Separate->value),
                'nullable',
                'string',
                'max:255',
            ],
            'gitOpsBranch' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[A-Za-z0-9._\/-]+\z/',
                'not_regex:/\.\.|\/\/|^\/|\/$/',
            ],
            'gitOpsPath' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[A-Za-z0-9._\/-]+\z/',
                'not_regex:/\.\.|\/\/|^\/|\/$/',
            ],
        ]);

        $this->project = $updateSettings->handle($this->project, Auth::user(), [
            'repository_mode' => $validated['gitOpsRepositoryMode'],
            'installation_id' => $validated['gitOpsInstallationId'] !== '' ? (int) $validated['gitOpsInstallationId'] : null,
            'repository_id' => $validated['gitOpsRepositoryId'] ?: null,
            'branch' => $validated['gitOpsBranch'],
            'path' => $validated['gitOpsPath'],
            'publish_mode' => 'direct',
        ])->loadMissing(['team', 'githubInstallation', 'gitOpsGitHubInstallation', 'manifest.revisions']);

        $this->fillFromProject();

        Flux::toast(variant: 'success', text: __('GitOps repository settings saved.'));
    }

    public function publish(PublishProjectManifests $publishManifests): void
    {
        try {
            $publication = $publishManifests->handle($this->project, Auth::user());
        } catch (GitOpsRepositoryException $exception) {
            $this->addError('publication', $exception->getMessage());

            return;
        }

        $this->project->load('manifest.revisions');

        Flux::toast(
            variant: 'success',
            text: $publication->changed
                ? __('Manifests published at commit :sha.', ['sha' => substr($publication->commitSha, 0, 7)])
                : __('Repository manifests are already up to date.'),
        );
    }

    /**
     * @return Collection<int, \App\Models\GitHubInstallation>
     */
    #[Computed]
    public function installations(): Collection
    {
        return $this->project->team->githubInstallations()->get();
    }

    /**
     * @return array<int, array{id: int, name: string, full_name: string, default_branch: string, html_url: string}>
     */
    #[Computed]
    public function repositories(): array
    {
        if ($this->gitOpsInstallationId === '') {
            return [];
        }

        $installation = $this->project->team->githubInstallations()->find($this->gitOpsInstallationId);

        if (! $installation) {
            return [];
        }

        return collect($this->gitHub->getInstallationRepositories($installation->installation_id))
            ->map(fn (array $repository): array => [
                'id' => (int) $repository['id'],
                'name' => (string) $repository['name'],
                'full_name' => (string) $repository['full_name'],
                'default_branch' => (string) ($repository['default_branch'] ?? 'main'),
                'html_url' => (string) ($repository['html_url'] ?? ''),
            ])
            ->sortBy('full_name')
            ->values()
            ->all();
    }

    #[Computed]
    public function latestPublication(): ?ProjectManifestRevision
    {
        return $this->project->manifest?->revisions()
            ->where('status', ProjectManifestRevision::StatusPublished)
            ->latest('published_at')
            ->first();
    }

    protected function fillFromProject(): void
    {
        $this->gitOpsRepositoryMode = $this->project->gitops_repository_mode->value;
        $this->gitOpsInstallationId = $this->project->gitops_github_installation_id ? (string) $this->project->gitops_github_installation_id : '';
        $this->gitOpsRepositoryId = $this->project->gitops_repository_id ?? '';
        $this->gitOpsBranch = $this->project->gitops_branch ?: $this->project->default_branch;
        $this->gitOpsPath = $this->project->gitops_path;
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
        <div class="flex items-center gap-3">
            <a href="{{ route('projects.show', ['project' => $project->slug]) }}" wire:navigate class="cursor-pointer rounded-md p-2 hover:bg-slate-100 dark:hover:bg-slate-800">
                <flux:icon name="arrow-left" class="size-5 text-slate-500" />
            </a>

            <div>
                <flux:heading size="xl" class="font-semibold text-slate-900 dark:text-slate-100">{{ __('GitOps Repository') }}</flux:heading>
                <flux:subheading class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $project->name }}</flux:subheading>
            </div>
        </div>

        <flux:button variant="primary" icon="cloud-arrow-up" wire:click="publish" wire:loading.attr="disabled" wire:target="publish" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500">
            <span wire:loading.remove wire:target="publish">{{ __('Publish Manifests') }}</span>
            <span wire:loading wire:target="publish">{{ __('Publishing...') }}</span>
        </flux:button>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <form wire:submit="save" class="space-y-6 rounded-md border border-slate-200 bg-white p-6 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
            <div>
                <flux:heading size="lg">{{ __('Repository mode') }}</flux:heading>
                <flux:subheading>{{ __('Choose where Dev Portal reads and writes generated Kubernetes manifests.') }}</flux:subheading>
            </div>

            <flux:field>
                <flux:radio.group wire:model.live="gitOpsRepositoryMode" variant="segmented">
                    <flux:radio value="co_located" icon="folder-git-2">{{ __('Same repository') }}</flux:radio>
                    <flux:radio value="separate" icon="folder">{{ __('Separate repository') }}</flux:radio>
                </flux:radio.group>
                <flux:error name="gitOpsRepositoryMode" />
            </flux:field>

            @if ($gitOpsRepositoryMode === GitOpsRepositoryMode::CoLocated->value)
                <div class="rounded-md border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/60">
                    <flux:text class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Source and GitOps repository') }}</flux:text>
                    <flux:text class="mt-1 font-mono text-sm text-slate-800 dark:text-slate-200">{{ $project->repository ?? __('Not connected') }}</flux:text>
                    <flux:text class="mt-2 text-xs text-slate-500">{{ __('Generated manifests are maintained alongside the application source.') }}</flux:text>
                </div>
            @else
                <div class="grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('GitHub installation') }}</flux:label>
                        <flux:select wire:model.live="gitOpsInstallationId">
                            <flux:select.option value="">{{ __('Select installation') }}</flux:select.option>
                            @foreach ($this->installations as $installation)
                                <flux:select.option wire:key="gitops-installation-{{ $installation->id }}" value="{{ $installation->id }}">
                                    {{ $installation->account_name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="gitOpsInstallationId" />
                    </flux:field>

                    <flux:field>
                        <div class="flex items-center justify-between gap-2">
                            <flux:label>{{ __('GitOps repository') }}</flux:label>
                            <span wire:loading wire:target="gitOpsInstallationId" class="text-xs text-blue-600 dark:text-blue-400">{{ __('Loading...') }}</span>
                        </div>
                        <flux:select wire:model.live="gitOpsRepositoryId">
                            <flux:select.option value="">{{ __('Select repository') }}</flux:select.option>
                            @foreach ($this->repositories as $repository)
                                <flux:select.option wire:key="gitops-repository-{{ $repository['id'] }}" value="{{ $repository['id'] }}">
                                    {{ $repository['full_name'] }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="gitOpsRepositoryId" />
                    </flux:field>
                </div>
            @endif

            <div class="grid gap-5 md:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Deployment branch') }}</flux:label>
                    <flux:input wire:model="gitOpsBranch" placeholder="main" class="font-mono" />
                    <flux:error name="gitOpsBranch" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Manifest path') }}</flux:label>
                    <flux:input wire:model="gitOpsPath" placeholder="deploy/k8s" class="font-mono" />
                    <flux:error name="gitOpsPath" />
                </flux:field>
            </div>

            <flux:callout icon="key" heading="{{ __('Repository access required') }}">
                <flux:text>{{ __('The GitHub App must have Contents: Read and write permission for every selected repository. Access is checked again before publication.') }}</flux:text>
            </flux:callout>

            <div class="flex justify-end">
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500">
                    {{ __('Save GitOps Settings') }}
                </flux:button>
            </div>
        </form>

        <div class="space-y-4">
            <div class="rounded-md border border-slate-200 bg-white p-5 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="sm">{{ __('Publication') }}</flux:heading>
                    <flux:badge color="blue" size="sm" class="font-mono text-2xs uppercase">DIRECT</flux:badge>
                </div>

                @if ($this->latestPublication)
                    <dl class="mt-4 space-y-3 text-xs">
                        <div>
                            <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Repository') }}</dt>
                            <dd class="mt-0.5 break-all font-mono text-slate-700 dark:text-slate-300">{{ $this->latestPublication->git_repository }}</dd>
                        </div>
                        <div>
                            <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Commit') }}</dt>
                            <dd class="mt-0.5 font-mono text-slate-700 dark:text-slate-300">{{ substr((string) $this->latestPublication->git_commit_sha, 0, 12) }}</dd>
                        </div>
                        <div>
                            <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Published') }}</dt>
                            <dd class="mt-0.5 font-mono text-slate-700 dark:text-slate-300">{{ $this->latestPublication->published_at?->format('Y-m-d H:i') }}</dd>
                        </div>
                    </dl>
                @else
                    <flux:text class="mt-3 text-xs text-slate-500">{{ __('No manifests have been published yet.') }}</flux:text>
                @endif
            </div>

            <flux:error name="publication" />

            <div class="rounded-md border border-slate-200 bg-white p-5 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                <flux:heading size="sm">{{ __('Managed files') }}</flux:heading>
                <flux:text class="mt-2 text-xs text-slate-500">{{ __('Dev Portal compares Git blob hashes and creates a commit only when managed files change.') }}</flux:text>
                <div class="mt-3 rounded bg-slate-50 px-3 py-2 font-mono text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    {{ $gitOpsPath }}/.devportal.json
                </div>
            </div>
        </div>
    </div>
</div>
