<?php

namespace App\Services\Kubernetes;

use App\Data\ExternalSecretStatus;
use App\Exceptions\KubernetesResourceException;
use App\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class KubernetesExternalSecretClient
{
    public function __construct(private KubernetesApiToken $apiToken) {}

    public function status(Project $project): ExternalSecretStatus
    {
        try {
            $response = $this->request()->get($this->endpoint($project));

            if ($response->status() === 404) {
                return $this->missingStatus();
            }

            $response->throw();
            $payload = $response->json();

            if (! is_array($payload)) {
                throw new KubernetesResourceException('Kubernetes returned an invalid ExternalSecret resource.');
            }

            return $this->statusFromPayload($payload);
        } catch (KubernetesResourceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new KubernetesResourceException('Kubernetes could not read the ExternalSecret status.', previous: $exception);
        }
    }

    public function refresh(Project $project): ExternalSecretStatus
    {
        try {
            $body = json_encode([
                'metadata' => [
                    'annotations' => [
                        'force-sync' => (string) now()->getTimestampMs(),
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
            $response = $this->request()
                ->withBody($body, 'application/merge-patch+json')
                ->patch($this->endpoint($project));

            if ($response->status() === 404) {
                return $this->missingStatus();
            }

            $response->throw();
            $payload = $response->json();

            if (! is_array($payload)) {
                throw new KubernetesResourceException('Kubernetes returned an invalid ExternalSecret resource.');
            }

            return $this->statusFromPayload($payload);
        } catch (KubernetesResourceException $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            throw new KubernetesResourceException('The ExternalSecret refresh request could not be encoded.', previous: $exception);
        } catch (Throwable $exception) {
            throw new KubernetesResourceException('Kubernetes rejected the ExternalSecret refresh request.', previous: $exception);
        }
    }

    protected function endpoint(Project $project): string
    {
        $project->loadMissing('manifest');
        $variables = $project->manifest->variables;
        $namespace = Arr::get($variables, 'namespace');
        $projectSlug = Arr::get($variables, 'project_slug');

        if (! is_string($namespace) || $namespace === '' || ! is_string($projectSlug) || $projectSlug === '') {
            throw new KubernetesResourceException('The manifest workspace does not define the ExternalSecret identity.');
        }

        $apiVersion = trim((string) config('services.kubernetes.external_secrets_api_version', 'v1'), '/');

        return 'apis/external-secrets.io/'.rawurlencode($apiVersion)
            .'/namespaces/'.rawurlencode($namespace)
            .'/externalsecrets/'.rawurlencode($projectSlug.'-env');
    }

    /** @param array<string, mixed> $payload */
    protected function statusFromPayload(array $payload): ExternalSecretStatus
    {
        $conditions = Arr::get($payload, 'status.conditions', []);
        $condition = null;

        if (is_array($conditions)) {
            foreach ($conditions as $candidate) {
                if (is_array($candidate) && Arr::get($candidate, 'type') === 'Ready') {
                    $condition = $candidate;

                    break;
                }
            }
        }

        return new ExternalSecretStatus(
            exists: true,
            ready: is_array($condition) && Arr::get($condition, 'status') === 'True',
            reason: is_array($condition) ? $this->nullableString(Arr::get($condition, 'reason')) : null,
            message: is_array($condition) ? $this->nullableString(Arr::get($condition, 'message')) : 'External Secrets Operator has not reported readiness yet.',
            refreshTime: $this->nullableString(Arr::get($payload, 'status.refreshTime')),
        );
    }

    protected function missingStatus(): ExternalSecretStatus
    {
        return new ExternalSecretStatus(
            exists: false,
            ready: false,
            reason: 'NotFound',
            message: 'The ExternalSecret resource has not been deployed.',
        );
    }

    protected function request(): PendingRequest
    {
        $url = rtrim((string) config('services.kubernetes.url'), '/');
        $token = $this->apiToken->resolve(
            configuredToken: (string) config('services.kubernetes.token'),
            tokenFile: (string) config('services.kubernetes.token_file'),
        );

        if ($url === '' || $token === '') {
            throw new KubernetesResourceException('The Kubernetes API is not configured. Set KUBERNETES_API_URL and either KUBERNETES_API_TOKEN or KUBERNETES_API_TOKEN_FILE.');
        }

        $caCertificate = (string) config('services.kubernetes.ca_cert');
        $verify = $caCertificate !== ''
            ? $caCertificate
            : (bool) config('services.kubernetes.verify_tls', true);

        return Http::baseUrl($url)
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout((int) config('services.kubernetes.connect_timeout', 3))
            ->timeout((int) config('services.kubernetes.timeout', 10))
            ->withOptions(['verify' => $verify]);
    }

    protected function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
