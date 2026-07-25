<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\ProjectManifest;
use App\Models\ProjectManifestRevision;
use App\Services\Manifests\ManifestPresetRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class InitializeProjectManifests implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    /**
     * @var list<int>
     */
    public array $backoff = [1, 5, 10];

    public function __construct(public Project $project) {}

    public function uniqueId(): string
    {
        return 'project-manifest-init:'.$this->project->id;
    }

    /**
     * Execute the job.
     */
    public function handle(ManifestPresetRegistry $presets): void
    {
        $project = $this->project->fresh(['manifest', 'team']);

        if (! $project) {
            return;
        }

        $project->forceFill([
            'initialization_status' => Project::InitializationInitializing,
            'initialization_error' => null,
        ])->save();

        $preset = $presets->presetForProject($project);
        $files = $presets->filesForProject($project);
        $baseHash = $presets->hashFiles($files);

        $manifest = ProjectManifest::updateOrCreate(
            ['project_id' => $project->id],
            [
                'preset_key' => $preset['key'],
                'preset_version' => $preset['version'],
                'variables' => $presets->variablesForProject($project),
                'base_hash' => $baseHash,
            ],
        );

        $manifest->revisions()->updateOrCreate(
            ['revision_number' => 1],
            [
                'patch_snapshot' => [],
                'compiled_hash' => $baseHash,
                'status' => ProjectManifestRevision::StatusDraft,
            ],
        );

        $project->forceFill([
            'initialization_status' => Project::InitializationReady,
            'initialization_error' => null,
            'initialized_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $project = $this->project->fresh();

        if (! $project) {
            return;
        }

        $project->forceFill([
            'initialization_status' => Project::InitializationFailed,
            'initialization_error' => $exception ? Str::limit($exception->getMessage(), 1000, '') : null,
        ])->save();
    }
}
