<?php

namespace App\Data;

readonly class KubernetesDeploymentStatus
{
    /** @param list<string> $images */
    public function __construct(
        public string $name,
        public int $desiredReplicas,
        public int $readyReplicas,
        public int $availableReplicas,
        public int $updatedReplicas,
        public array $images,
        public ?string $message = null,
    ) {}

    public function ready(): bool
    {
        return $this->desiredReplicas > 0
            && $this->readyReplicas >= $this->desiredReplicas
            && $this->availableReplicas >= $this->desiredReplicas
            && $this->updatedReplicas >= $this->desiredReplicas;
    }

    /** @return array{name: string, desired_replicas: int, ready_replicas: int, available_replicas: int, updated_replicas: int, images: list<string>, message: string|null, ready: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'desired_replicas' => $this->desiredReplicas,
            'ready_replicas' => $this->readyReplicas,
            'available_replicas' => $this->availableReplicas,
            'updated_replicas' => $this->updatedReplicas,
            'images' => $this->images,
            'message' => $this->message,
            'ready' => $this->ready(),
        ];
    }
}
