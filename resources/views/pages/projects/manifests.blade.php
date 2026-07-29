<?php

use App\Models\Project;
use App\Models\ProjectManifest;
use App\Models\ProjectManifestPatch;
use App\Services\Manifests\ManifestCompiler;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

new #[Layout('layouts.app')] #[Title('Project Manifests')] class extends Component {
    public Project $project;

    public ProjectManifest $manifest;

    public string $selectedPath = '';

    public string $content = '';

    public string $validationMessage = '';

    protected ManifestCompiler $compiler;

    public function boot(ManifestCompiler $compiler): void
    {
        $this->compiler = $compiler;
    }

    public function mount(Project $project): void
    {
        $team = Auth::user()->currentTeam;

        abort_if($project->team_id !== $team->id, 404);

        $this->project = $project->loadMissing(['manifest', 'team']);
        abort_if(! $this->project->manifest, 404);

        $this->manifest = $this->project->manifest;
        $files = $this->files();
        $requestedPath = request()->query('path');
        $selectedPath = is_string($requestedPath) && array_key_exists($requestedPath, $files)
            ? $requestedPath
            : (string) array_key_first($files);

        $this->selectFile($selectedPath);
    }

    public function selectFile(string $path): void
    {
        if (! $this->isAllowedPath($path)) {
            $this->addError('selectedPath', __('Selected manifest path is not editable.'));

            return;
        }

        $this->resetErrorBag();
        $this->selectedPath = $path;
        $this->content = $this->files()[$path] ?? '';
        $this->validationMessage = '';
    }

    public function saveFile(): void
    {
        $this->validate([
            'content' => ['nullable', 'string', 'max:200000'],
        ]);

        if (! $this->isAllowedPath($this->selectedPath) || ! $this->validateManifestContent($this->selectedPath, $this->content)) {
            return;
        }

        $baseFiles = $this->compiler->baseFiles($this->manifest);
        $baseContent = $baseFiles[$this->selectedPath] ?? '';
        $baseContentHash = hash('sha256', $baseContent);

        if ($this->content === $baseContent) {
            $this->manifest->patches()->where('path', $this->selectedPath)->delete();
            $this->manifest->increment('lock_version');
            $this->manifest->refresh();

            Flux::toast(variant: 'success', text: __('Patch reset for :path.', ['path' => $this->selectedPath]));

            return;
        }

        $this->manifest->patches()->updateOrCreate(
            ['path' => $this->selectedPath],
            [
                'operation' => ProjectManifestPatch::OperationReplace,
                'content' => $this->content,
                'base_content_hash' => $baseContentHash,
                'edited_by' => Auth::id(),
            ],
        );

        $this->manifest->increment('lock_version');
        $this->manifest->refresh();

        Flux::toast(variant: 'success', text: __('Manifest patch saved.'));
    }

    public function resetFile(): void
    {
        if (! $this->isAllowedPath($this->selectedPath)) {
            return;
        }

        $this->manifest->patches()->where('path', $this->selectedPath)->delete();
        $this->manifest->increment('lock_version');
        $this->manifest->refresh();
        $this->content = $this->compiler->baseFiles($this->manifest)[$this->selectedPath] ?? '';
        $this->validationMessage = '';

        Flux::toast(variant: 'success', text: __('Manifest file reset.'));
    }

    public function validateManifest(): void
    {
        $files = $this->files();
        $files[$this->selectedPath] = $this->content;

        foreach ($files as $path => $content) {
            if (! str_ends_with($path, '.yaml')) {
                continue;
            }

            if (! $this->validateManifestContent($path, $content)) {
                return;
            }
        }

        $this->resetErrorBag();
        $this->validationMessage = __('Manifest tree parsed successfully.');
    }

    /**
     * @return array<string, string>
     */
    public function files(): array
    {
        return $this->compiler->compile($this->manifest);
    }

    /**
     * @return array<int, array{type: string, path: string, name: string, depth: int, patched: bool}>
     */
    public function treeRows(): array
    {
        $rows = [];
        $seenDirectories = [];
        $patchedPaths = $this->patchedPaths();

        foreach (array_keys($this->files()) as $path) {
            $parts = explode('/', $path);
            $directoryPath = '';

            foreach (array_slice($parts, 0, -1) as $depth => $part) {
                $directoryPath = trim($directoryPath.'/'.$part, '/');

                if (isset($seenDirectories[$directoryPath])) {
                    continue;
                }

                $seenDirectories[$directoryPath] = true;
                $rows[] = [
                    'type' => 'directory',
                    'path' => $directoryPath,
                    'name' => $part,
                    'depth' => $depth,
                    'patched' => false,
                ];
            }

            $rows[] = [
                'type' => 'file',
                'path' => $path,
                'name' => array_slice($parts, -1)[0],
                'depth' => count($parts) - 1,
                'patched' => in_array($path, $patchedPaths, true),
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function patchedPaths(): array
    {
        return $this->manifest->patches()->pluck('path')->all();
    }

    public function depthClass(int $depth): string
    {
        return match ($depth) {
            0 => 'pl-2',
            1 => 'pl-5',
            2 => 'pl-8',
            3 => 'pl-11',
            default => 'pl-14',
        };
    }

    protected function validateManifestContent(string $path, string $content): bool
    {
        if (preg_match('/^kind:\s*Secret\s*$/mi', $content)) {
            $this->addError('content', __('Plain Kubernetes Secret manifests are blocked. Use ExternalSecret instead.'));

            return false;
        }

        if (! str_ends_with($path, '.yaml')) {
            return true;
        }

        try {
            Yaml::parse($content);
        } catch (ParseException $exception) {
            $this->addError('content', __('Invalid YAML in :path: :message', [
                'path' => $path,
                'message' => $exception->getMessage(),
            ]));

            return false;
        }

        return true;
    }

    protected function isAllowedPath(string $path): bool
    {
        if ($path === '' || Str::startsWith($path, ['/', '\\']) || Str::contains($path, ['..', '\\'])) {
            return false;
        }

        return array_key_exists($path, $this->files());
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
        <div class="flex items-center gap-3">
            <a href="{{ route('projects.show', ['project' => $project->slug]) }}" wire:navigate class="cursor-pointer rounded-md p-2 hover:bg-slate-100 dark:hover:bg-slate-800">
                <flux:icon name="arrow-left" class="size-5 text-slate-500" />
            </a>

            <div>
                <div class="flex items-center gap-2">
                    <flux:heading size="xl" class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Manifest Editor') }}</flux:heading>
                    <flux:badge color="blue" size="sm" class="font-mono text-2xs uppercase">{{ $manifest->preset_key }}/{{ $manifest->preset_version }}</flux:badge>
                </div>
                <flux:subheading class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $project->name }} / {{ $selectedPath }}</flux:subheading>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <flux:button variant="filled" icon="check-circle" size="sm" class="cursor-pointer" wire:click="validateManifest">
                {{ __('Validate') }}
            </flux:button>
            <flux:button variant="filled" icon="arrow-path" size="sm" class="cursor-pointer" wire:click="resetFile">
                {{ __('Reset') }}
            </flux:button>
            <flux:button variant="primary" icon="cloud-arrow-up" size="sm" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500" wire:click="saveFile">
                {{ __('Save Patch') }}
            </flux:button>
        </div>
    </div>

    <div class="grid min-h-0 flex-1 gap-4 xl:grid-cols-[18rem_minmax(0,1fr)_18rem]">
        <div class="rounded-md border border-slate-200 bg-white shadow-2xs dark:border-slate-800 dark:bg-slate-900">
            <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                <flux:heading size="sm" class="font-medium text-slate-900 dark:text-slate-100">{{ __('Files') }}</flux:heading>
            </div>

            <div class="max-h-[42rem] overflow-auto py-2">
                @foreach ($this->treeRows() as $row)
                    @if ($row['type'] === 'directory')
                        <div class="{{ $this->depthClass($row['depth']) }} flex items-center gap-2 px-2 py-1.5 text-xs font-medium text-slate-500 dark:text-slate-400">
                            <flux:icon name="folder" class="size-4" />
                            <span class="truncate">{{ $row['name'] }}</span>
                        </div>
                    @else
                        <button
                            type="button"
                            wire:click="selectFile('{{ $row['path'] }}')"
                            class="{{ $this->depthClass($row['depth']) }} flex w-full cursor-pointer items-center gap-2 px-2 py-1.5 text-left text-xs {{ $selectedPath === $row['path'] ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800' }}"
                        >
                            <flux:icon name="document-text" class="size-4 shrink-0" />
                            <span class="truncate font-mono">{{ $row['name'] }}</span>
                            @if ($row['patched'])
                                <flux:badge color="amber" size="sm" class="ml-auto font-mono text-2xs uppercase">{{ __('patch') }}</flux:badge>
                            @endif
                        </button>
                    @endif
                @endforeach
            </div>
        </div>

        <div class="min-h-0 rounded-md border border-slate-200 bg-white shadow-2xs dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                <div class="min-w-0">
                    <flux:heading size="sm" class="truncate font-mono text-slate-900 dark:text-slate-100">{{ $selectedPath }}</flux:heading>
                    <flux:subheading class="text-2xs text-slate-500">{{ strlen($content) }} bytes</flux:subheading>
                </div>
            </div>

            <div class="p-4">
                <flux:textarea
                    wire:model="content"
                    rows="30"
                    class="font-mono text-xs leading-5"
                    spellcheck="false"
                    data-test="manifest-editor"
                />
                <flux:error name="content" />
                <flux:error name="selectedPath" />
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-md border border-slate-200 bg-white p-4 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                <flux:heading size="sm" class="font-medium text-slate-900 dark:text-slate-100">{{ __('Workspace') }}</flux:heading>
                <dl class="mt-3 space-y-3 text-xs">
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Namespace') }}</dt>
                        <dd class="mt-0.5 font-mono text-slate-700 dark:text-slate-300">{{ $manifest->variables['namespace'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Image') }}</dt>
                        <dd class="mt-0.5 break-all font-mono text-slate-700 dark:text-slate-300">{{ $manifest->variables['image_repository'] }}:{{ $manifest->variables['image_tag'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs uppercase tracking-wider text-slate-400">{{ __('Lock Version') }}</dt>
                        <dd class="mt-0.5 font-mono text-slate-700 dark:text-slate-300">{{ $manifest->lock_version }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-md border border-slate-200 bg-white p-4 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                <flux:heading size="sm" class="font-medium text-slate-900 dark:text-slate-100">{{ __('Patches') }}</flux:heading>
                <div class="mt-3 space-y-2">
                    @forelse ($this->patchedPaths() as $path)
                        <div class="rounded border border-amber-200 bg-amber-50 px-2 py-1.5 font-mono text-2xs text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300">
                            {{ $path }}
                        </div>
                    @empty
                        <flux:text class="text-xs text-slate-500">{{ __('No patches saved') }}</flux:text>
                    @endforelse
                </div>
            </div>

            @if ($validationMessage)
                <flux:callout variant="success" icon="check-circle" heading="{{ $validationMessage }}" />
            @endif
        </div>
    </div>
</div>
