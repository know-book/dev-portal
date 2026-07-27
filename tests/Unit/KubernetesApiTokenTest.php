<?php

use App\Services\Kubernetes\KubernetesApiToken;

test('configured token takes precedence over the service account token file', function () {
    $tokenFile = temporaryKubernetesApiTokenFile();
    file_put_contents($tokenFile, 'file-token');

    try {
        $token = (new KubernetesApiToken)->resolve('configured-token', $tokenFile);

        expect($token)->toBe('configured-token');
    } finally {
        unlink($tokenFile);
    }
});

test('service account token is read from the file for every request', function () {
    $apiToken = new KubernetesApiToken;
    $tokenFile = temporaryKubernetesApiTokenFile();
    file_put_contents($tokenFile, "first-token\n");

    try {
        expect($apiToken->resolve('', $tokenFile))->toBe('first-token');

        file_put_contents($tokenFile, 'rotated-token');

        expect($apiToken->resolve('', $tokenFile))->toBe('rotated-token');
    } finally {
        unlink($tokenFile);
    }
});

test('missing service account token file leaves kubernetes unconfigured', function () {
    expect((new KubernetesApiToken)->resolve('', '/missing/service-account/token'))->toBe('');
});

function temporaryKubernetesApiTokenFile(): string
{
    $tokenFile = tempnam(sys_get_temp_dir(), 'kubernetes-api-token-');

    if ($tokenFile === false) {
        throw new RuntimeException('Unable to create a temporary Kubernetes token file.');
    }

    return $tokenFile;
}
