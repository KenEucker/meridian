<?php

declare(strict_types=1);

namespace App\Services\Console;

use Illuminate\Support\Str;

/**
 * The technician documentation packaged with this deployment (GOD-012 through
 * GOD-017; technical spec 22.6).
 *
 * Documentation is read from a packaged directory, never from the network and
 * never from `docs/` at large. The packaging step copies `docs/technician/` and
 * nothing else, so the specification, QA scripts, development plan,
 * traceability matrix, and issue documents are not present to be served — the
 * boundary is enforced by what ships, not by a filter that could be widened by
 * accident (GOD-014, GOD-015).
 *
 * Documents are addressed by slug, and a slug that is not in the manifest is
 * not a document. Nothing here takes a path from the caller, so no request can
 * walk out of the packaged directory.
 */
final class TechnicianDocumentation
{
    public function directory(): string
    {
        $configured = config('meridian.technician_docs.path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : resource_path('technician-docs');
    }

    public function isPackaged(): bool
    {
        return is_file($this->manifestPath());
    }

    /**
     * The Meridian version this documentation was packaged from, or null when
     * no documentation is packaged with this build.
     */
    public function version(): ?string
    {
        $version = $this->manifest()['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function buildVersion(): string
    {
        return (string) config('meridian.version');
    }

    /**
     * Whether the packaged documentation was built from the running version
     * (GOD-017). A mismatch is not an error — a deployment can legitimately run
     * documentation from the release it shipped with — but a technician reading
     * a procedure deserves to know.
     */
    public function matchesBuild(): bool
    {
        return $this->version() !== null && $this->version() === $this->buildVersion();
    }

    /**
     * @return list<array{slug: string, title: string, headings: list<string>}>
     */
    public function index(string $filter = ''): array
    {
        $documents = [];

        foreach ($this->manifest()['documents'] ?? [] as $document) {
            if (! is_array($document) || ! is_string($document['slug'] ?? null)) {
                continue;
            }

            $entry = [
                'slug' => $document['slug'],
                'title' => (string) ($document['title'] ?? $document['slug']),
                'headings' => array_values(array_filter(
                    (array) ($document['headings'] ?? []),
                    static fn ($heading): bool => is_string($heading),
                )),
            ];

            if ($this->matchesFilter($entry, $filter)) {
                $documents[] = $entry;
            }
        }

        return $documents;
    }

    /**
     * The slug the Documentation page opens by default: the packaged index.
     */
    public function defaultSlug(): ?string
    {
        $documents = $this->index();

        return $documents === [] ? null : $documents[0]['slug'];
    }

    /**
     * @return array{slug: string, title: string, html: string}|null
     */
    public function document(?string $slug): ?array
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        foreach ($this->manifest()['documents'] ?? [] as $document) {
            if (! is_array($document) || ($document['slug'] ?? null) !== $slug) {
                continue;
            }

            $file = (string) ($document['file'] ?? '');
            $markdown = $this->read($file);

            if ($markdown === null) {
                return null;
            }

            return [
                'slug' => $slug,
                'title' => (string) ($document['title'] ?? $slug),
                'html' => $this->render($markdown),
            ];
        }

        return null;
    }

    /**
     * Filtering matches the document title and every heading inside it
     * (GOD-016), because a technician looking for "pairing token" is looking for
     * a section, not a file name.
     *
     * @param  array{slug: string, title: string, headings: list<string>}  $document
     */
    private function matchesFilter(array $document, string $filter): bool
    {
        $needle = trim($filter);

        if ($needle === '') {
            return true;
        }

        if (Str::contains($document['title'], $needle, ignoreCase: true)) {
            return true;
        }

        foreach ($document['headings'] as $heading) {
            if (Str::contains($heading, $needle, ignoreCase: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Headings, lists, tables, and fenced code, rendered with the same raw-HTML
     * and unsafe-link safeguards the product document renderer uses (GOD-016).
     */
    private function render(string $markdown): string
    {
        return (string) Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * The file name comes from the manifest, not from a request, and is still
     * reduced to a base name before it is joined to the packaged directory.
     */
    private function read(string $file): ?string
    {
        $name = basename($file);

        if ($name === '' || ! str_ends_with(strtolower($name), '.md')) {
            return null;
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $path = $this->manifestPath();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function manifestPath(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'manifest.json';
    }
}
