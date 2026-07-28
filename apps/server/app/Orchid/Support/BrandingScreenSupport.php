<?php

declare(strict_types=1);

namespace App\Orchid\Support;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Services\Branding\BrandingAssetService;
use App\Services\Branding\BrandingAuthorityException;
use App\Services\Branding\BrandingGovernance;
use App\Services\Branding\BrandingValidationException;
use App\Services\Node\EventAuthorityException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Shared branding handling for the Orchid organization and department screens
 * (M15A.6, M15A.7).
 *
 * The two screens do the same four things — read the current profile into the
 * form, apply a logo upload, apply a logo removal, and turn a refusal into
 * something Orchid will render next to the offending field — and the fourth is
 * the one worth centralising. A `BrandingValidationException` carries the
 * failing pair, the measured ratio, and the required ratio (BRAND-015), and
 * flattening that into a single sentence would throw away the part that tells
 * an operator which of ten colors to change.
 */
trait BrandingScreenSupport
{
    /**
     * Apply a logo upload and a logo removal for one slot.
     *
     * Removal is checked first so that ticking "remove" and choosing a file in
     * the same save ends with the new file, not an empty slot. Whichever way
     * that ambiguity is resolved somebody will be surprised; ending with the
     * asset the operator went to the trouble of choosing is the less
     * destructive surprise.
     */
    private function applyBrandingAsset(
        Request $request,
        Organization|Department $owner,
        string $slot,
        string $fileKey,
        string $removeKey,
    ): void {
        $assets = app(BrandingAssetService::class);
        $actor = $request->user();

        if (! $actor instanceof User) {
            return;
        }

        if ($request->boolean($removeKey)) {
            $this->guardBranding(
                fn () => $assets->remove($owner, $slot, $actor, AuditEvent::SOURCE_ORCHID),
                $removeKey,
            );
        }

        $file = $request->file($fileKey);

        if (! $file instanceof UploadedFile) {
            return;
        }

        $this->guardBranding(
            fn () => $assets->put(
                $owner,
                $slot,
                (string) file_get_contents($file->getRealPath()),
                $actor,
                AuditEvent::SOURCE_ORCHID,
            ),
            $fileKey,
        );
    }

    /**
     * Run a branding write, converting its refusals into field errors.
     *
     * Orchid has no notion of a 422-with-structured-failures, so a contrast
     * refusal becomes one validation message per failing pair. An authority or
     * freeze refusal becomes a single message, because there is nothing to
     * enumerate: the edit was attempted in the wrong place or at the wrong
     * time (BRAND-021).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $write
     * @return TReturn
     *
     * @throws ValidationException
     */
    private function guardBranding(callable $write, string $field): mixed
    {
        try {
            return $write();
        } catch (BrandingValidationException $exception) {
            $failures = $exception->failures;

            throw ValidationException::withMessages([
                $field => $failures === []
                    ? [$exception->getMessage()]
                    : array_map(
                        static fn ($failure): string => $failure->describe(),
                        $failures,
                    ),
            ]);
        } catch (BrandingAuthorityException|EventAuthorityException $exception) {
            throw ValidationException::withMessages([
                $field => [$exception->getMessage()],
            ]);
        }
    }

    /**
     * A short line explaining why branding cannot be edited right now, or null
     * when it can. Shown so an operator learns the reason before a save is
     * refused rather than after (BRAND-021).
     */
    private function brandingLockReason(?string $organizationId): ?string
    {
        if ($organizationId === null || $organizationId === '') {
            return null;
        }

        $governance = app(BrandingGovernance::class);

        if (! $governance->holdsBrandingAuthority()) {
            return __('Branding is edited on the central node. This node cannot change it; the change syncs down from central.');
        }

        $event = $governance->activeEventFor($organizationId);

        return $event === null
            ? null
            : __('Branding is frozen while ":event" is in its active event window.', ['event' => (string) $event->name]);
    }

    /**
     * The public URL for a stored branding asset, or null when the slot is
     * empty.
     */
    private function brandingAssetUrl(mixed $attachmentId): ?string
    {
        if (! is_string($attachmentId) || $attachmentId === '') {
            return null;
        }

        return Attachment::query()->whereKey($attachmentId)->exists()
            ? route('branding.asset', ['attachment' => $attachmentId])
            : null;
    }
}
