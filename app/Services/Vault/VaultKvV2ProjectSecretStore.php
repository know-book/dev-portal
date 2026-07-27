<?php

namespace App\Services\Vault;

use App\Contracts\ProjectSecretStore;
use App\Data\SecretDocument;
use App\Data\SecretMetadata;
use App\Exceptions\SecretStoreException;
use App\Exceptions\SecretVersionConflict;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class VaultKvV2ProjectSecretStore implements ProjectSecretStore
{
    public function read(Project $project): SecretDocument
    {
        try {
            $endpoint = $this->endpoint($project, 'data');
            $response = $this->request()->get($endpoint);

            if ($response->status() === 404) {
                return new SecretDocument([], 0);
            }

            if ($response->failed()) {
                throw $this->responseFailure($project, 'read', $endpoint, $response);
            }

            return $this->documentFromResponse($project, $response);
        } catch (SecretStoreException $exception) {
            report($exception);

            throw $exception;
        } catch (Throwable $exception) {
            $failure = $this->transportFailure($project, 'read', $exception);
            report($failure);

            throw $failure;
        }
    }

    public function metadata(Project $project): SecretMetadata
    {
        try {
            $endpoint = $this->endpoint($project, 'metadata');
            $response = $this->request()->get($endpoint);

            if ($response->status() === 404) {
                return new SecretMetadata(exists: false, version: 0);
            }

            if ($response->failed()) {
                throw $this->responseFailure($project, 'metadata', $endpoint, $response);
            }

            $version = $response->json('data.current_version');

            if (! is_int($version) && ! ctype_digit((string) $version)) {
                throw $this->invalidResponseFailure($project, 'metadata', $endpoint, $response);
            }

            return new SecretMetadata(exists: true, version: (int) $version);
        } catch (SecretStoreException $exception) {
            report($exception);

            throw $exception;
        } catch (Throwable $exception) {
            $failure = $this->transportFailure($project, 'metadata', $exception);
            report($failure);

            throw $failure;
        }
    }

    /** @param array<string, string> $values */
    public function write(Project $project, array $values, int $expectedVersion): SecretDocument
    {
        try {
            $endpoint = $this->endpoint($project, 'data');
            $response = $this->request()->post($endpoint, [
                'options' => ['cas' => $expectedVersion],
                'data' => (object) $values,
            ]);

            if ($response->status() === 400 && $this->isCasMismatch($response)) {
                throw new SecretVersionConflict('The secret changed after it was revealed. Reveal the latest version and apply your changes again.');
            }

            if ($response->failed()) {
                throw $this->responseFailure($project, 'write', $endpoint, $response);
            }

            $version = $response->json('data.version');

            if (! is_int($version) && ! ctype_digit((string) $version)) {
                throw $this->invalidResponseFailure($project, 'write', $endpoint, $response);
            }

            return new SecretDocument($values, (int) $version);
        } catch (SecretVersionConflict $exception) {
            throw $exception;
        } catch (SecretStoreException $exception) {
            report($exception);

            throw $exception;
        } catch (Throwable $exception) {
            $failure = $this->transportFailure($project, 'write', $exception);
            report($failure);

            throw $failure;
        }
    }

    protected function request(): PendingRequest
    {
        $url = rtrim((string) config('services.vault.url'), '/');
        $token = (string) config('services.vault.token');

        if ($url === '' || $token === '') {
            $diagnosticId = (string) Str::ulid();

            throw new SecretStoreException(
                "Vault is not configured. Set VAULT_ADDR and VAULT_TOKEN. Reference: {$diagnosticId}.",
                diagnosticContext: [
                    'diagnostic_id' => $diagnosticId,
                    'integration' => 'vault',
                    'failure_reason' => 'configuration_missing',
                ],
            );
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

    protected function endpoint(Project $project, string $resource): string
    {
        $project->loadMissing('team');
        $mount = collect(explode('/', trim((string) config('services.vault.mount', 'secret'), '/')))
            ->filter()
            ->map(fn (string $segment): string => rawurlencode($segment))
            ->implode('/');

        if ($mount === '') {
            $diagnosticId = (string) Str::ulid();

            throw new SecretStoreException(
                "Vault KV mount is not configured. Set VAULT_KV_MOUNT. Reference: {$diagnosticId}.",
                diagnosticContext: [
                    'diagnostic_id' => $diagnosticId,
                    'integration' => 'vault',
                    'failure_reason' => 'mount_missing',
                    'project_id' => $project->id,
                    'team_id' => $project->team_id,
                ],
            );
        }

        return 'v1/'.$mount.'/'.rawurlencode($resource).'/'.rawurlencode($project->team->slug).'/'.rawurlencode($project->slug).'/app';
    }

    protected function documentFromResponse(Project $project, Response $response): SecretDocument
    {
        $values = $response->json('data.data');
        $version = $response->json('data.metadata.version');

        if (! is_array($values) || (! is_int($version) && ! ctype_digit((string) $version))) {
            throw $this->invalidResponseFailure($project, 'read', $this->endpoint($project, 'data'), $response);
        }

        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw $this->invalidResponseFailure($project, 'read', $this->endpoint($project, 'data'), $response);
            }

            $normalized[$key] = $value;
        }

        ksort($normalized);

        return new SecretDocument($normalized, (int) $version);
    }

    protected function responseFailure(
        Project $project,
        string $operation,
        string $endpoint,
        Response $response,
    ): SecretStoreException {
        $status = $response->status();
        $diagnosticId = (string) Str::ulid();
        $operationLabel = $this->operationLabel($operation);
        $message = match ($status) {
            400 => "Vault rejected the {$operationLabel} (HTTP 400). Check the KV v2 mount and request configuration.",
            401, 403 => "Vault denied the {$operationLabel} (HTTP {$status}). Check the token capabilities for this path.",
            404 => "Vault API path for the {$operationLabel} was not found (HTTP 404). Check VAULT_ADDR and VAULT_KV_MOUNT.",
            405 => 'Vault does not support this operation at the configured path (HTTP 405). Check that the mount uses KV v2.',
            412 => "Vault cannot process the {$operationLabel} yet (HTTP 412). Retry after replication catches up.",
            429 => "Vault throttled the {$operationLabel} (HTTP 429). Retry shortly.",
            500 => "Vault failed internally during the {$operationLabel} (HTTP 500).",
            501 => 'Vault is not initialized (HTTP 501).',
            502 => "Vault dependency failed during the {$operationLabel} (HTTP 502).",
            503 => 'Vault is sealed, unavailable, or overloaded (HTTP 503).',
            default => "Vault failed the {$operationLabel} (HTTP {$status}).",
        };

        return new SecretStoreException(
            "{$message} Reference: {$diagnosticId}.",
            diagnosticContext: $this->diagnosticContext(
                $project,
                $operation,
                $endpoint,
                $diagnosticId,
                httpStatus: $status,
                vaultRequestId: $this->vaultRequestId($response),
                failureReason: $this->failureReason($status),
            ),
        );
    }

    protected function invalidResponseFailure(
        Project $project,
        string $operation,
        string $endpoint,
        Response $response,
    ): SecretStoreException {
        $diagnosticId = (string) Str::ulid();

        return new SecretStoreException(
            "Vault returned an invalid response for the {$this->operationLabel($operation)}. Reference: {$diagnosticId}.",
            diagnosticContext: $this->diagnosticContext(
                $project,
                $operation,
                $endpoint,
                $diagnosticId,
                httpStatus: $response->status(),
                vaultRequestId: $this->vaultRequestId($response),
                failureReason: 'invalid_response',
            ),
        );
    }

    protected function transportFailure(Project $project, string $operation, Throwable $exception): SecretStoreException
    {
        $diagnosticId = (string) Str::ulid();
        $operationLabel = $this->operationLabel($operation);
        $isConnectionFailure = $exception instanceof ConnectionException;
        $message = $isConnectionFailure
            ? "Vault connection failed during the {$operationLabel}. Check VAULT_ADDR, DNS, TLS, and network policy."
            : "Vault failed unexpectedly during the {$operationLabel}.";

        return new SecretStoreException(
            "{$message} Reference: {$diagnosticId}.",
            diagnosticContext: $this->diagnosticContext(
                $project,
                $operation,
                $this->endpoint($project, $operation === 'metadata' ? 'metadata' : 'data'),
                $diagnosticId,
                failureReason: $isConnectionFailure ? 'connection_failed' : 'unexpected_failure',
                failureType: $exception::class,
            ),
        );
    }

    /** @return array<string, bool|int|string|null> */
    protected function diagnosticContext(
        Project $project,
        string $operation,
        string $endpoint,
        string $diagnosticId,
        ?int $httpStatus = null,
        ?string $vaultRequestId = null,
        ?string $failureReason = null,
        ?string $failureType = null,
    ): array {
        return [
            'diagnostic_id' => $diagnosticId,
            'integration' => 'vault',
            'operation' => $operation,
            'project_id' => $project->id,
            'team_id' => $project->team_id,
            'vault_path' => Str::after($endpoint, 'v1/'),
            'http_status' => $httpStatus,
            'vault_request_id' => $vaultRequestId,
            'failure_reason' => $failureReason,
            'failure_type' => $failureType,
        ];
    }

    protected function operationLabel(string $operation): string
    {
        return match ($operation) {
            'read' => 'secret read',
            'metadata' => 'secret metadata check',
            'write' => 'secret write',
            default => 'Vault request',
        };
    }

    protected function failureReason(int $status): string
    {
        return match ($status) {
            400 => 'invalid_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'path_not_found',
            405 => 'unsupported_operation',
            412 => 'precondition_failed',
            429 => 'throttled',
            500 => 'internal_error',
            501 => 'not_initialized',
            502 => 'dependency_failed',
            503 => 'unavailable',
            default => 'http_error',
        };
    }

    protected function vaultRequestId(Response $response): ?string
    {
        $requestId = $response->json('request_id');

        return is_string($requestId) && preg_match('/\A[A-Za-z0-9-]{1,128}\z/', $requestId) === 1
            ? $requestId
            : null;
    }

    protected function isCasMismatch(Response $response): bool
    {
        $errors = $response->json('errors');

        if (! is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (is_string($error) && Str::contains(Str::lower($error), 'check-and-set parameter did not match')) {
                return true;
            }
        }

        return false;
    }
}
