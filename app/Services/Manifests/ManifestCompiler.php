<?php

namespace App\Services\Manifests;

use App\Models\ProjectManifest;
use App\Models\ProjectManifestPatch;

class ManifestCompiler
{
    public function __construct(private ManifestPresetRegistry $presets) {}

    /**
     * @return array<string, string>
     */
    public function baseFiles(ProjectManifest $manifest): array
    {
        return $this->presets->renderPreset(
            $manifest->preset_key,
            $manifest->preset_version,
            $manifest->variables,
        );
    }

    /**
     * @return array<string, string>
     */
    public function compile(ProjectManifest $manifest): array
    {
        $files = $this->baseFiles($manifest);

        foreach ($manifest->patches()->orderBy('path')->get() as $patch) {
            if ($patch->operation === ProjectManifestPatch::OperationDelete) {
                unset($files[$patch->path]);

                continue;
            }

            $files[$patch->path] = $patch->content ?? '';
        }

        ksort($files);

        return $files;
    }

    public function compiledHash(ProjectManifest $manifest): string
    {
        return $this->presets->hashFiles($this->compile($manifest));
    }
}
