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

    public const PREFERRED_MIME = 'image/webp';

    public const FALLBACK_MIME = 'image/jpeg';

    public const REJECTED_MIME = 'image/gif';
}
