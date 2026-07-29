<?php

namespace App\Services\ArgoCd;

use App\Exceptions\ArgoCdException;
use App\Services\Kubernetes\KubernetesApiToken;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class KubernetesApplicationClient
{
    public function __construct(private KubernetesApiToken $apiToken) {}

    /**
     * @param  array<string, mixed>  $application
     * @return array<string, mixed>
     */
    public function apply(array $application): array
    {
        $name = data_get($application, 'metadata.name');
        $namespace = data_get($application, 'metadata.namespace');

        if (! is_string($name) || ! is_string($namespace) || $name === '' || $namespace === '') {
            throw new ArgoCdException('The Argo CD Application definition is missing its identity.');
        }

        try {
            $body = json_encode($application, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $endpoint = $this->endpoint($name, $namespace);
            $response = $this->request()
                ->withQueryParameters([
                    'fieldManager' => 'dev-portal',
                    'force' => 'true',
                ])
                ->withBody($body, 'application/apply-patch+yaml')
                ->patch($endpoint);

            $response->throw();
            $payload = $response->json();

            if (! is_array($payload)) {
                throw new ArgoCdException('Kubernetes returned an invalid Application resource.');
            }

            return $payload;
        } catch (ArgoCdException $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            throw new ArgoCdException('The Argo CD Application definition could not be encoded.', previous: $exception);
        } catch (Throwable $exception) {
            throw new ArgoCdException('Kubernetes rejected the Argo CD Application update.', previous: $exception);
        }
    }

    /** @return array<string, mixed>|null */
    public function status(string $name, string $namespace, bool $hardRefresh = false): ?array
    {
        try {
            $request = $this->request();
            $endpoint = $this->endpoint($name, $namespace);

            if ($hardRefresh) {
                $body = json_encode([
                    'metadata' => [
                        'annotations' => [
                            'argocd.argoproj.io/refresh' => 'hard',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR);
                $response = $request
                    ->withBody($body, 'application/merge-patch+json')
                    ->patch($endpoint);
            } else {
                $response = $request->get($endpoint);
            }

            if ($response->status() === 404) {
                return null;
            }

            $response->throw();
            $payload = $response->json();

            if (! is_array($payload)) {
                throw new ArgoCdException('Kubernetes returned an invalid Application status.');
            }

            return $payload;
        } catch (ArgoCdException $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            throw new ArgoCdException('The Argo CD Application refresh request could not be encoded.', previous: $exception);
        } catch (Throwable $exception) {
            throw new ArgoCdException('Kubernetes could not read the Argo CD Application status.', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    public function sync(string $name, string $namespace): array
    {
        try {
            $body = json_encode([
                'operation' => [
                    'sync' => [
                        'prune' => (bool) config('services.argocd.auto_prune', true),
                        'syncOptions' => ['CreateNamespace=true'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
            $response = $this->request()
                ->withBody($body, 'application/merge-patch+json')
                ->patch($this->endpoint($name, $namespace));

            $response->throw();
            $payload = $response->json();

            if (! is_array($payload)) {
                throw new ArgoCdException('Kubernetes returned an invalid Application sync response.');
            }

            return $payload;
        } catch (ArgoCdException $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            throw new ArgoCdException('The Argo CD Application sync request could not be encoded.', previous: $exception);
        } catch (Throwable $exception) {
            throw new ArgoCdException('Kubernetes rejected the Argo CD synchronization request.', previous: $exception);
        }
    }

    protected function endpoint(string $name, string $namespace): string
    {
        if ($name === '' || $namespace === '') {
            throw new ArgoCdException('The Argo CD Application identity is incomplete.');
        }

        return 'apis/argoproj.io/v1alpha1/namespaces/'.rawurlencode($namespace).'/applications/'.rawurlencode($name);
    }

    protected function request(): PendingRequest
    {
        $url = rtrim((string) config('services.kubernetes.url'), '/');
        $token = $this->apiToken->resolve(
            configuredToken: (string) config('services.kubernetes.token'),
            tokenFile: (string) config('services.kubernetes.token_file'),
        );

        if ($url === '' || $token === '') {
            throw new ArgoCdException('The Kubernetes API is not configured. Set KUBERNETES_API_URL and either KUBERNETES_API_TOKEN or KUBERNETES_API_TOKEN_FILE.');
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
