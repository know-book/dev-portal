<?php

use App\Actions\Projects\GetProjectArgoStatus;
use App\Actions\Projects\ReconcileProjectArgoApplication;
use App\Actions\Projects\SyncProjectArgoApplication;
use App\Data\ArgoApplicationStatus;
use App\Exceptions\ArgoCdException;
use App\Exceptions\GitOpsRepositoryException;
use App\Models\Project;
use App\Services\ArgoCd\ArgoApplicationDefinitionFactory;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Argo CD Application')] class extends Component {
    #[Locked]
    public Project $project;

    public bool $statusLoaded = false;

    public string $syncStatus = 'Not checked';

    public string $healthStatus = 'Not checked';

    public ?string $revision = null;

    public ?string $operationPhase = null;

    public ?string $statusMessage = null;

    protected ArgoApplicationDefinitionFactory $definitions;

    public function boot(ArgoApplicationDefinitionFactory $definitions): void
    {
        $this->definitions = $definitions;
    }

    public function mount(Project $project): void
    {
        $team = Auth::user()->currentTeam;

        abort_if($project->team_id !== $team->id, 404);

        $this->project = $project->loadMissing(['team', 'manifest.revisions', 'githubInstallation', 'gitOpsGitHubInstallation']);
    }

    public function reconcile(ReconcileProjectArgoApplication $reconcile): void
    {
        $this->resetErrorBag();

        try {
            $status = $reconcile->handle($this->project, Auth::user());
        } catch (ArgoCdException|GitOpsRepositoryException $exception) {
            $this->addError('argo', $exception->getMessage());

            return;
        }

        $this->applyStatus($status);
        Flux::toast(variant: 'success', text: __('Argo CD Application reconciled through the Kubernetes API.'));
    }

    public function refreshStatus(GetProjectArgoStatus $getStatus): void
    {
        $this->resetErrorBag();

        try {
            $status = $getStatus->handle($this->project, Auth::user(), hardRefresh: true);
        } catch (ArgoCdException $exception) {
            $this->addError('argo', $exception->getMessage());

            return;
        }

        if (! $status) {
            $this->statusLoaded = true;
            $this->syncStatus = 'Missing';
            $this->healthStatus = 'Missing';
            $this->revision = null;
            $this->operationPhase = null;
            $this->statusMessage = __('Create the Application CRD before requesting a sync.');

            return;
        }

        $this->applyStatus($status);
    }

    public function sync(SyncProjectArgoApplication $sync): void
    {
        $this->resetErrorBag();

        try {
            $status = $sync->handle($this->project, Auth::user());
        } catch (ArgoCdException $exception) {
            $this->addError('argo', $exception->getMessage());

            return;
        }

        $this->applyStatus($status);
        Flux::toast(variant: 'success', text: __('Argo CD synchronization requested.'));
    }

    #[Computed]
    public function applicationName(): string
    {
        return $this->definitions->applicationName($this->project);
    }

    public function syncBadgeColor(): string
    {
        return match ($this->syncStatus) {
            'Synced' => 'emerald',
            'OutOfSync' => 'amber',
            'Missing' => 'red',
            default => 'zinc',
        };
    }

    public function healthBadgeColor(): string
    {
        return match ($this->healthStatus) {
            'Healthy' => 'emerald',
            'Progressing', 'Suspended' => 'amber',
            'Degraded', 'Missing' => 'red',
            default => 'zinc',
        };
    }

    protected function applyStatus(ArgoApplicationStatus $status): void
    {
        $this->statusLoaded = true;
        $this->syncStatus = $status->syncStatus;
        $this->healthStatus = $status->healthStatus;
        $this->revision = $status->revision;
        $this->operationPhase = $status->operationPhase;
        $this->statusMessage = $status->message;
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
                    <flux:heading size="xl" class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Argo CD Application') }}</flux:heading>
                    <flux:badge color="blue" size="sm" class="font-mono text-2xs uppercase">CRD + REST</flux:badge>
                </div>
                <flux:subheading class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $this->applicationName }}</flux:subheading>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <flux:button variant="filled" icon="arrow-path" wire:click="refreshStatus" wire:loading.attr="disabled" wire:target="refreshStatus" class="cursor-pointer">
                {{ __('Refresh Status') }}
            </flux:button>
            <flux:button variant="filled" icon="cloud-arrow-up" wire:click="reconcile" wire:loading.attr="disabled" wire:target="reconcile" class="cursor-pointer">
                {{ __('Reconcile CRD') }}
            </flux:button>
            <flux:button variant="primary" icon="play" wire:click="sync" wire:loading.attr="disabled" wire:target="sync" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500">
                {{ __('Sync') }}
            </flux:button>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-md border border-slate-200 bg-white p-5 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between gap-3">
                        <flux:heading size="sm">{{ __('Sync status') }}</flux:heading>
                        <flux:badge color="{{ $this->syncBadgeColor() }}" size="sm" class="font-mono text-2xs uppercase">{{ $syncStatus }}</flux:badge>
                    </div>
                    <flux:text class="mt-3 text-xs text-slate-500">{{ __('Comparison of the Git revision against live cluster resources.') }}</flux:text>
                </div>

                <div class="rounded-md border border-slate-200 bg-white p-5 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between gap-3">
                        <flux:heading size="sm">{{ __('Health status') }}</flux:heading>
                        <flux:badge color="{{ $this->healthBadgeColor() }}" size="sm" class="font-mono text-2xs uppercase">{{ $healthStatus }}</flux:badge>
                    </div>
                    <flux:text class="mt-3 text-xs text-slate-500">{{ __('Aggregated health reported by the Argo CD application controller.') }}</flux:text>
                </div>
            </div>

            <div class="rounded-md border border-slate-200 bg-white p-6 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg">{{ __('Application source') }}</flux:heading>
                    <flux:badge color="zinc" size="sm" class="font-mono text-2xs uppercase">{{ $project->gitops_repository_mode->label() }}</flux:badge>
                </div>

                <dl class="mt-5 grid gap-5 text-xs sm:grid-cols-2">
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Repository') }}</dt>
                        <dd class="mt-1 break-all font-mono text-slate-700 dark:text-slate-300">{{ $project->gitops_repository ?? $project->repository ?? __('Not configured') }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Revision') }}</dt>
                        <dd class="mt-1 font-mono text-slate-700 dark:text-slate-300">{{ $project->gitops_branch ?: $project->default_branch }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Manifest path') }}</dt>
                        <dd class="mt-1 font-mono text-slate-700 dark:text-slate-300">{{ $project->gitops_path }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Deployed revision') }}</dt>
                        <dd class="mt-1 font-mono text-slate-700 dark:text-slate-300">{{ $revision ? substr($revision, 0, 12) : '—' }}</dd>
                    </div>
                </dl>

                @if ($operationPhase || $statusMessage)
                    <div class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <flux:text class="text-2xs uppercase tracking-wider text-slate-400">{{ $operationPhase ?? __('Controller message') }}</flux:text>
                        <flux:text class="mt-1 text-xs text-slate-600 dark:text-slate-300">{{ $statusMessage ?? __('Operation is in progress.') }}</flux:text>
                    </div>
                @endif
            </div>

            <flux:error name="argo" />
        </div>

        <div class="space-y-4">
            <flux:callout icon="folder-git-2" heading="{{ __('Git remains authoritative') }}">
                <flux:text>{{ __('The Application CRD points Argo CD at the selected repository, branch, and path. Dev Portal never applies workload manifests directly.') }}</flux:text>
            </flux:callout>

            <flux:callout icon="key" heading="{{ __('Repository credentials') }}">
                <flux:text>{{ __('Private Git repositories must also be registered in Argo CD. GitHub App access used by Dev Portal is not copied into Argo CD.') }}</flux:text>
            </flux:callout>

            <div class="rounded-md border border-slate-200 bg-white p-5 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                <flux:heading size="sm">{{ __('Control plane') }}</flux:heading>
                <dl class="mt-3 space-y-3 text-xs">
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Application namespace') }}</dt>
                        <dd class="mt-0.5 font-mono text-slate-700 dark:text-slate-300">{{ config('services.argocd.namespace', 'argocd') }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Argo project') }}</dt>
                        <dd class="mt-0.5 font-mono text-slate-700 dark:text-slate-300">{{ config('services.argocd.project', 'default') }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>
