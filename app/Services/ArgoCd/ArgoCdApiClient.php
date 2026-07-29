<?php

namespace App\Services\ArgoCd;

use App\Data\ArgoApplicationStatus;
use App\Exceptions\ArgoCdException;
use App\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class ArgoCdApiClient
{
    public function __construct(private ArgoApplicationDefinitionFactory $definitions) {}

    public function isConfigured(): bool
    {
        return filled(config('services.argocd.url')) && filled(config('services.argocd.token'));
    }

    public function status(Project $project, bool $hardRefresh = false): ?ArgoApplicationStatus
    {
        $name = $this->definitions->applicationName($project);
        $query = [
            'appNamespace' => $this->definitions->applicationNamespace(),
            'project' => (string) config('services.argocd.project', 'default'),
        ];

        if ($hardRefresh) {
            $query['refresh'] = 'hard';
        }

        try {
            $response = $this->request()->get('api/v1/applications/'.rawurlencode($name), $query);

            if ($response->status() === 404) {
                return null;
            }

            $response->throw();
            $payload = $response->json();

            if (! is_array($payload)) {
                throw new ArgoCdException('Argo CD returned an invalid Application status.');
            }

            return ArgoApplicationStatus::fromApplication($payload, $name);
        } catch (ArgoCdException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ArgoCdException('Argo CD could not read the Application status.', previous: $exception);
        }
    }

    public function sync(Project $project): ArgoApplicationStatus
    {
        $name = $this->definitions->applicationName($project);
        $appNamespace = $this->definitions->applicationNamespace();
        $argoProject = (string) config('services.argocd.project', 'default');

        try {
            $this->request()->post('api/v1/applications/'.rawurlencode($name).'/sync', [
                'name' => $name,
                'appNamespace' => $appNamespace,
                'project' => $argoProject,
                'prune' => (bool) config('services.argocd.auto_prune', true),
            ])->throw();
        } catch (Throwable $exception) {
            throw new ArgoCdException('Argo CD rejected the synchronization request.', previous: $exception);
        }

        return $this->status($project)
            ?? throw new ArgoCdException('Argo CD accepted the sync but the Application status is unavailable.');
    }

    protected function request(): PendingRequest
    {
        $url = rtrim((string) config('services.argocd.url'), '/');
        $token = (string) config('services.argocd.token');

        if (! $this->isConfigured()) {
            throw new ArgoCdException('The Argo CD API is not configured for this environment.');
        }

        return Http::baseUrl($url)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('services.argocd.connect_timeout', 3))
            ->timeout((int) config('services.argocd.timeout', 10))
            ->withOptions(['verify' => (bool) config('services.argocd.verify_tls', true)]);
    }
}
