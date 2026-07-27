<?php

namespace App\Actions\Projects;

use App\Contracts\GitOpsRepositoryPublisher;
use App\Data\GitOpsPublication;
use App\Models\Project;
use App\Models\ProjectManifest;
use App\Models\ProjectManifestRevision;
use App\Models\User;
use App\Services\GitOps\GitOpsTargetResolver;
use App\Services\Manifests\ManifestCompiler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class PublishProjectManifests
{
    public function __construct(
        private ManifestCompiler $compiler,
        private GitOpsTargetResolver $targetResolver,
        private GitOpsRepositoryPublisher $publisher,
    ) {}

    public function handle(Project $project, User $user): GitOpsPublication
    {
        if (! $user->belongsToTeam($project->team)) {
            throw new AuthorizationException;
        }

        $manifest = $project->manifest()->firstOrFail();
        $files = $this->compiler->compile($manifest);
        $compiledHash = $this->compiler->compiledHash($manifest);
        $target = $this->targetResolver->resolve($project);
        $managedFiles = array_keys($files);
        sort($managedFiles);

        $files['.devportal.json'] = json_encode([
            'schema_version' => 1,
            'managed_by' => 'dev-portal',
            'project' => $project->slug,
            'preset' => $manifest->preset_key.'/'.$manifest->preset_version,
            'compiled_hash' => $compiledHash,
            'managed_files' => $managedFiles,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        $publication = $this->publisher->publish(
            $target,
            $files,
            "chore(deploy): sync {$project->slug} manifests",
        );

        DB::transaction(function () use ($manifest, $user, $compiledHash, $publication, $target): void {
            $lockedManifest = ProjectManifest::query()->lockForUpdate()->findOrFail($manifest->id);
            $revision = $lockedManifest->revisions()
                ->where('compiled_hash', $compiledHash)
                ->latest('revision_number')
                ->first();

            if (! $revision) {
                $revision = $lockedManifest->revisions()->create([
                    'revision_number' => ((int) $lockedManifest->revisions()->max('revision_number')) + 1,
                    'patch_snapshot' => $lockedManifest->patches()
                        ->orderBy('path')
                        ->get(['path', 'operation', 'content', 'base_content_hash'])
                        ->keyBy('path')
                        ->toArray(),
                    'compiled_hash' => $compiledHash,
                    'created_by' => $user->id,
                ]);
            }

            $revision->update([
                'status' => ProjectManifestRevision::StatusPublished,
                'created_by' => $revision->created_by ?? $user->id,
                'published_at' => now(),
                'git_commit_sha' => $publication->commitSha,
                'git_repository' => $target->repository,
                'git_branch' => $target->branch,
                'git_path' => $target->path,
            ]);
        });

        return $publication;
    }
}
