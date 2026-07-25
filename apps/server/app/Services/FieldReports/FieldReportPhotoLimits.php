<?php

namespace App\Services\FieldReports;

/**
 * Alpha 1 Field Report photo capture limits (M9.7).
 *
 * Technical spec section 18.3 and data/API section 10.17.
 */
final class FieldReportPhotoLimits
{
    public const MAX_COUNT = 2;

    public const MAX_WIDTH = 2560;

    public const MAX_HEIGHT = 1900;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Largest source bitmap the server will decode, in pixels.
     *
     * GD decodes to 4 bytes per pixel, so an unbounded source is a memory
     * exhaustion vector regardless of how few bytes arrived on the wire.
     */
    public const MAX_SOURCE_PIXELS = 50_000_000;

    /**
     * Upper bound for the temporary `memory_limit` raise used while decoding,
     * resizing, and re-encoding one photo.
     */
    public const MEMORY_CEILING_BYTES = 512 * 1024 * 1024;

    /**
     * Slack added on top of the estimated GD working set.
     */
    public const MEMORY_HEADROOM_BYTES = 32 * 1024 * 1024;

    public const PREFERRED_MIME = 'image/webp';

    public const FALLBACK_MIME = 'image/jpeg';

    public const REJECTED_MIME = 'image/gif';
}
