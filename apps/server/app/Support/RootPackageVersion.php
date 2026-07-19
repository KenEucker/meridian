<?php

namespace App\Support;

use RuntimeException;

class RootPackageVersion
{
    public static function resolve(string $repositoryRoot): string
    {
        $packageJson = rtrim($repositoryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'package.json';

        if (! is_file($packageJson)) {
            throw new RuntimeException("Root package.json was not found at {$packageJson}.");
        }

        $contents = file_get_contents($packageJson);
        if ($contents === false) {
            throw new RuntimeException("Root package.json could not be read at {$packageJson}.");
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Root package.json could not be decoded at {$packageJson}.");
        }

        $version = $decoded['version'] ?? null;
        if (! is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new RuntimeException('Root package.json version must use numeric x.y.z format.');
        }

        return $version;
    }
}
