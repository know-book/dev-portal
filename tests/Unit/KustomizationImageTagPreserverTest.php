<?php

use App\Services\Manifests\KustomizationImageTagPreserver;

test('it preserves deployed image tags without reformatting generated YAML', function () {
    $generated = <<<'YAML'
resources:
  - deployment.yaml

images:
  - name: ghcr.io/acme/app
    newTag: latest
  - name: ghcr.io/acme/nginx
    newTag: latest
YAML;
    $current = <<<'YAML'
resources:
- deployment.yaml
images:
- name: ghcr.io/acme/app
  newName: ghcr.io/acme/app
  newTag: sha-1234567
- name: ghcr.io/acme/nginx
  newName: ghcr.io/acme/nginx
  newTag: sha-7654321
YAML;

    $preserved = (new KustomizationImageTagPreserver)->preserve($generated, $current);

    expect($preserved)
        ->toContain("resources:\n  - deployment.yaml")
        ->toContain('newTag: sha-1234567', 'newTag: sha-7654321')
        ->not->toContain('newName:', 'newTag: latest');
});
