<?php

namespace App\Services\Kubernetes;

use App\Data\KubernetesDeploymentStatus;
use App\Exceptions\KubernetesResourceException;
use App\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

class KubernetesDeploymentClient
{
    public function __construct(private KubernetesApiToken $apiToken) {}

    /** @return list<KubernetesDeploymentStatus> */
    public function status(Project $project): array
    {
        try {
            $response = $this->request()->get($this->endpoint($project));

            if ($response->status() === 404) {
                return [];
            }

            $response->throw();
            $items = $response->json('items');

            if (! is_array($items)) {
                throw new KubernetesResourceException('Kubernetes returned an invalid Deployment list.');
            }

            $deployments = [];

            foreach ($items as $item) {
                if (is_array($item)) {
                    $deployments[] = $this->statusFromPayload($item);
                }
            }

            return $deployments;
        } catch (KubernetesResourceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new KubernetesResourceException('Kubernetes could not read the project Deployment status.', previous: $exception);
        }
    }

    protected function endpoint(Project $project): string
    {
        $project->loadMissing('manifest');
        $namespace = Arr::get($project->manifest->variables, 'namespace');

        if (! is_string($namespace) || $namespace === '') {
            throw new KubernetesResourceException('The manifest workspace does not define a Deployment namespace.');
        }

        return 'apis/apps/v1/namespaces/'.rawurlencode($namespace).'/deployments';
    }

    /** @param array<string, mixed> $payload */
    protected function statusFromPayload(array $payload): KubernetesDeploymentStatus
    {
        $containers = Arr::get($payload, 'spec.template.spec.containers', []);
        $images = [];

        if (is_array($containers)) {
            foreach ($containers as $container) {
                $image = is_array($container) ? Arr::get($container, 'image') : null;

                if (is_string($image) && $image !== '') {
                    $images[] = $image;
                }
            }
        }

        return new KubernetesDeploymentStatus(
            name: (string) Arr::get($payload, 'metadata.name', 'unknown'),
            desiredReplicas: (int) Arr::get($payload, 'spec.replicas', 1),
            readyReplicas: (int) Arr::get($payload, 'status.readyReplicas', 0),
            availableReplicas: (int) Arr::get($payload, 'status.availableReplicas', 0),
            updatedReplicas: (int) Arr::get($payload, 'status.updatedReplicas', 0),
            images: array_values(array_unique($images)),
            message: $this->conditionMessage($payload),
        );
    }

    /** @param array<string, mixed> $payload */
    protected function conditionMessage(array $payload): ?string
    {
        $conditions = Arr::get($payload, 'status.conditions', []);

        if (! is_array($conditions)) {
            return null;
        }

        foreach (array_reverse($conditions) as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $message = Arr::get($condition, 'message');

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return null;
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
}
