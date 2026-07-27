<?php

namespace App\Services\ArgoCd;

use App\Exceptions\ArgoCdException;
use App\Models\Project;
use App\Services\GitOps\GitOpsTargetResolver;
use Illuminate\Support\Str;

class ArgoApplicationDefinitionFactory
{
    public function __construct(private GitOpsTargetResolver $targets) {}

    /** @return array<string, mixed> */
    public function make(Project $project): array
    {
        $project->loadMissing(['team', 'manifest']);
        $target = $this->targets->resolve($project);
        $destinationNamespace = $project->manifest?->variables['namespace'] ?? null;

        if (! is_string($destinationNamespace) || $destinationNamespace === '') {
            throw new ArgoCdException('The project manifest does not define a destination namespace.');
        }

        $syncPolicy = [
            'syncOptions' => ['CreateNamespace=true'],
        ];

        if ($project->auto_deploy) {
            $syncPolicy['automated'] = [
                'enabled' => true,
                'prune' => (bool) config('services.argocd.auto_prune', true),
                'selfHeal' => (bool) config('services.argocd.self_heal', true),
                'allowEmpty' => false,
            ];
        }

        return [
            'apiVersion' => 'argoproj.io/v1alpha1',
            'kind' => 'Application',
            'metadata' => [
                'name' => $this->applicationName($project),
                'namespace' => $this->applicationNamespace(),
                'labels' => [
                    'app.kubernetes.io/managed-by' => 'dev-portal',
                    'dev-portal.io/project' => $this->labelValue($project->slug),
                    'dev-portal.io/team' => $this->labelValue($project->team->slug),
                ],
            ],
            'spec' => [
                'project' => (string) config('services.argocd.project', 'default'),
                'source' => [
                    'repoURL' => $this->repositoryUrl($target->repository),
                    'targetRevision' => $target->branch,
                    'path' => trim($target->path, '/'),
                ],
                'destination' => [
                    'server' => (string) config('services.argocd.destination_server', 'https://kubernetes.default.svc'),
                    'namespace' => $destinationNamespace,
                ],
                'syncPolicy' => $syncPolicy,
                'revisionHistoryLimit' => 10,
            ],
        ];
    }

    public function applicationName(Project $project): string
    {
        $project->loadMissing('team');

        return $this->kubernetesName($project->team->slug.'-'.$project->slug);
    }

    public function applicationNamespace(): string
    {
        return (string) config('services.argocd.namespace', 'argocd');
    }

    protected function repositoryUrl(string $repository): string
    {
        if (str_contains($repository, '://') || str_starts_with($repository, 'git@')) {
            return $repository;
        }

        return 'https://github.com/'.trim($repository, '/').'.git';
    }

    protected function labelValue(string $value): string
    {
        return $this->kubernetesName($value);
    }

    protected function kubernetesName(string $value): string
    {
        $name = Str::slug(Str::lower($value)) ?: 'app';

        return rtrim(Str::limit($name, 63, ''), '-');
    }
}
