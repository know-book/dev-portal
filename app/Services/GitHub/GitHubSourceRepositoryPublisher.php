<?php

namespace App\Services\GitHub;

use App\Contracts\SourceRepositoryPublisher;
use App\Data\GitOpsPublication;
use App\Data\SourceRepositoryTarget;
use App\Exceptions\SourceRepositoryException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class GitHubSourceRepositoryPublisher implements SourceRepositoryPublisher
{
    public function __construct(private GitHubAppService $gitHub) {}

    /**
     * @param  array<string, string>  $files
     */
    public function publish(SourceRepositoryTarget $target, array $files, string $commitMessage): GitOpsPublication
    {
        if ($files === []) {
            throw new SourceRepositoryException('No Docker template files were provided for publication.');
        }

        try {
            return $this->publishDirectly($target, $files, $commitMessage);
        } catch (SourceRepositoryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SourceRepositoryException('GitHub rejected the Docker template import request.', previous: $exception);
        }
    }

    /**
     * @param  array<string, string>  $files
     */
    protected function publishDirectly(SourceRepositoryTarget $target, array $files, string $commitMessage): GitOpsPublication
    {
        $request = $this->request($target);
        $branch = rawurlencode($target->branch);
        $basePath = "repos/{$target->repository}";
        $reference = $request->get("{$basePath}/git/ref/heads/{$branch}")->throw();
        $currentCommitSha = (string) $reference->json('object.sha');

        if ($currentCommitSha === '') {
            throw new SourceRepositoryException('The configured source repository branch does not exist.');
        }

        $commit = $request->get("{$basePath}/git/commits/{$currentCommitSha}")->throw();
        $currentTreeSha = (string) $commit->json('tree.sha');
        $treeResponse = $request->get("{$basePath}/git/trees/{$currentTreeSha}", ['recursive' => 1])->throw();

        if ($treeResponse->json('truncated') === true) {
            throw new SourceRepositoryException('The source repository tree is too large to compare safely.');
        }

        $existingBlobShas = $this->existingBlobShas($treeResponse->json('tree'));
        $previousManagedFiles = $this->previousManagedFiles($request, $basePath, $target, $existingBlobShas);

        $this->ensureFilesCanBeManaged($target, $files, $previousManagedFiles, $existingBlobShas);

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

    protected function request(SourceRepositoryTarget $target): PendingRequest
    {
        $token = $this->gitHub->getInstallationAccessToken($target->installationId);

        if ($token === '') {
            throw new SourceRepositoryException('Unable to authenticate the GitHub App installation.');
        }

        return Http::baseUrl('https://api.github.com')
            ->withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeader('X-GitHub-Api-Version', config('services.github.api_version'))
            ->connectTimeout(3)
            ->timeout(10);
    }

    /**
     * @return array<string, string>
     */
    protected function existingBlobShas(mixed $tree): array
    {
        if (! is_array($tree)) {
            throw new SourceRepositoryException('GitHub returned an invalid source repository tree.');
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

        return $existingBlobShas;
    }

    /**
     * @param  array<string, string>  $existingBlobShas
     * @return list<string>
     */
    protected function previousManagedFiles(PendingRequest $request, string $basePath, SourceRepositoryTarget $target, array $existingBlobShas): array
    {
        $markerPath = $target->filePath($target->markerPath);
        $markerSha = $existingBlobShas[$markerPath] ?? null;

        if (! $markerSha) {
            return [];
        }

        $blob = $request->get("{$basePath}/git/blobs/{$markerSha}")->throw();
        $encodedContent = str_replace("\n", '', (string) $blob->json('content'));
        $decodedContent = base64_decode($encodedContent, true);

        if ($decodedContent === false) {
            throw new SourceRepositoryException('The existing Dev Portal Docker marker is invalid.');
        }

        try {
            $marker = json_decode($decodedContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SourceRepositoryException('The existing Dev Portal Docker marker is invalid.', previous: $exception);
        }

        $managedFiles = Arr::get(is_array($marker) ? $marker : [], 'managed_files', []);

        if (! is_array($managedFiles)) {
            throw new SourceRepositoryException('The existing Dev Portal Docker marker is invalid.');
        }

        return array_values(array_filter(
            $managedFiles,
            fn (mixed $path): bool => is_string($path) && $path !== $target->markerPath,
        ));
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $previousManagedFiles
     * @param  array<string, string>  $existingBlobShas
     */
    protected function ensureFilesCanBeManaged(SourceRepositoryTarget $target, array $files, array $previousManagedFiles, array $existingBlobShas): void
    {
        foreach (array_keys($files) as $relativePath) {
            $path = $target->filePath($relativePath);

            if ($path === $target->markerPath || ! isset($existingBlobShas[$path])) {
                continue;
            }

            if (! in_array($path, $previousManagedFiles, true)) {
                throw new SourceRepositoryException("The source repository file [{$path}] already exists and is not managed by Dev Portal.");
            }
        }
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $previousManagedFiles
     * @param  array<string, string>  $existingBlobShas
     * @return list<array{path: string, mode: string, type: string, content?: string, sha?: null}>
     */
    protected function changedTreeEntries(SourceRepositoryTarget $target, array $files, array $previousManagedFiles, array $existingBlobShas): array
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

        foreach (array_diff($previousManagedFiles, array_keys($files)) as $path) {
            if (! isset($existingBlobShas[$path])) {
                continue;
            }

            $entries[] = [
                'path' => $target->filePath($path),
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
