<?php

use App\Actions\Projects\GetProjectArgoStatus;
use App\Actions\Projects\GetProjectExternalSecretStatus;
use App\Actions\Projects\GetProjectSecretMetadata;
use App\Actions\Projects\PublishProjectManifests;
use App\Actions\Projects\ReconcileProjectArgoApplication;
use App\Actions\Projects\RefreshProjectExternalSecret;
use App\Actions\Projects\SyncProjectArgoApplication;
use App\Data\ArgoApplicationStatus;
use App\Data\ExternalSecretStatus;
use App\Exceptions\ArgoCdException;
use App\Exceptions\GitOpsRepositoryException;
use App\Exceptions\KubernetesResourceException;
use App\Exceptions\SecretStoreException;
use App\Models\Project;
use App\Models\ProjectManifestRevision;
use App\Services\Manifests\ManifestCompiler;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Deploy Project')] class extends Component {
    #[Locked]
    public Project $project;

    public bool $manifestReady = false;

    public bool $manifestsPublished = false;

    public ?string $publishedCommit = null;

    public string $manifestMessage = '';

    public string $vaultState = 'unchecked';

    public int $vaultVersion = 0;

    public string $argoState = 'unchecked';

    public string $argoSyncStatus = 'Not checked';

    public string $argoHealthStatus = 'Not checked';

    public string $externalSecretState = 'unchecked';

    public ?string $externalSecretReason = null;

    public ?string $externalSecretMessage = null;

    public ?string $externalSecretRefreshTime = null;

    protected ManifestCompiler $compiler;

    public function boot(ManifestCompiler $compiler): void
    {
        $this->compiler = $compiler;
    }

    public function mount(Project $project): void
    {
        $team = Auth::user()->currentTeam;

        abort_if($project->team_id !== $team->id, 404);

        $this->project = $project;
        $this->refreshLocalState();
    }

    public function publish(PublishProjectManifests $publishManifests): void
    {
        $this->resetErrorBag('git');

        if (! $this->manifestReady) {
            $this->addError('git', __('Finish the manifest workspace before publishing.'));

            return;
        }

        try {
            $publication = $publishManifests->handle($this->project, Auth::user());
        } catch (GitOpsRepositoryException $exception) {
            $this->addError('git', $exception->getMessage());

            return;
        } catch (Throwable) {
            $this->addError('git', __('The manifests could not be compiled and published.'));

            return;
        }

        $this->refreshLocalState();
        Flux::toast(
            variant: 'success',
            text: $publication->changed
                ? __('Manifests published at commit :sha.', ['sha' => substr($publication->commitSha, 0, 7)])
                : __('Repository manifests are already up to date.'),
        );
    }

    public function checkVault(GetProjectSecretMetadata $getMetadata): void
    {
        $this->resetErrorBag('vault');

        try {
            $metadata = $getMetadata->handle($this->project, Auth::user());
        } catch (SecretStoreException $exception) {
            $this->vaultState = 'error';
            $this->addError('vault', $exception->getMessage());

            return;
        }

        $this->vaultState = $metadata->exists ? 'exists' : 'missing';
        $this->vaultVersion = $metadata->version;
    }

    public function reconcileArgo(ReconcileProjectArgoApplication $reconcile): void
    {
        $this->resetErrorBag('argo');

        try {
            $status = $reconcile->handle($this->project, Auth::user());
        } catch (ArgoCdException|GitOpsRepositoryException|SecretStoreException $exception) {
            $this->addError('argo', $exception->getMessage());

            return;
        }

        $this->applyArgoStatus($status);
        Flux::toast(variant: 'success', text: __('Argo CD Application reconciled.'));
    }

    public function refreshArgo(GetProjectArgoStatus $getStatus): void
    {
        $this->resetErrorBag('argo');

        try {
            $status = $getStatus->handle($this->project, Auth::user(), hardRefresh: true);
        } catch (ArgoCdException $exception) {
            $this->argoState = 'error';
            $this->addError('argo', $exception->getMessage());

            return;
        }

        if (! $status) {
            $this->argoState = 'missing';
            $this->argoSyncStatus = 'Missing';
            $this->argoHealthStatus = 'Missing';

            return;
        }

        $this->applyArgoStatus($status);
    }

    public function syncArgo(SyncProjectArgoApplication $sync): void
    {
        $this->resetErrorBag('argo');

        try {
            $status = $sync->handle($this->project, Auth::user());
        } catch (ArgoCdException $exception) {
            $this->addError('argo', $exception->getMessage());

            return;
        }

        $this->applyArgoStatus($status);
        Flux::toast(variant: 'success', text: __('Argo CD synchronization requested.'));
    }

    public function checkExternalSecret(GetProjectExternalSecretStatus $getStatus): void
    {
        $this->resetErrorBag('externalSecret');

        try {
            $status = $getStatus->handle($this->project, Auth::user());
        } catch (KubernetesResourceException $exception) {
            $this->externalSecretState = 'error';
            $this->addError('externalSecret', $exception->getMessage());

            return;
        }

        $this->applyExternalSecretStatus($status);
    }

    public function refreshExternalSecret(RefreshProjectExternalSecret $refresh): void
    {
        $this->resetErrorBag('externalSecret');

        try {
            $status = $refresh->handle($this->project, Auth::user());
        } catch (KubernetesResourceException $exception) {
            $this->externalSecretState = 'error';
            $this->addError('externalSecret', $exception->getMessage());

            return;
        }

        $this->applyExternalSecretStatus($status);
        Flux::toast(variant: 'success', text: __('External Secrets Operator refresh requested.'));
    }

    #[Computed]
    public function currentStep(): int
    {
        return match (true) {
            ! $this->manifestReady => 1,
            ! $this->manifestsPublished => 2,
            $this->vaultState !== 'exists' => 3,
            $this->argoState !== 'exists' => 4,
            $this->argoSyncStatus !== 'Synced' => 5,
            $this->externalSecretState !== 'ready' => 6,
            default => 7,
        };
    }

    public function isComplete(int $step): bool
    {
        return $step < $this->currentStep;
    }

    public function stepContainerClass(int $step): string
    {
        return match (true) {
            $this->isComplete($step) => 'border-emerald-200 bg-emerald-50/40 dark:border-emerald-900/70 dark:bg-emerald-950/20',
            $this->currentStep === $step => 'border-blue-300 bg-blue-50/50 ring-1 ring-blue-200 dark:border-blue-700 dark:bg-blue-950/20 dark:ring-blue-900',
            default => 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900',
        };
    }

    public function stepNumberClass(int $step): string
    {
        return match (true) {
            $this->isComplete($step) => 'bg-emerald-600 text-white',
            $this->currentStep === $step => 'bg-blue-600 text-white',
            default => 'bg-slate-200 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
        };
    }

    protected function refreshLocalState(): void
    {
        $freshProject = $this->project->fresh([
            'team',
            'manifest.patches',
            'manifest.revisions',
            'githubInstallation',
            'gitOpsGitHubInstallation',
        ]);

        if ($freshProject) {
            $this->project = $freshProject;
        }

        $manifest = $this->project->manifest;
        $this->manifestReady = $this->project->initialization_status === Project::InitializationReady && $manifest !== null;
        $this->manifestsPublished = false;
        $this->publishedCommit = null;
        $this->manifestMessage = $this->manifestReady
            ? __('Manifest workspace is ready.')
            : __('Manifest workspace is still initializing or needs attention.');

        if (! $manifest) {
            return;
        }

        try {
            $compiledHash = $this->compiler->compiledHash($manifest);
        } catch (Throwable) {
            $this->manifestReady = false;
            $this->manifestMessage = __('The current manifest workspace cannot be compiled.');

            return;
        }

        $publication = $manifest->revisions
            ->where('status', ProjectManifestRevision::StatusPublished)
            ->sortByDesc('revision_number')
            ->first();

        $this->manifestsPublished = $publication?->compiled_hash === $compiledHash
            && filled($publication?->git_commit_sha);
        $this->publishedCommit = $this->manifestsPublished
            ? $publication?->git_commit_sha
            : null;
    }

    protected function applyArgoStatus(ArgoApplicationStatus $status): void
    {
        $this->argoState = 'exists';
        $this->argoSyncStatus = $status->syncStatus;
        $this->argoHealthStatus = $status->healthStatus;
    }

    protected function applyExternalSecretStatus(ExternalSecretStatus $status): void
    {
        $this->externalSecretState = match (true) {
            ! $status->exists => 'missing',
            $status->ready => 'ready',
            default => 'not_ready',
        };
        $this->externalSecretReason = $status->reason;
        $this->externalSecretMessage = $status->message;
        $this->externalSecretRefreshTime = $status->refreshTime;
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
                <div class="flex items-center gap-2">
                    <flux:heading size="xl" class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Deploy :project', ['project' => $project->name]) }}</flux:heading>
                    @if ($this->currentStep > 6)
                        <flux:badge color="emerald" size="sm" class="font-mono text-2xs uppercase">{{ __('Ready') }}</flux:badge>
                    @else
                        <flux:badge color="blue" size="sm" class="font-mono text-2xs uppercase">{{ __('Step :step of 6', ['step' => $this->currentStep]) }}</flux:badge>
                    @endif
                </div>
                <flux:subheading>{{ __('Follow the control-plane lifecycle in order. Every completion state is verified from its source system.') }}</flux:subheading>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <flux:button variant="filled" icon="arrow-path" wire:click="checkVault" wire:loading.attr="disabled" wire:target="checkVault" class="cursor-pointer">
                {{ __('Check Vault') }}
            </flux:button>
            <flux:button variant="filled" icon="arrow-path" wire:click="refreshArgo" wire:loading.attr="disabled" wire:target="refreshArgo" class="cursor-pointer">
                {{ __('Check Argo CD') }}
            </flux:button>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-4">
            <section class="{{ $this->stepContainerClass(1) }} rounded-md border p-5 shadow-2xs">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex gap-4">
                        <div class="{{ $this->stepNumberClass(1) }} flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-xs font-semibold">{{ $this->isComplete(1) ? '✓' : '1' }}</div>
                        <div>
                            <flux:heading size="sm">{{ __('Prepare manifest workspace') }}</flux:heading>
                            <flux:text class="mt-1 text-xs text-slate-500">{{ $manifestMessage }}</flux:text>
                        </div>
                    </div>
                    <flux:button variant="filled" icon="document-text" :href="route('projects.manifests', ['project' => $project->slug])" wire:navigate class="cursor-pointer">{{ __('Open Manifests') }}</flux:button>
                </div>
            </section>

            <section class="{{ $this->stepContainerClass(2) }} rounded-md border p-5 shadow-2xs">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex gap-4">
                        <div class="{{ $this->stepNumberClass(2) }} flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-xs font-semibold">{{ $this->isComplete(2) ? '✓' : '2' }}</div>
                        <div>
                            <flux:heading size="sm">{{ __('Publish manifests to Git') }}</flux:heading>
                            <flux:text class="mt-1 text-xs text-slate-500">
                                @if ($manifestsPublished)
                                    {{ __('Current manifests are published at :sha.', ['sha' => substr((string) $publishedCommit, 0, 12)]) }}
                                @else
                                    {{ __('Write the compiled manifest tree to the configured GitOps repository.') }}
                                @endif
                            </flux:text>
                            <flux:error name="git" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button variant="ghost" :href="route('projects.gitops', ['project' => $project->slug])" wire:navigate class="cursor-pointer">{{ __('Settings') }}</flux:button>
                        <flux:button variant="primary" icon="cloud-arrow-up" wire:click="publish" wire:loading.attr="disabled" wire:target="publish" :disabled="! $manifestReady" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500">{{ __('Publish') }}</flux:button>
                    </div>
                </div>
            </section>

            <section class="{{ $this->stepContainerClass(3) }} rounded-md border p-5 shadow-2xs">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex gap-4">
                        <div class="{{ $this->stepNumberClass(3) }} flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-xs font-semibold">{{ $this->isComplete(3) ? '✓' : '3' }}</div>
                        <div>
                            <div class="flex items-center gap-2">
                                <flux:heading size="sm">{{ __('Create project secret in Vault') }}</flux:heading>
                                @if ($vaultState === 'exists')
                                    <flux:badge color="emerald" size="sm" class="font-mono text-2xs">v{{ $vaultVersion }}</flux:badge>
                                @elseif ($vaultState === 'missing')
                                    <flux:badge color="red" size="sm" class="font-mono text-2xs uppercase">{{ __('Missing') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text class="mt-1 text-xs text-slate-500">{{ __('Save the flat JSON environment document. The status check reads metadata only, never secret values.') }}</flux:text>
                            <flux:error name="vault" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button variant="ghost" wire:click="checkVault" wire:loading.attr="disabled" wire:target="checkVault" class="cursor-pointer">{{ __('Check') }}</flux:button>
                        <flux:button variant="primary" icon="key" :href="route('projects.secrets', ['project' => $project->slug])" wire:navigate :disabled="! $manifestsPublished" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500">{{ $vaultState === 'exists' ? __('Edit Secret') : __('Create Secret') }}</flux:button>
                    </div>
                </div>
            </section>

            <section class="{{ $this->stepContainerClass(4) }} rounded-md border p-5 shadow-2xs">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex gap-4">
                        <div class="{{ $this->stepNumberClass(4) }} flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-xs font-semibold">{{ $this->isComplete(4) ? '✓' : '4' }}</div>
                        <div>
                            <flux:heading size="sm">{{ __('Create Argo CD Application') }}</flux:heading>
                            <flux:text class="mt-1 text-xs text-slate-500">{{ $argoState === 'exists' ? __('Application CRD exists in the Argo CD namespace.') : __('Reconcile an Application CRD derived from the published Git target.') }}</flux:text>
                            <flux:error name="argo" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button variant="ghost" wire:click="refreshArgo" wire:loading.attr="disabled" wire:target="refreshArgo" class="cursor-pointer">{{ __('Check') }}</flux:button>
                        <flux:button variant="primary" icon="cloud-arrow-up" wire:click="reconcileArgo" wire:loading.attr="disabled" wire:target="reconcileArgo" :disabled="! $manifestsPublished || $vaultState !== 'exists'" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500">{{ __('Reconcile CRD') }}</flux:button>
                    </div>
                </div>
            </section>

            <section class="{{ $this->stepContainerClass(5) }} rounded-md border p-5 shadow-2xs">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex gap-4">
                        <div class="{{ $this->stepNumberClass(5) }} flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-xs font-semibold">{{ $this->isComplete(5) ? '✓' : '5' }}</div>
                        <div>
                            <div class="flex items-center gap-2">
                                <flux:heading size="sm">{{ __('Sync Argo CD') }}</flux:heading>
                                <flux:badge color="{{ $argoSyncStatus === 'Synced' ? 'emerald' : ($argoSyncStatus === 'OutOfSync' ? 'amber' : 'zinc') }}" size="sm" class="font-mono text-2xs uppercase">{{ $argoSyncStatus }}</flux:badge>
                                <flux:badge color="{{ $argoHealthStatus === 'Healthy' ? 'emerald' : 'zinc' }}" size="sm" class="font-mono text-2xs uppercase">{{ $argoHealthStatus }}</flux:badge>
                            </div>
                            <flux:text class="mt-1 text-xs text-slate-500">{{ __('Ask Argo CD to reconcile the published Git revision with the target cluster.') }}</flux:text>
                        </div>
                    </div>
                    <flux:button variant="primary" icon="play" wire:click="syncArgo" wire:loading.attr="disabled" wire:target="syncArgo" :disabled="$argoState !== 'exists'" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500">{{ __('Sync') }}</flux:button>
                </div>
            </section>

            <section class="{{ $this->stepContainerClass(6) }} rounded-md border p-5 shadow-2xs">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex gap-4">
                        <div class="{{ $this->stepNumberClass(6) }} flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-xs font-semibold">{{ $this->isComplete(6) ? '✓' : '6' }}</div>
                        <div>
                            <div class="flex items-center gap-2">
                                <flux:heading size="sm">{{ __('Verify Vault-to-Kubernetes sync') }}</flux:heading>
                                @if ($externalSecretState === 'ready')
                                    <flux:badge color="emerald" size="sm" class="font-mono text-2xs uppercase">{{ __('Ready') }}</flux:badge>
                                @elseif (in_array($externalSecretState, ['missing', 'not_ready'], true))
                                    <flux:badge color="amber" size="sm" class="font-mono text-2xs uppercase">{{ $externalSecretReason ?? __('Pending') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text class="mt-1 text-xs text-slate-500">{{ $externalSecretMessage ?? __('Verify that External Secrets Operator created and refreshed the target Kubernetes Secret.') }}</flux:text>
                            @if ($externalSecretRefreshTime)
                                <flux:text class="mt-1 font-mono text-2xs text-slate-400">{{ __('Last refresh: :time', ['time' => $externalSecretRefreshTime]) }}</flux:text>
                            @endif
                            <flux:error name="externalSecret" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button variant="ghost" wire:click="checkExternalSecret" wire:loading.attr="disabled" wire:target="checkExternalSecret" :disabled="$argoSyncStatus !== 'Synced'" class="cursor-pointer">{{ __('Check') }}</flux:button>
                        <flux:button variant="primary" icon="arrow-path" wire:click="refreshExternalSecret" wire:loading.attr="disabled" wire:target="refreshExternalSecret" :disabled="$argoSyncStatus !== 'Synced'" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500">{{ __('Force Sync') }}</flux:button>
                    </div>
                </div>
            </section>
        </div>

        <div class="space-y-4">
            @if ($this->currentStep > 6)
                <flux:callout variant="success" icon="check-circle" heading="{{ __('Deployment workflow complete') }}">
                    <flux:text>{{ __('Git, Vault, Argo CD, and External Secrets Operator all report the expected state.') }}</flux:text>
                </flux:callout>
            @else
                <flux:callout icon="information-circle" heading="{{ __('Current step: :step', ['step' => $this->currentStep]) }}">
                    <flux:text>{{ __('Complete each verified step before moving to the next control-plane action.') }}</flux:text>
                </flux:callout>
            @endif

            <div class="rounded-md border border-slate-200 bg-white p-5 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                <flux:heading size="sm">{{ __('Deployment target') }}</flux:heading>
                <dl class="mt-3 space-y-3 text-xs">
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Repository') }}</dt>
                        <dd class="mt-0.5 break-all font-mono text-slate-700 dark:text-slate-300">{{ $project->gitops_repository ?? $project->repository ?? __('Not configured') }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Branch') }}</dt>
                        <dd class="mt-0.5 font-mono text-slate-700 dark:text-slate-300">{{ $project->gitops_branch ?: $project->default_branch }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Path') }}</dt>
                        <dd class="mt-0.5 font-mono text-slate-700 dark:text-slate-300">{{ $project->gitops_path }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Vault path') }}</dt>
                        <dd class="mt-0.5 break-all font-mono text-slate-700 dark:text-slate-300">{{ config('services.vault.mount', 'secret') }}/{{ $project->team->slug }}/{{ $project->slug }}/app</dd>
                    </div>
                </dl>
            </div>

            <flux:callout icon="key" heading="{{ __('No secret values are read') }}">
                <flux:text>{{ __('This workflow checks Vault metadata and ExternalSecret conditions only. Values remain visible only inside the explicit Secret editor.') }}</flux:text>
            </flux:callout>
        </div>
    </div>
</div>
