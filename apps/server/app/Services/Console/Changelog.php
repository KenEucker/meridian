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
     * Where one entry's pull request can be read in full (GOD-019).
     *
     * Built from configuration and the number rather than stored per entry.
     * The packaged file is generated from git history, which carries numbers
     * and not addresses, so a stored URL would be absent from exactly the
     * entries that render on a node with no network — and present-but-wrong on
     * every entry generated before the repository moved.
     *
     * Null when no repository is configured, which is a real state: a build
     * with refresh switched off and the repository blanked has nothing honest
     * to point at, and the page says the number without pretending to be a
     * link. The address is not reachable offline either way. That is fine — the
     * page renders and reads completely without it (GOD-021), and this is the
     * one thing on it that is worth following when there *is* a network.
     */
    public function pullRequestUrl(int $number): ?string
    {
        if ($number <= 0) {
            return null;
        }

        $configured = config('meridian.changelog.repository_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/')."/pull/{$number}";
        }

        $repository = config('meridian.changelog.refresh.repository');

        if (! is_string($repository) || trim($repository, '/') === '') {
            return null;
        }

        return 'https://github.com/'.trim($repository, '/')."/pull/{$number}";
    }

    /**
     * The releases with each entry's pull request address attached.
     *
     * Resolved here rather than in the view, so the template has a value to
     * print instead of configuration to reason about.
     *
     * @param  list<array{version: string, entries: list<array<string, mixed>>}>  $releases
     * @return list<array{version: string, entries: list<array<string, mixed>>}>
     */
    public function withPullRequestUrls(array $releases): array
    {
        return array_map(
            fn (array $release): array => [
                'version' => $release['version'],
                'entries' => array_map(
                    fn (array $entry): array => $entry + [
                        'url' => $this->pullRequestUrl((int) ($entry['number'] ?? 0)),
                    ],
                    $release['entries'],
                ),
            ],
            $releases,
        );
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
