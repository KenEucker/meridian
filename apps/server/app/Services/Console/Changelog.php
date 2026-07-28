<?php

declare(strict_types=1);

namespace App\Services\Console;

/**
 * The changelog packaged with this deployment (GOD-018 through GOD-021;
 * technical spec 22.7).
 *
 * The packaged file is the baseline and is always sufficient to render the
 * page. {@see ChangelogRefresh} may merge newer entries into it on a central
 * node, but nothing here depends on that having happened, or on a network
 * existing at all.
 *
 * Entries are not filtered by conventional-commit type or change category
 * (GOD-020). Every merged pull request appears.
 */
final class Changelog
{
    public function path(): string
    {
        $configured = config('meridian.changelog.path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : resource_path('changelog/changelog.json');
    }

    public function isPackaged(): bool
    {
        return is_file($this->path());
    }

    /**
     * The packaged baseline, grouped by the Meridian version each change
     * shipped in, newest version first.
     *
     * @return list<array{version: string, entries: list<array<string, mixed>>}>
     */
    public function releases(): array
    {
        return $this->normalize($this->baseline()['releases'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function baseline(): array
    {
        if (! $this->isPackaged()) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path()), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Merge refreshed entries into the packaged baseline. A pull request number
     * appears once: the refreshed entry wins, because it carries the real pull
     * request title and body rather than the merge commit's approximation of
     * them.
     *
     * @param  list<array{version: string, entries: list<array<string, mixed>>}>  $baseline
     * @param  list<array<string, mixed>>  $refreshed
     * @return list<array{version: string, entries: list<array<string, mixed>>}>
     */
    public function merge(array $baseline, array $refreshed): array
    {
        /** @var array<string, array<int, array<string, mixed>>> $byVersion */
        $byVersion = [];

        foreach ($baseline as $release) {
            $version = (string) ($release['version'] ?? '');

            foreach ($release['entries'] ?? [] as $entry) {
                $number = (int) ($entry['number'] ?? 0);

                if ($number > 0) {
                    $byVersion[$version][$number] = $entry;
                }
            }
        }

        foreach ($refreshed as $entry) {
            $number = (int) ($entry['number'] ?? 0);
            $version = (string) ($entry['version'] ?? '');

            if ($number <= 0 || $version === '') {
                continue;
            }

            // A refreshed entry may correct which version a change shipped in,
            // so any stale copy under another version is dropped first.
            foreach ($byVersion as $existingVersion => $entries) {
                if ($existingVersion !== $version) {
                    unset($byVersion[$existingVersion][$number]);
                }
            }

            unset($entry['version']);
            $byVersion[$version][$number] = $entry;
        }

        $releases = [];

        foreach ($byVersion as $version => $entries) {
            if ($entries === []) {
                continue;
            }

            $releases[] = ['version' => $version, 'entries' => array_values($entries)];
        }

        return $this->normalize($releases);
    }

    /**
     * @param  array<int, mixed>  $releases
     * @return list<array{version: string, entries: list<array<string, mixed>>}>
     */
    private function normalize(array $releases): array
    {
        $normalized = [];

        foreach ($releases as $release) {
            if (! is_array($release)) {
                continue;
            }

            $version = (string) ($release['version'] ?? '');
            $entries = array_values(array_filter(
                (array) ($release['entries'] ?? []),
                static fn ($entry): bool => is_array($entry),
            ));

            if ($version === '' || $entries === []) {
                continue;
            }

            usort($entries, static fn (array $a, array $b): int => (int) ($b['number'] ?? 0) <=> (int) ($a['number'] ?? 0));

            $normalized[] = ['version' => $version, 'entries' => $entries];
        }

        usort(
            $normalized,
            static fn (array $a, array $b): int => version_compare($b['version'], $a['version']),
        );

        return $normalized;
    }
}
