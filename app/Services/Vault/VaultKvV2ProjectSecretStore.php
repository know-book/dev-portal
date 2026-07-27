<?php

namespace App\Services\Vault;

use App\Contracts\ProjectSecretStore;
use App\Data\SecretDocument;
use App\Exceptions\SecretStoreException;
use App\Exceptions\SecretVersionConflict;
use App\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class VaultKvV2ProjectSecretStore implements ProjectSecretStore
{
    public function read(Project $project): SecretDocument
    {
        try {
            $response = $this->request()->get($this->endpoint($project));

            if ($response->status() === 404) {
                return new SecretDocument([], 0);
            }

            $response->throw();

            return $this->documentFromResponse($response);
        } catch (SecretStoreException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SecretStoreException('Vault could not read this project secret.', previous: $exception);
        }
    }

    /** @param array<string, string> $values */
    public function write(Project $project, array $values, int $expectedVersion): SecretDocument
    {
        try {
            $response = $this->request()->post($this->endpoint($project), [
                'options' => ['cas' => $expectedVersion],
                'data' => (object) $values,
            ]);

            if ($response->status() === 400) {
                throw new SecretVersionConflict('The secret changed after it was revealed. Reveal the latest version and apply your changes again.');
            }

            $response->throw();

            $version = $response->json('data.version');

            if (! is_int($version) && ! ctype_digit((string) $version)) {
                throw new SecretStoreException('Vault returned an invalid secret version.');
            }

            return new SecretDocument($values, (int) $version);
        } catch (SecretStoreException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SecretStoreException('Vault could not update this project secret.', previous: $exception);
        }
    }

    protected function request(): PendingRequest
    {
        $url = rtrim((string) config('services.vault.url'), '/');
        $token = (string) config('services.vault.token');

        if ($url === '' || $token === '') {
            throw new SecretStoreException('Vault is not configured for this environment.');
        }

        $headers = ['X-Vault-Token' => $token];
        $namespace = (string) config('services.vault.namespace');

        if ($namespace !== '') {
            $headers['X-Vault-Namespace'] = $namespace;
        }

        return Http::baseUrl($url)
            ->withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('services.vault.connect_timeout', 3))
            ->timeout((int) config('services.vault.timeout', 10))
            ->withOptions(['verify' => (bool) config('services.vault.verify_tls', true)]);
    }

    protected function endpoint(Project $project): string
    {
        $project->loadMissing('team');
        $mount = collect(explode('/', trim((string) config('services.vault.mount', 'secret'), '/')))
            ->filter()
            ->map(fn (string $segment): string => rawurlencode($segment))
            ->implode('/');

        if ($mount === '') {
            throw new SecretStoreException('Vault KV mount is not configured.');
        }

        return 'v1/'.$mount.'/data/'.rawurlencode($project->team->slug).'/'.rawurlencode($project->slug).'/app';
    }

    protected function documentFromResponse(Response $response): SecretDocument
    {
        $values = $response->json('data.data');
        $version = $response->json('data.metadata.version');

        if (! is_array($values) || (! is_int($version) && ! ctype_digit((string) $version))) {
            throw new SecretStoreException('Vault returned an invalid secret document.');
        }

        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new SecretStoreException('Vault returned a secret that is not a flat string map.');
            }

            $normalized[$key] = $value;
        }

        ksort($normalized);

        return new SecretDocument($normalized, (int) $version);
    }
}
