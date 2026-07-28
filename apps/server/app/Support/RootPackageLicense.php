<?php

namespace App\Support;

use RuntimeException;

/**
 * The license the repository is actually published under, read from the root
 * package manifest (GOD-033).
 *
 * The console footer states this value rather than a string typed into a
 * template, so the footer cannot claim one license while the repository is
 * published under another — which is exactly the defect M15C.5 exists to fix.
 */
class RootPackageLicense
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

        $license = $decoded['license'] ?? null;
        if (! is_string($license) || trim($license) === '') {
            throw new RuntimeException('Root package.json must declare a license.');
        }

        return trim($license);
    }
}
