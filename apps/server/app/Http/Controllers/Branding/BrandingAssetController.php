<?php

namespace App\Http\Controllers\Branding;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a branding logo from the private attachments disk (BRAND-023).
 *
 * Branding assets are served without a per-user check, unlike Field Report
 * photos. A logo is the mark an organization puts on its own staff-facing
 * screens, its generated PDF exports, and its system email (BRAND-002); it is
 * identity, not operational content, and a signed short-lived URL would break
 * exactly the places it has to appear — an email a staff member opens a day
 * later, or a device rendering from cache with no network (BRAND-022).
 *
 * The route is still narrow. Only an attachment whose morph type is an
 * organization or a department is served, so this cannot become a second,
 * unauthenticated door onto Field Report photos.
 */
final class BrandingAssetController extends Controller
{
    public function show(Attachment $attachment): StreamedResponse|Response
    {
        if (! $attachment->isBrandingAsset() || $attachment->deleted_at !== null) {
            abort(404);
        }

        $disk = Storage::disk($attachment->storage_disk);

        if (! $disk->exists($attachment->storage_path)) {
            abort(404);
        }

        return $disk->response(
            $attachment->storage_path,
            $attachment->filename,
            [
                'Content-Type' => $attachment->mime_type,
                // Logos change rarely and are replaced by creating a new
                // attachment with a new id, so a long cache never serves a
                // stale asset: the URL changes when the logo does.
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
            'inline',
        );
    }
}
