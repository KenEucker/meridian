<?php

declare(strict_types=1);

namespace App\Services\Branding;

/**
 * Permitted branding logo types and sizes (BRAND-023).
 *
 * PNG, WebP, and JPEG only. SVG is deliberately excluded even though it is the
 * obvious format for a logo: an SVG is a document that can carry script and
 * external references, branding assets are served inline and unauthenticated
 * so that they render in email and in generated PDFs, and sanitising SVG
 * properly is a dependency decision the technology baseline has not made. A
 * raster logo at the size ceiling below is legible on every surface Alpha 1
 * renders, so nothing is lost that is worth that risk.
 *
 * The byte ceiling is deliberately smaller than the Field Report photo ceiling.
 * A photo is evidence and arrives once from a device; a logo is chrome that
 * every signed-in surface loads, including devices syncing it over a field
 * connection for offline use (BRAND-022).
 */
final class BrandingAssetLimits
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * @var array<string, string> mime type => file extension
     */
    public const PERMITTED_MIME_TYPES = [
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/jpeg' => 'jpg',
    ];

    /**
     * @var list<string>
     */
    public const SLOTS = [
        \App\Models\Attachment::BRANDING_SLOT_FULL_LOCKUP,
        \App\Models\Attachment::BRANDING_SLOT_COMPACT_MARK,
        \App\Models\Attachment::BRANDING_SLOT_DEPARTMENT_LOGO,
    ];

    public static function permits(string $mimeType): bool
    {
        return array_key_exists(strtolower($mimeType), self::PERMITTED_MIME_TYPES);
    }

    public static function extensionFor(string $mimeType): string
    {
        return self::PERMITTED_MIME_TYPES[strtolower($mimeType)] ?? 'bin';
    }

    /**
     * @return list<string>
     */
    public static function permittedMimeTypes(): array
    {
        return array_keys(self::PERMITTED_MIME_TYPES);
    }
}
