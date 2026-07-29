<?php

use App\Actions\Projects\ImportProjectDockerTemplate;
use App\Enums\GitOpsRepositoryMode;
use App\Enums\ProjectFramework;
use App\Exceptions\SourceRepositoryException;
use App\Models\Project;
use App\Services\Docker\DockerPresetRegistry;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Docker CI')] class extends Component {
    public Project $project;

    public string $template = 'laravel';

    public ?string $publishedCommit = null;

    private DockerPresetRegistry $presets;

    public function boot(DockerPresetRegistry $presets): void
    {
        $this->presets = $presets;
    }

    public function mount(Project $project): void
    {
        $team = Auth::user()->currentTeam;

        if ($project->team_id !== $team->id) {
            abort(404);
        }

        $this->project = $project->loadMissing(['githubInstallation', 'team']);
        $this->template = match ($project->framework) {
            ProjectFramework::NextJs => 'nextjs',
            default => 'laravel',
        };
    }

    /**
     * @return array<string, array{label: string, description: string, images: list<string>}>
     */
    #[Computed]
    public function templates(): array
    {
        return $this->presets->presets();
    }

    /**
     * @return array{label: string, description: string, images: list<string>}
     */
    #[Computed]
    public function selectedTemplate(): array
    {
        return $this->templates[$this->template] ?? $this->templates['laravel'];
    }

    public function import(ImportProjectDockerTemplate $importTemplate): void
    {
        $validated = $this->validate([
            'template' => ['required', Rule::in(array_keys($this->templates))],
        ]);

        $this->resetErrorBag('repository');

        try {
            $publication = $importTemplate->handle($this->project, Auth::user(), $validated['template']);
        } catch (SourceRepositoryException $exception) {
            $this->addError('repository', $exception->getMessage());

            return;
        }

        $this->publishedCommit = $publication->commitSha;

        Flux::toast(
            variant: 'success',
            text: $publication->changed
                ? __('Docker CI imported at commit :sha.', ['sha' => substr($publication->commitSha, 0, 7)])
                : __('Docker CI files are already up to date.'),
        );
    }

    public function canImport(): bool
    {
        return collect($this->importRequirements)
            ->every(fn (array $requirement): bool => $requirement['ready']);
    }

    /**
     * @return list<array{key: string, label: string, ready: bool, message: string}>
     */
    #[Computed]
    public function importRequirements(): array
    {
        $installation = $this->project->githubInstallation;

        return [
            [
                'key' => 'repository',
                'label' => __('Source repository'),
                'ready' => filled($this->project->repository),
                'message' => filled($this->project->repository)
                    ? __('Configured as :repository.', ['repository' => $this->project->repository])
                    : __('Select a source repository for this project.'),
            ],
            [
                'key' => 'installation',
                'label' => __('GitHub App installation'),
                'ready' => $installation !== null,
                'message' => $installation
                    ? __('Connected as :account.', ['account' => $installation->account_name])
                    : __('Connect the source repository through a GitHub App installation.'),
            ],
            [
                'key' => 'contents',
                'label' => __('Contents: Read and write'),
                'ready' => $installation?->canWriteRepositoryContents() === true,
                'message' => $installation?->canWriteRepositoryContents() === true
                    ? __('Dev Portal can commit Docker files to the repository.')
                    : __('Enable the Contents repository permission with Read and write access.'),
            ],
            [
                'key' => 'workflows',
                'label' => __('Workflows: Read and write'),
                'ready' => $installation?->canWriteRepositoryWorkflows() === true,
                'message' => $installation?->canWriteRepositoryWorkflows() === true
                    ? __('Dev Portal can create and update files under .github/workflows.')
                    : __('Enable the Workflows repository permission with Read and write access, then approve the updated installation permissions.'),
            ],
        ];
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
                    <flux:heading size="xl" class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Docker CI') }}</flux:heading>
                    <flux:badge color="blue" size="sm" class="font-mono text-2xs uppercase">GHCR</flux:badge>
                </div>
                <flux:subheading>{{ __('Import a production Docker build and GitHub Actions workflow into the source repository.') }}</flux:subheading>
            </div>
        </div>

        <flux:button
            variant="primary"
            icon="cloud-arrow-up"
            wire:click="import"
            wire:loading.attr="disabled"
            wire:target="import"
            :disabled="! $this->canImport()"
            class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500"
            data-test="import-docker-template"
        >
            {{ __('Import to Repository') }}
        </flux:button>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-6">
            <section class="rounded-md border border-slate-200 bg-white p-6 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                <flux:heading size="lg">{{ __('Choose a template') }}</flux:heading>
                <flux:text class="mt-1 text-xs text-slate-500">{{ __('The project framework is selected by default. You can import either supported template.') }}</flux:text>

                <div class="mt-5">
                    <flux:radio.group wire:model.live="template" variant="segmented">
                        @foreach ($this->templates as $key => $preset)
                            <flux:radio wire:key="docker-template-{{ $key }}" value="{{ $key }}" icon="cube">{{ $preset['label'] }}</flux:radio>
                        @endforeach
                    </flux:radio.group>
                    <flux:error name="template" />
                </div>

                <div class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/60">
                    <flux:heading size="sm">{{ $this->selectedTemplate['label'] }}</flux:heading>
                    <flux:text class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $this->selectedTemplate['description'] }}</flux:text>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($this->selectedTemplate['images'] as $image)
                            <flux:badge wire:key="docker-image-{{ $image }}" color="zinc" size="sm" class="font-mono">
                                @if ($template === 'laravel')
                                    ghcr.io/{{ strtolower((string) $project->repository) }}/{{ $image }}:sha-xxxxxxx
                                @else
                                    ghcr.io/{{ strtolower((string) $project->repository) }}:sha-xxxxxxx
                                @endif
                            </flux:badge>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="rounded-md border border-slate-200 bg-white p-6 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                <flux:heading size="lg">{{ __('Files managed by Dev Portal') }}</flux:heading>
                <flux:text class="mt-1 text-xs text-slate-500">{{ __('The import is one atomic commit. Existing files are never adopted or overwritten automatically.') }}</flux:text>

                <div class="mt-4 grid gap-2 font-mono text-xs text-slate-600 sm:grid-cols-2 dark:text-slate-300">
                    <div class="rounded bg-slate-50 px-3 py-2 dark:bg-slate-800">.github/workflows/docker-build.yaml</div>
                    <div class="rounded bg-slate-50 px-3 py-2 dark:bg-slate-800">.dockerignore</div>
                    @if ($template === 'laravel')
                        <div class="rounded bg-slate-50 px-3 py-2 dark:bg-slate-800">docker/production/php-fpm/*</div>
                        <div class="rounded bg-slate-50 px-3 py-2 dark:bg-slate-800">docker/production/nginx/*</div>
                    @else
                        <div class="rounded bg-slate-50 px-3 py-2 dark:bg-slate-800">docker/production/Dockerfile</div>
                    @endif
                    <div class="rounded bg-slate-50 px-3 py-2 dark:bg-slate-800">.devportal/docker.json</div>
                </div>

                <flux:error name="repository" />

                @if ($publishedCommit)
                    <flux:callout variant="success" icon="check-circle" heading="{{ __('Imported at :sha', ['sha' => substr($publishedCommit, 0, 12)]) }}" class="mt-5">
                        {{ __('GitHub Actions will build the default branch and publish latest and sha-* tags to GHCR.') }}
                    </flux:callout>
                @endif
            </section>
        </div>

        <aside class="space-y-4">
            <div class="rounded-md border border-slate-200 bg-white p-5 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                <flux:heading size="sm">{{ __('Source repository') }}</flux:heading>
                <dl class="mt-4 space-y-3 text-xs">
                    <div>
                        <dt class="uppercase tracking-wider text-slate-400">{{ __('Repository') }}</dt>
                        <dd class="mt-1 break-all font-mono text-slate-700 dark:text-slate-300">{{ $project->repository ?? __('Not connected') }}</dd>
                    </div>
                    <div>
                        <dt class="uppercase tracking-wider text-slate-400">{{ __('Branch') }}</dt>
                        <dd class="mt-1 font-mono text-slate-700 dark:text-slate-300">{{ $project->default_branch }}</dd>
                    </div>
                </dl>
            </div>

            @if (! $this->canImport())
                <flux:callout variant="warning" icon="key" heading="{{ __('Import unavailable') }}">
                    <div class="space-y-3">
                        <flux:text class="text-sm">{{ __('Complete every requirement below to enable Import to Repository.') }}</flux:text>

                        <div class="space-y-2">
                            @foreach ($this->importRequirements as $requirement)
                                <div wire:key="docker-import-requirement-{{ $requirement['key'] }}" class="flex items-start gap-2 rounded-md border border-amber-200 bg-white/70 p-2.5 dark:border-amber-900 dark:bg-slate-900/60">
                                    <flux:icon name="{{ $requirement['ready'] ? 'check-circle' : 'x-circle' }}" class="mt-0.5 size-4 shrink-0 {{ $requirement['ready'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}" />
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-semibold text-slate-800 dark:text-slate-200">{{ $requirement['label'] }}</span>
                                            <flux:badge color="{{ $requirement['ready'] ? 'emerald' : 'red' }}" size="sm" class="font-mono text-2xs uppercase">
                                                {{ $requirement['ready'] ? __('Ready') : __('Missing') }}
                                            </flux:badge>
                                        </div>
                                        <flux:text class="mt-0.5 text-xs text-slate-600 dark:text-slate-400">{{ $requirement['message'] }}</flux:text>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <flux:button variant="filled" size="sm" icon="cog" :href="route('settings.github')" wire:navigate class="cursor-pointer">
                            {{ __('Open GitHub App Settings') }}
                        </flux:button>
                    </div>
                </flux:callout>
            @else
                <flux:callout icon="folder-git-2" heading="{{ __('Safe repository writes') }}">
                    {{ __('Dev Portal updates only files recorded in .devportal/docker.json and rejects unmanaged collisions.') }}
                </flux:callout>
            @endif

            <flux:callout icon="information-circle" heading="{{ __('GHCR authentication') }}">
                {{ __('The generated workflow uses GITHUB_TOKEN with contents: read and packages: write. No registry password is stored in Dev Portal.') }}
            </flux:callout>

            @if ($project->gitops_repository_mode === GitOpsRepositoryMode::CoLocated)
                <flux:callout icon="arrow-path" heading="{{ __('Automatic GitOps tag update') }}">
                    {{ __('After a successful build, the workflow updates :path/kustomization.yaml with the sha-* image tag and pushes a skip-ci commit.', ['path' => trim($project->gitops_path, '/')]) }}
                </flux:callout>
            @else
                <flux:callout variant="warning" icon="information-circle" heading="{{ __('Separate GitOps repository') }}">
                    {{ __('The workflow builds and pushes GHCR images, but does not write image tags to the separate GitOps repository.') }}
                </flux:callout>
            @endif
        </aside>
    </div>
</div>
