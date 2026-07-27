<?php

namespace App\Services\GitHub;

use App\Contracts\GitOpsRepositoryPublisher;
use App\Data\GitOpsPublication;
use App\Data\GitOpsTarget;
use App\Enums\GitOpsPublishMode;
use App\Exceptions\GitOpsRepositoryException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class GitHubGitOpsRepositoryPublisher implements GitOpsRepositoryPublisher
{
    public function __construct(private GitHubAppService $gitHub) {}

    /**
     * @param  array<string, string>  $files
     */
    public function publish(GitOpsTarget $target, array $files, string $commitMessage): GitOpsPublication
    {
        if ($target->publishMode !== GitOpsPublishMode::Direct) {
            throw new GitOpsRepositoryException('Pull request publishing is not available yet.');
        }

        if ($files === []) {
            throw new GitOpsRepositoryException('No manifest files were provided for publication.');
        }

        try {
            return $this->publishDirectly($target, $files, $commitMessage);
        } catch (GitOpsRepositoryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new GitOpsRepositoryException('GitHub rejected the manifest publication request.', previous: $exception);
        }
    }

    /**
     * @param  array<string, string>  $files
     */
    protected function publishDirectly(GitOpsTarget $target, array $files, string $commitMessage): GitOpsPublication
    {
        $request = $this->request($target);
        $branch = rawurlencode($target->branch);
        $basePath = "repos/{$target->repository}";

        $reference = $request->get("{$basePath}/git/ref/heads/{$branch}")->throw();
        $currentCommitSha = (string) $reference->json('object.sha');

        if ($currentCommitSha === '') {
            throw new GitOpsRepositoryException('The configured GitOps branch does not exist.');
        }

        $commit = $request->get("{$basePath}/git/commits/{$currentCommitSha}")->throw();
        $currentTreeSha = (string) $commit->json('tree.sha');
        $treeResponse = $request->get("{$basePath}/git/trees/{$currentTreeSha}", ['recursive' => 1])->throw();

        if ($treeResponse->json('truncated') === true) {
            throw new GitOpsRepositoryException('The repository tree is too large to compare safely.');
        }

        $tree = $treeResponse->json('tree');

        if (! is_array($tree)) {
            throw new GitOpsRepositoryException('GitHub returned an invalid repository tree.');
        }

        $existingBlobShas = [];

        foreach ($tree as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'blob') {
                continue;
            }

            $path = $entry['path'] ?? null;
            $sha = $entry['sha'] ?? null;

            if (is_string($path) && is_string($sha)) {
                $existingBlobShas[$path] = $sha;
            }
        }

        $this->ensureTargetIsManagedOrEmpty($target, $existingBlobShas);
        $previousManagedFiles = $this->previousManagedFiles($request, $basePath, $target, $existingBlobShas);
        $treeEntries = $this->changedTreeEntries($target, $files, $previousManagedFiles, $existingBlobShas);

        if ($treeEntries === []) {
            return new GitOpsPublication(changed: false, commitSha: $currentCommitSha);
        }

        $newTree = $request->post("{$basePath}/git/trees", [
            'base_tree' => $currentTreeSha,
            'tree' => $treeEntries,
        ])->throw();

        $newTreeSha = (string) $newTree->json('sha');
        $newCommit = $request->post("{$basePath}/git/commits", [
            'message' => $commitMessage,
            'tree' => $newTreeSha,
            'parents' => [$currentCommitSha],
        ])->throw();

        $newCommitSha = (string) $newCommit->json('sha');

        $request->patch("{$basePath}/git/refs/heads/{$branch}", [
            'sha' => $newCommitSha,
            'force' => false,
        ])->throw();

        return new GitOpsPublication(changed: true, commitSha: $newCommitSha);
    }

    /**
     * @param  array<string, string>  $existingBlobShas
     */
    protected function ensureTargetIsManagedOrEmpty(GitOpsTarget $target, array $existingBlobShas): void
    {
        $markerPath = $target->filePath('.devportal.json');

        if (isset($existingBlobShas[$markerPath])) {
            return;
        }

        $targetPrefix = trim($target->path, '/').'/';
        $containsUnmanagedFiles = collect(array_keys($existingBlobShas))
            ->contains(fn (string $path): bool => str_starts_with($path, $targetPrefix));

        if ($containsUnmanagedFiles) {
            throw new GitOpsRepositoryException('The configured manifest path already contains files not managed by Dev Portal. Choose another path or adopt it explicitly.');
        }
    }

    protected function request(GitOpsTarget $target): PendingRequest
    {
        $token = $this->gitHub->getInstallationAccessToken($target->installationId);

        if ($token === '') {
            throw new GitOpsRepositoryException('Unable to authenticate the GitHub App installation.');
        }

        return Http::baseUrl('https://api.github.com')
            ->withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeader('X-GitHub-Api-Version', config('services.github.api_version'))
            ->connectTimeout(3)
            ->timeout(10);
    }

    /**
     * @param  array<string, string>  $existingBlobShas
     * @return list<string>
     */
    protected function previousManagedFiles(PendingRequest $request, string $basePath, GitOpsTarget $target, array $existingBlobShas): array
    {
        $markerPath = $target->filePath('.devportal.json');
        $markerSha = $existingBlobShas[$markerPath] ?? null;

        if (! $markerSha) {
            return [];
        }

        $blob = $request->get("{$basePath}/git/blobs/{$markerSha}")->throw();
        $encodedContent = str_replace("\n", '', (string) $blob->json('content'));
        $decodedContent = base64_decode($encodedContent, true);

        if ($decodedContent === false) {
            throw new GitOpsRepositoryException('The existing Dev Portal manifest marker is invalid.');
        }

        try {
            $marker = json_decode($decodedContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GitOpsRepositoryException('The existing Dev Portal manifest marker is invalid.', previous: $exception);
        }

        $managedFiles = Arr::get(is_array($marker) ? $marker : [], 'managed_files', []);

        if (! is_array($managedFiles)) {
            throw new GitOpsRepositoryException('The existing Dev Portal manifest marker is invalid.');
        }

        $paths = [];

        foreach ($managedFiles as $path) {
            if (is_string($path) && $path !== '.devportal.json') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $previousManagedFiles
     * @param  array<string, string>  $existingBlobShas
     * @return list<array{path: string, mode: string, type: string, content?: string, sha?: null}>
     */
    protected function changedTreeEntries(GitOpsTarget $target, array $files, array $previousManagedFiles, array $existingBlobShas): array
    {
        $entries = [];

        foreach ($files as $relativePath => $content) {
            $path = $target->filePath($relativePath);

            if (($existingBlobShas[$path] ?? null) === $this->gitBlobSha($content)) {
                continue;
            }

            $entries[] = [
                'path' => $path,
                'mode' => '100644',
                'type' => 'blob',
                'content' => $content,
            ];
        }

        foreach (array_diff($previousManagedFiles, array_keys($files)) as $relativePath) {
            $path = $target->filePath($relativePath);

            if (! isset($existingBlobShas[$path])) {
                continue;
            }

            $entries[] = [
                'path' => $path,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => null,
            ];
        }

        return $entries;
    }

    protected function gitBlobSha(string $content): string
    {
        return hash('sha1', 'blob '.strlen($content)."\0".$content);
    }
}
