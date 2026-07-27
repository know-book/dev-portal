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
            $endpoint = 'apis/argoproj.io/v1alpha1/namespaces/'.rawurlencode($namespace).'/applications/'.rawurlencode($name);
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
