<?php

namespace App\Services\Manifests;

use Symfony\Component\Yaml\Yaml;

class KustomizationImageTagPreserver
{
    public function preserve(string $generated, string $current): string
    {
        $parsed = Yaml::parse($current);

        if (! is_array($parsed) || ! is_array($parsed['images'] ?? null)) {
            return $generated;
        }

        $tags = [];

        foreach ($parsed['images'] as $image) {
            if (! is_array($image)) {
                continue;
            }

            $name = $image['name'] ?? null;
            $tag = $image['newTag'] ?? null;

            if (is_string($name) && is_string($tag) && $this->isValidImageTag($tag)) {
                $tags[$name] = $tag;
            }
        }

        if ($tags === []) {
            return $generated;
        }

        $lines = preg_split('/(?<=\n)/', $generated);

        if ($lines === false) {
            return $generated;
        }

        $inImages = false;
        $currentImage = null;

        foreach ($lines as $index => $line) {
            if (preg_match('/^images:\s*(?:#.*)?(?:\r?\n)?$/', $line) === 1) {
                $inImages = true;
                $currentImage = null;

                continue;
            }

            if ($inImages && preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*:\s*/', $line) === 1) {
                $inImages = false;
                $currentImage = null;
            }

            if (! $inImages) {
                continue;
            }

            if (preg_match('/^\s*-\s*name:\s*["\']?([^"\'\r\n]+?)["\']?\s*(?:#.*)?(?:\r?\n)?$/', $line, $matches) === 1) {
                $currentImage = trim($matches[1]);

                continue;
            }

            if ($currentImage === null || ! isset($tags[$currentImage])) {
                continue;
            }

            if (preg_match('/^(\s*newTag:\s*).*(\r?\n)?$/', $line, $matches) === 1) {
                $lines[$index] = $matches[1].$tags[$currentImage].($matches[2] ?? '');
                $currentImage = null;
            }
        }

        return implode('', $lines);
    }

    protected function isValidImageTag(string $tag): bool
    {
        return preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}$/', $tag) === 1;
    }
}
