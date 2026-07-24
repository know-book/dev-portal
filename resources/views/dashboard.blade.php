<x-layouts::app :title="__('Dashboard')">
    <livewire:pages::teams.pending-invitations-modal />

    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <!-- Team Overview Cards -->
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Current Team') }}</flux:text>
                    <flux:icon name="users" class="size-5 text-zinc-400" />
                </div>
                <flux:heading size="xl" class="mt-2">{{ auth()->user()->currentTeam?->name }}</flux:heading>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total Projects') }}</flux:text>
                    <flux:icon name="folder" class="size-5 text-zinc-400" />
                </div>
                <flux:heading size="xl" class="mt-2">{{ auth()->user()->currentTeam?->projects()->count() ?? 0 }}</flux:heading>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('GitOps & Cluster') }}</flux:text>
                    <flux:icon name="cpu-chip" class="size-5 text-zinc-400" />
                </div>
                <flux:heading size="xl" class="mt-2 text-emerald-600 dark:text-emerald-400">ArgoCD Connected</flux:heading>
            </div>
        </div>

        <!-- Quick Access to Projects -->
        <div class="flex-1 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">{{ __('Team Projects') }}</flux:heading>
                <flux:button variant="primary" icon="plus" :href="route('projects.index')" wire:navigate>
                    {{ __('View All & Manage') }}
                </flux:button>
            </div>

            @php
                $projects = auth()->user()->currentTeam?->projects()->latest()->take(5)->get() ?? collect();
            @endphp

            @if ($projects->isNotEmpty())
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($projects as $project)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                <flux:icon name="cube" class="size-5 text-zinc-400" />
                                <div>
                                    <a href="{{ route('projects.show', ['project' => $project->slug]) }}" wire:navigate class="font-medium hover:underline">
                                        {{ $project->name }}
                                    </a>
                                    @if ($project->repository)
                                        <flux:text class="text-xs font-mono text-zinc-400">{{ $project->repository }}</flux:text>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <flux:badge size="sm">{{ $project->framework->label() }}</flux:badge>
                                <flux:button variant="ghost" size="sm" icon="chevron-right" :href="route('projects.show', ['project' => $project->slug])" wire:navigate />
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <flux:icon name="folder" class="size-10 text-zinc-400" />
                    <flux:heading size="md" class="mt-2">{{ __('No projects found') }}</flux:heading>
                    <flux:text class="mt-1 text-sm text-zinc-500">{{ __('Create your first project under this team to start deploying.') }}</flux:text>
                    <flux:button variant="primary" icon="plus" class="mt-4" :href="route('projects.index')" wire:navigate>
                        {{ __('Go to Projects') }}
                    </flux:button>
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
