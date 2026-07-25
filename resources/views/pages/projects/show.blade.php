<?php

use App\Enums\ProjectFramework;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Project Details')] class extends Component {
    public Project $project;

    public function mount(Project $project): void
    {
        // Ensure project belongs to current team
        $team = Auth::user()->currentTeam;
        if ($project->team_id !== $team->id) {
            abort(404);
        }

        $this->project = $project->loadMissing(['manifest', 'team']);
    }

    public function deleteProject(): void
    {
        $team = Auth::user()->currentTeam;
        if ($this->project->team_id !== $team->id) {
            abort(403);
        }

        $projectName = $this->project->name;
        $this->project->delete();

        Flux::modal('delete-project')->close();

        Flux::toast(variant: 'success', text: __('Project ":name" deleted.', ['name' => $projectName]));

        $this->redirectRoute('projects.index', navigate: true);
    }

    public function initializationBadgeColor(): string
    {
        return match ($this->project->initialization_status) {
            Project::InitializationReady => 'emerald',
            Project::InitializationFailed => 'red',
            Project::InitializationInitializing => 'amber',
            default => 'zinc',
        };
    }

    public function initializationDotClass(): string
    {
        return match ($this->project->initialization_status) {
            Project::InitializationReady => 'bg-emerald-500',
            Project::InitializationFailed => 'bg-red-500',
            Project::InitializationInitializing => 'bg-amber-500',
            default => 'bg-slate-400',
        };
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <!-- VMware Clarity Enterprise Header -->
    <div class="flex flex-col gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
        <div class="flex items-center gap-3">
            <a href="{{ route('projects.index') }}" wire:navigate class="cursor-pointer rounded-md p-2 hover:bg-slate-100 dark:hover:bg-slate-800">
                <flux:icon name="arrow-left" class="size-5 text-slate-500" />
            </a>

            <div>
                <div class="flex items-center gap-2">
                    <flux:heading size="xl" class="font-semibold text-slate-900 dark:text-slate-100">{{ $project->name }}</flux:heading>
                    @if ($project->framework === ProjectFramework::Laravel)
                        <flux:badge color="red" size="sm" class="font-mono text-2xs uppercase">{{ $project->framework->label() }}</flux:badge>
                    @elseif ($project->framework === ProjectFramework::NextJs)
                        <flux:badge color="blue" size="sm" class="font-mono text-2xs uppercase">{{ $project->framework->label() }}</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm" class="font-mono text-2xs uppercase">{{ $project->framework->label() }}</flux:badge>
                    @endif
                    <flux:badge color="{{ $this->initializationBadgeColor() }}" size="sm" class="font-mono text-2xs uppercase">{{ $project->initialization_status }}</flux:badge>
                </div>
                @if ($project->repository)
                    <flux:subheading class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $project->repository }}</flux:subheading>
                @endif
            </div>
        </div>

        <div class="flex items-center gap-2">
            <flux:button variant="filled" icon="document-text" size="sm" class="cursor-pointer" :href="route('projects.manifests', ['project' => $project->slug])" wire:navigate>
                {{ __('Manifests') }}
            </flux:button>
            <flux:button variant="filled" icon="arrow-path" size="sm" class="cursor-pointer">
                {{ __('Sync ArgoCD') }}
            </flux:button>
            <flux:button variant="primary" icon="play" size="sm" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500">
                {{ __('Deploy') }}
            </flux:button>

            <flux:modal.trigger name="delete-project">
                <flux:button variant="ghost" icon="trash" size="sm" class="cursor-pointer text-red-600 hover:text-red-700 dark:text-red-400">
                    {{ __('Delete') }}
                </flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <!-- VMware Clarity Status Cards Grid -->
    <div class="grid gap-6 md:grid-cols-3">
        <!-- ArgoCD Status -->
        <div class="rounded-md border border-slate-200 bg-white p-5 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="size-2.5 rounded-full bg-emerald-500"></span>
                    <flux:heading size="md" class="font-medium text-slate-900 dark:text-slate-100">{{ __('ArgoCD GitOps') }}</flux:heading>
                </div>
                <flux:badge color="emerald" size="sm" class="font-mono text-2xs uppercase">HEALTHY</flux:badge>
            </div>
            <flux:text class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                {{ __('Auto-sync enabled & active') }}
            </flux:text>
            <div class="mt-4 rounded-md bg-slate-50 p-2.5 font-mono text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                App: {{ $project->slug }}-app
            </div>
        </div>

        <!-- Vault Environment Variables -->
        <div class="rounded-md border border-slate-200 bg-white p-5 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <flux:icon name="key" class="size-5 text-amber-500" />
                    <flux:heading size="md" class="font-medium text-slate-900 dark:text-slate-100">{{ __('Vault Secrets') }}</flux:heading>
                </div>
                <flux:badge color="amber" size="sm" class="font-mono text-2xs uppercase">KV v2</flux:badge>
            </div>
            <flux:text class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                {{ __('HashiCorp Vault Secret Injection') }}
            </flux:text>
            <div class="mt-4 flex items-center justify-between font-mono text-2xs text-slate-400">
                <span>secret/{{ $project->team->slug }}/{{ $project->slug }}</span>
            </div>
        </div>

        <!-- K8s Resources & Manifests -->
        <div class="rounded-md border border-slate-200 bg-white p-5 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="size-2.5 rounded-full {{ $this->initializationDotClass() }}"></span>
                    <flux:heading size="md" class="font-medium text-slate-900 dark:text-slate-100">{{ __('Manifest Init') }}</flux:heading>
                </div>
                <flux:badge color="{{ $this->initializationBadgeColor() }}" size="sm" class="font-mono text-2xs uppercase">{{ $project->initialization_status }}</flux:badge>
            </div>
            <flux:text class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                {{ $project->initialized_at ? __('Initialized :time', ['time' => $project->initialized_at->format('Y-m-d H:i')]) : __('Preset workspace is being prepared') }}
            </flux:text>
            <div class="mt-4 flex items-center justify-between font-mono text-2xs text-slate-400">
                <span>{{ $project->manifest?->preset_key ?? $project->framework->value }}/{{ $project->manifest?->preset_version ?? 'v1' }}</span>
            </div>
        </div>
    </div>

    <!-- Overview Details Table -->
    <div class="rounded-md border border-slate-200 bg-white p-6 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
        <flux:heading size="lg" class="font-medium text-slate-900 dark:text-slate-100">{{ __('Workload Specification') }}</flux:heading>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <flux:text class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Runtime Framework') }}</flux:text>
                <flux:text class="mt-0.5 font-medium text-slate-800 dark:text-slate-200">{{ $project->framework->label() }}</flux:text>
            </div>
            <div>
                <flux:text class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Team Owner') }}</flux:text>
                <flux:text class="mt-0.5 font-medium text-slate-800 dark:text-slate-200">{{ $project->team->name }}</flux:text>
            </div>
            <div>
                <flux:text class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Repository Source') }}</flux:text>
                <flux:text class="mt-0.5 font-mono text-xs text-slate-800 dark:text-slate-200">{{ $project->repository ?? __('Not connected') }}</flux:text>
            </div>
            <div>
                <flux:text class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Created At') }}</flux:text>
                <flux:text class="mt-0.5 font-mono text-xs text-slate-800 dark:text-slate-200">{{ $project->created_at?->format('Y-m-d H:i') }}</flux:text>
            </div>
        </div>

        @if ($project->description)
            <div class="mt-6 border-t border-slate-100 pt-4 dark:border-slate-800">
                <flux:text class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Description') }}</flux:text>
                <flux:text class="mt-1 text-sm text-slate-700 dark:text-slate-300">{{ $project->description }}</flux:text>
            </div>
        @endif
    </div>

    <!-- Delete Project Modal -->
    <flux:modal name="delete-project" focusable class="max-w-md">
        <form wire:submit="deleteProject" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Project') }}</flux:heading>
                <flux:subheading>{{ __('Are you sure you want to delete :name? This action cannot be undone.', ['name' => $project->name]) }}</flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled" class="cursor-pointer">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit" class="cursor-pointer" data-test="confirm-delete-project">
                    {{ __('Delete Project') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
