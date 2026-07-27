<?php

namespace App\Services\Kubernetes;

final class KubernetesApiToken
{
    public function resolve(string $configuredToken, string $tokenFile): string
    {
        $configuredToken = trim($configuredToken);

        if ($configuredToken !== '') {
            return $configuredToken;
        }

        $tokenFile = trim($tokenFile);

        if ($tokenFile === '' || ! is_readable($tokenFile)) {
            return '';
        }

        $token = file_get_contents($tokenFile);

        return is_string($token) ? trim($token) : '';
    }
}
