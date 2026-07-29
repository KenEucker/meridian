<?php

declare(strict_types=1);

namespace App\Services\SystemConfig;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Parses `.env.example` into the System Configuration catalogue (technical
 * spec 22A.2; SYS-001 through SYS-004).
 *
 * `.env.example` is deliberately the single catalogue source rather than a
 * second hand-maintained PHP registry: a variable that is not in the example
 * file is not a variable Meridian claims to know about. `## Heading` lines
 * open a section, a contiguous comment block above a variable is its
 * description, and `# @tag` lines inside that block carry structured metadata
 * (see the file header of `.env.example` for the tag grammar).
 *
 * The parsed catalogue is cached keyed by the Meridian build version and the
 * file's mtime/size, so a redeploy or an edited example file invalidates it
 * (SYS-023). A cache store that is unavailable degrades to parsing per
 * request rather than failing.
 */
class EnvExampleCatalog
{
    private const CACHE_KEY_PREFIX = 'meridian.system-config.catalog.';

    /**
     * @var list<CatalogEntry>|null
     */
    private ?array $loaded = null;

    /**
     * @return list<CatalogEntry>
     */
    public function entries(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $path = $this->path();

        if (! is_file($path)) {
            return $this->loaded = [];
        }

        $cacheKey = self::CACHE_KEY_PREFIX.sha1(implode('|', [
            (string) config('meridian.version'),
            $path,
            (string) @filemtime($path),
            (string) @filesize($path),
        ]));

        try {
            $cached = Cache::get($cacheKey);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached)) {
            return $this->loaded = array_map(
                static fn (array $entry): CatalogEntry => CatalogEntry::fromArray($entry),
                $cached,
            );
        }

        $entries = $this->parse((string) file_get_contents($path));

        try {
            Cache::put(
                $cacheKey,
                array_map(static fn (CatalogEntry $entry): array => $entry->toArray(), $entries),
                now()->addDay(),
            );
        } catch (Throwable) {
            // The catalogue still works without a cache store; it is just
            // parsed again next boot (SYS-023).
        }

        return $this->loaded = $entries;
    }

    public function entry(string $name): ?CatalogEntry
    {
        foreach ($this->entries() as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function sections(): array
    {
        $sections = [];

        foreach ($this->entries() as $entry) {
            if (! in_array($entry->section, $sections, true)) {
                $sections[] = $entry->section;
            }
        }

        return $sections;
    }

    public function path(): string
    {
        return base_path('.env.example');
    }

    /**
     * @return list<CatalogEntry>
     */
    public function parse(string $contents): array
    {
        $entries = [];
        $section = 'General';
        $pendingComments = [];
        $pendingTags = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $pendingComments = [];
                $pendingTags = [];

                continue;
            }

            if (preg_match('/^##\s+(.+)$/', $trimmed, $matches) === 1) {
                $section = trim($matches[1]);
                $pendingComments = [];
                $pendingTags = [];

                continue;
            }

            if (preg_match('/^#\s*@([a-z]+)(?:\s+(.*))?$/', $trimmed, $matches) === 1) {
                $pendingTags[] = [strtolower($matches[1]), trim($matches[2] ?? '')];

                continue;
            }

            // A commented-out variable is still a catalogued variable — it is
            // how the example file documents optional settings.
            if (preg_match('/^#\s*([A-Z][A-Z0-9_]*)=(.*)$/', $trimmed, $matches) === 1) {
                $entries[] = $this->makeEntry(
                    name: $matches[1],
                    rawValue: $matches[2],
                    commentedOut: true,
                    section: $section,
                    comments: $pendingComments,
                    tags: $pendingTags,
                );
                $pendingComments = [];
                $pendingTags = [];

                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                $pendingComments[] = ltrim(substr($trimmed, 1));

                continue;
            }

            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $trimmed, $matches) === 1) {
                $entries[] = $this->makeEntry(
                    name: $matches[1],
                    rawValue: $matches[2],
                    commentedOut: false,
                    section: $section,
                    comments: $pendingComments,
                    tags: $pendingTags,
                );
            }

            $pendingComments = [];
            $pendingTags = [];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $comments
     * @param  list<array{0: string, 1: string}>  $tags
     */
    private function makeEntry(
        string $name,
        string $rawValue,
        bool $commentedOut,
        string $section,
        array $comments,
        array $tags,
    ): CatalogEntry {
        $label = null;
        $type = null;
        $enumValues = [];
        $configKeys = [];
        $secret = false;
        $required = false;
        $bootstrap = false;
        $managed = null;
        $readonly = false;
        $restart = null;

        foreach ($tags as [$tag, $value]) {
            switch ($tag) {
                case 'label':
                    $label = $value !== '' ? $value : null;
                    break;
                case 'type':
                    if (str_starts_with($value, 'enum:')) {
                        $type = CatalogEntry::TYPE_ENUM;
                        $enumValues = array_values(array_filter(explode('|', substr($value, 5))));
                    } elseif (in_array($value, [
                        CatalogEntry::TYPE_STRING,
                        CatalogEntry::TYPE_BOOLEAN,
                        CatalogEntry::TYPE_INTEGER,
                        CatalogEntry::TYPE_FLOAT,
                        CatalogEntry::TYPE_JSON,
                        CatalogEntry::TYPE_URL,
                        CatalogEntry::TYPE_DURATION,
                    ], true)) {
                        $type = $value;
                    }
                    break;
                case 'config':
                    $configKeys = array_values(array_filter(array_map('trim', explode(',', $value))));
                    break;
                case 'secret':
                    $secret = true;
                    break;
                case 'required':
                    $required = true;
                    break;
                case 'bootstrap':
                    $bootstrap = true;
                    break;
                case 'managed':
                    $managed = $value !== '' ? $value : 'Meridian';
                    break;
                case 'readonly':
                    $readonly = true;
                    break;
                case 'restart':
                    $restart = in_array($value, ['workers', 'deploy'], true) ? $value : null;
                    break;
            }
        }

        return new CatalogEntry(
            name: $name,
            label: $label ?? $this->defaultLabel($name),
            description: implode(' ', $comments),
            section: $section,
            exampleValue: $rawValue === '' ? null : $this->stripQuotes($rawValue),
            commentedOut: $commentedOut,
            // A variable with no declared type stays a string. Guessing a
            // stricter type from the example value could change Laravel
            // semantics, which is exactly what SYS-004 forbids.
            type: $type ?? CatalogEntry::TYPE_STRING,
            enumValues: $enumValues,
            configKeys: $configKeys,
            secret: $secret,
            required: $required,
            bootstrapLocked: $bootstrap,
            managedLabel: $managed,
            readonly: $readonly,
            restart: $restart,
        );
    }

    private function defaultLabel(string $name): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $name)));
    }

    private function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2
            && (str_starts_with($value, '"') && str_ends_with($value, '"')
                || str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
