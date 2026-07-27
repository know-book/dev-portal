<?php

namespace App\Data;

use Illuminate\Support\Arr;

readonly class ArgoApplicationStatus
{
    public function __construct(
        public string $name,
        public string $syncStatus,
        public string $healthStatus,
        public ?string $revision = null,
        public ?string $operationPhase = null,
        public ?string $message = null,
    ) {}

    /** @param array<string, mixed> $application */
    public static function fromApplication(array $application, string $fallbackName): self
    {
        return new self(
            name: (string) Arr::get($application, 'metadata.name', $fallbackName),
            syncStatus: (string) Arr::get($application, 'status.sync.status', 'Unknown'),
            healthStatus: (string) Arr::get($application, 'status.health.status', 'Unknown'),
            revision: self::nullableString(Arr::get($application, 'status.sync.revision')),
            operationPhase: self::nullableString(Arr::get($application, 'status.operationState.phase')),
            message: self::nullableString(Arr::get($application, 'status.operationState.message')),
        );
    }

    protected static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
