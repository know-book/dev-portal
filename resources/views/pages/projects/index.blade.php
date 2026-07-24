<?php

use App\Enums\ProjectFramework;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Projects')] class extends Component {
    public string $name = '';

    public string $framework = 'laravel';

    public string $repository = '';

    public string $description = '';

    public function createProject(): void
    {
        $team = Auth::user()->currentTeam;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'framework' => ['required', Rule::enum(ProjectFramework::class)],
            'repository' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $project = $team->projects()->create([
            'name' => $validated['name'],
            'framework' => $validated['framework'],
            'repository' => $validated['repository'] ?: null,
            'description' => $validated['description'] ?: null,
        ]);

        $this->dispatch('close-modal', name: 'create-project');

        $this->reset(['name', 'framework', 'repository', 'description']);

        Flux::toast(variant: 'success', text: __('Project ":name" created.', ['name' => $project->name]));
    }

    public function deleteProject(int $projectId): void
    {
        $project = Auth::user()->currentTeam->projects()->findOrFail($projectId);
        $projectName = $project->name;

        $project->delete();

        $this->dispatch('close-modal', name: "delete-project-{$projectId}");

        Flux::toast(variant: 'success', text: __('Project ":name" deleted.', ['name' => $projectName]));
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return Auth::user()->currentTeam?->projects()->latest()->get() ?? collect();
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <!-- VMware Clarity Enterprise Header -->
    <div class="flex items-center justify-between border-b border-slate-200 pb-5 dark:border-slate-800">
        <div class="flex items-center gap-3">
            <div class="flex size-10 items-center justify-center rounded-lg bg-blue-600/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400">
                <flux:icon name="cube" class="size-6" />
            </div>
            <div>
                <flux:heading size="xl" class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Project Inventory') }}</flux:heading>
                <flux:subheading class="text-xs text-slate-500 dark:text-slate-400">{{ __('Kubernetes workloads & GitOps application management') }}</flux:subheading>
            </div>
        </div>

        <flux:modal.trigger name="create-project">
            <flux:button variant="primary" icon="plus" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500" data-test="new-project-button">
                {{ __('New Project') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    <!-- Project Cards Grid -->
    <div class="grid auto-rows-min items-start gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($this->projects as $project)
            <div class="flex flex-col justify-between rounded-md border border-slate-200 bg-white p-5 shadow-2xs transition hover:border-blue-500 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-blue-500" data-test="project-card">
                <div>
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <flux:heading size="lg" class="font-medium text-slate-900 dark:text-slate-100">
                                <a href="{{ route('projects.show', ['project' => $project->slug]) }}" wire:navigate class="hover:text-blue-600 dark:hover:text-blue-400">
                                    {{ $project->name }}
                                </a>
                            </flux:heading>
                            <flux:text class="text-xs font-mono text-slate-400">{{ $project->slug }}</flux:text>
                        </div>

                        @if ($project->framework === ProjectFramework::Laravel)
                            <flux:badge color="red" size="sm" class="font-mono text-2xs uppercase">{{ $project->framework->label() }}</flux:badge>
                        @elseif ($project->framework === ProjectFramework::NextJs)
                            <flux:badge color="blue" size="sm" class="font-mono text-2xs uppercase">{{ $project->framework->label() }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm" class="font-mono text-2xs uppercase">{{ $project->framework->label() }}</flux:badge>
                        @endif
                    </div>

                    @if ($project->description)
                        <flux:text class="mt-3 line-clamp-2 text-xs text-slate-600 dark:text-slate-400">
                            {{ $project->description }}
                        </flux:text>
                    @endif

                    @if ($project->repository)
                        <div class="mt-4 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                            <flux:icon name="folder-git-2" class="size-4 text-slate-400" />
                            <span class="truncate font-mono">{{ $project->repository }}</span>
                        </div>
                    @endif
                </div>

                <!-- VMware Clarity Footer Status & Actions -->
                <div class="mt-6 flex items-center justify-between border-t border-slate-100 pt-3 dark:border-slate-800">
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full bg-emerald-500"></span>
                        <span class="text-2xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Ready') }}</span>
                    </div>

                    <div class="flex items-center gap-1">
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="eye"
                            :href="route('projects.show', ['project' => $project->slug])"
                            wire:navigate
                            class="cursor-pointer"
                            :tooltip="__('View details')"
                        />

                        <flux:modal.trigger name="delete-project-{{ $project->id }}">
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                class="cursor-pointer text-red-600 hover:text-red-700 dark:text-red-400"
                                :tooltip="__('Delete project')"
                                data-test="delete-project-button"
                            />
                        </flux:modal.trigger>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full flex flex-col items-center justify-center rounded-md border border-dashed border-slate-300 p-12 text-center dark:border-slate-800">
                <flux:icon name="cube" class="size-12 text-slate-400" />
                <flux:heading size="lg" class="mt-4 text-slate-800 dark:text-slate-200">{{ __('No workloads found') }}</flux:heading>
                <flux:subheading class="mt-1 text-slate-500">{{ __('Create a project to provision Kubernetes resources.') }}</flux:subheading>
                <flux:modal.trigger name="create-project" class="mt-6">
                    <flux:button variant="primary" icon="plus" class="cursor-pointer">
                        {{ __('Create Project') }}
                    </flux:button>
                </flux:modal.trigger>
            </div>
        @endforelse
    </div>

    <!-- Modals (Positioned outside CSS Grid container to avoid grid-item spacing bug) -->
    @foreach ($this->projects as $project)
        <flux:modal name="delete-project-{{ $project->id }}" focusable class="max-w-md">
            <form wire:submit="deleteProject({{ $project->id }})" class="space-y-6">
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
    @endforeach

    <!-- Create Project Modal -->
    <flux:modal name="create-project" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="createProject" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create Project') }}</flux:heading>
                <flux:subheading>{{ __('Specify project runtime framework & Git repository.') }}</flux:subheading>
            </div>

            <flux:field>
                <flux:label>{{ __('Project Name') }}</flux:label>
                <flux:input wire:model="name" type="text" placeholder="my-app" required autofocus data-test="project-name-input" />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Framework') }}</flux:label>
                <flux:select wire:model="framework" data-test="project-framework-select">
                    <flux:select.option value="laravel">Laravel (PHP 8.4)</flux:select.option>
                    <flux:select.option value="nextjs">Next.js (Node.js)</flux:select.option>
                    <flux:select.option value="other">Other / Custom Dockerfile</flux:select.option>
                </flux:select>
                <flux:error name="framework" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Git Repository (Optional)') }}</flux:label>
                <flux:input wire:model="repository" type="text" placeholder="org/repo" data-test="project-repo-input" />
                <flux:error name="repository" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description (Optional)') }}</flux:label>
                <flux:textarea wire:model="description" placeholder="Project details..." rows="3" />
                <flux:error name="description" />
            </flux:field>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled" class="cursor-pointer">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" type="submit" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500" data-test="create-project-submit">
                    {{ __('Create Project') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
