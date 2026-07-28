<?php

namespace App\Http\Controllers\Branding;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Branding\BrandingAccess;
use App\Services\Branding\BrandingAdminService;
use App\Services\Branding\BrandingAssetLimits;
use App\Services\Branding\BrandingAssetService;
use App\Services\Branding\BrandingAuthorityException;
use App\Services\Branding\BrandingGovernance;
use App\Services\Branding\BrandingPalette;
use App\Services\Branding\BrandingResolver;
use App\Services\Branding\BrandingValidationException;
use App\Services\Branding\Lettermark;
use App\Services\Node\EventAuthorityException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Product-path branding administration (M15A.6, M15A.7; BRAND-018 through
 * BRAND-021, BRAND-023).
 *
 * Refusals are returned as data, not as prose. A contrast failure comes back
 * with `failures[]` carrying the pair, both colors, the measured ratio, and the
 * required ratio, because BRAND-015 asks the *product* to say those three
 * things and the admin surface renders them per failure. A `422` is a
 * correctable submission; a `409` is a refusal about where or when the edit was
 * attempted, which no amount of editing the colors will fix.
 */
final class BrandingCommandController extends Controller
{
    public function updateOrganization(
        Request $request,
        BrandingAccess $access,
        BrandingAdminService $branding,
        BrandingResolver $resolver,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'palette' => ['sometimes', 'nullable', 'array'],
            'palette.*' => ['string', 'max:32'],
            'department_branding_enabled' => ['sometimes', 'boolean'],
        ]);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $access->canManageOrganizationBranding($user, $organization)) {
            return $this->denied('You do not have permission to edit this organization\'s branding.');
        }

        try {
            $organization = $branding->updateOrganizationBranding(
                $organization,
                array_intersect_key($validated, array_flip([
                    'display_name',
                    'palette',
                    'department_branding_enabled',
                ])),
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (BrandingValidationException $exception) {
            return $this->rejected($exception);
        } catch (BrandingAuthorityException|EventAuthorityException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($this->organizationPayload($organization, $resolver));
    }

    public function updateDepartment(
        Request $request,
        BrandingAccess $access,
        BrandingAdminService $branding,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            'accent' => ['sometimes', 'nullable', 'string', 'max:32'],
            'surface' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $department = Department::query()->with('organization')
            ->findOrFail((string) $validated['department_id']);

        if (! $access->canManageDepartmentBranding($user, $department)) {
            return $this->denied('You do not have permission to edit this department\'s branding.');
        }

        try {
            $department = $branding->updateDepartmentBranding(
                $department,
                array_intersect_key($validated, array_flip(['accent', 'surface'])),
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (BrandingValidationException $exception) {
            return $this->rejected($exception);
        } catch (BrandingAuthorityException|EventAuthorityException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($this->departmentPayload($department));
    }

    /**
     * The contrast result an admin surface shows before saving (BRAND-018).
     */
    public function preview(Request $request, BrandingAccess $access, BrandingAdminService $branding): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
            'department_id' => ['sometimes', 'nullable', 'uuid', Rule::exists(Department::class, 'id')],
            'palette' => ['sometimes', 'nullable', 'array'],
            'palette.*' => ['string', 'max:32'],
            'accent' => ['sometimes', 'nullable', 'string', 'max:32'],
            'surface' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);
        $departmentId = $validated['department_id'] ?? null;

        $permitted = $departmentId !== null
            ? $access->canManageDepartmentBranding(
                $user,
                Department::query()->with('organization')->findOrFail((string) $departmentId),
            )
            : $access->canManageOrganizationBranding($user, $organization);

        if (! $permitted) {
            return $this->denied('You do not have permission to preview this branding profile.');
        }

        try {
            $result = $departmentId !== null
                ? $branding->previewDepartmentBranding(
                    $organization,
                    $validated['accent'] ?? null,
                    $validated['surface'] ?? null,
                )
                : $branding->previewOrganizationPalette(
                    $validated['palette'] ?? BrandingPalette::meridianDefault()->toArray(),
                );
        } catch (BrandingValidationException $exception) {
            return $this->rejected($exception);
        }

        return response()->json($result);
    }

    /**
     * The organization's events and the mark each one carries (BRAND-028).
     *
     * Archived events are included. An archived event still has staff reading
     * its records, and an organizer correcting a mark on last year's event
     * should not have to un-archive it to do so.
     */
    public function events(Request $request, Organization $organization, BrandingAccess $access): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canManageOrganizationBranding($user, $organization)) {
            return $this->denied('You do not have permission to see this organization\'s event branding.');
        }

        $events = Event::query()
            ->where('organization_id', $organization->getKey())
            ->orderByRaw('starts_at is null')
            ->orderByDesc('starts_at')
            ->orderBy('name')
            ->get();

        return response()->json([
            'events' => $events->map(fn (Event $event): array => [
                'event_id' => (string) $event->getKey(),
                'name' => (string) $event->name,
                'lettermark' => Lettermark::forName((string) $event->name),
                'archived' => $event->archived_at !== null,
                'logo_url' => $event->branding_logo_attachment_id !== null
                    ? route('branding.asset', ['attachment' => $event->branding_logo_attachment_id])
                    : null,
            ])->all(),
        ]);
    }

    public function uploadAsset(
        Request $request,
        BrandingAccess $access,
        BrandingAssetService $assets,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'slot' => ['required', 'string', Rule::in(BrandingAssetLimits::SLOTS)],
            'organization_id' => ['required_without_all:department_id,team_id,event_id', 'nullable', 'uuid', Rule::exists(Organization::class, 'id')],
            'department_id' => ['required_without_all:organization_id,team_id,event_id', 'nullable', 'uuid', Rule::exists(Department::class, 'id')],
            'team_id' => ['required_without_all:organization_id,department_id,event_id', 'nullable', 'uuid', Rule::exists(Team::class, 'id')],
            'event_id' => ['required_without_all:organization_id,department_id,team_id', 'nullable', 'uuid', Rule::exists(Event::class, 'id')],
            'logo' => ['required', 'file', 'max:'.(int) (BrandingAssetLimits::MAX_BYTES / 1024)],
        ]);

        $owner = $this->resolveOwner($validated);

        if (! $this->permits($access, $user, $owner)) {
            return $this->denied('You do not have permission to change this branding logo.');
        }

        /*
         * The `file` validation rule above already refuses an upload PHP
         * itself failed, so this is belt-and-braces — but the failure mode it
         * prevents is worth the three lines: `getRealPath()` returns `false`
         * for a file with no temp path, and passing that to
         * `file_get_contents` resolves to the process working directory and
         * reports a permission error against `public/`, which names neither
         * the real file nor the real problem.
         */
        $path = $request->file('logo')->getRealPath();
        $bytes = is_string($path) && $path !== '' && is_file($path)
            ? @file_get_contents($path)
            : false;

        if ($bytes === false) {
            return response()->json([
                'message' => 'The uploaded logo could not be read. Please try the upload again.',
                'failures' => [],
            ], 422);
        }

        try {
            $attachment = $assets->put(
                $owner,
                (string) $validated['slot'],
                $bytes,
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (BrandingValidationException $exception) {
            return $this->rejected($exception);
        } catch (BrandingAuthorityException|EventAuthorityException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'attachment_id' => (string) $attachment->getKey(),
            'slot' => (string) $validated['slot'],
            'mime_type' => $attachment->mime_type,
            'byte_size' => (int) $attachment->byte_size,
            'url' => route('branding.asset', ['attachment' => $attachment->getKey()]),
        ], 201);
    }

    public function removeAsset(
        Request $request,
        BrandingAccess $access,
        BrandingAssetService $assets,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'slot' => ['required', 'string', Rule::in(BrandingAssetLimits::SLOTS)],
            'organization_id' => ['required_without_all:department_id,team_id,event_id', 'nullable', 'uuid', Rule::exists(Organization::class, 'id')],
            'department_id' => ['required_without_all:organization_id,team_id,event_id', 'nullable', 'uuid', Rule::exists(Department::class, 'id')],
            'team_id' => ['required_without_all:organization_id,department_id,event_id', 'nullable', 'uuid', Rule::exists(Team::class, 'id')],
            'event_id' => ['required_without_all:organization_id,department_id,team_id', 'nullable', 'uuid', Rule::exists(Event::class, 'id')],
        ]);

        $owner = $this->resolveOwner($validated);

        if (! $this->permits($access, $user, $owner)) {
            return $this->denied('You do not have permission to change this branding logo.');
        }

        try {
            $assets->remove($owner, (string) $validated['slot'], $user, AuditEvent::SOURCE_API);
        } catch (BrandingValidationException $exception) {
            return $this->rejected($exception);
        } catch (BrandingAuthorityException|EventAuthorityException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['slot' => (string) $validated['slot'], 'attachment_id' => null]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveOwner(array $validated): Organization|Department|Team|Event
    {
        $eventId = $validated['event_id'] ?? null;

        if ($eventId !== null) {
            return Event::query()->with('organization')->findOrFail((string) $eventId);
        }

        $teamId = $validated['team_id'] ?? null;

        if ($teamId !== null) {
            return Team::query()->with('department.organization')->findOrFail((string) $teamId);
        }

        $departmentId = $validated['department_id'] ?? null;

        if ($departmentId !== null) {
            return Department::query()->with('organization')->findOrFail((string) $departmentId);
        }

        return Organization::query()->findOrFail((string) $validated['organization_id']);
    }

    private function permits(BrandingAccess $access, User $user, Organization|Department|Team|Event $owner): bool
    {
        return match (true) {
            $owner instanceof Organization => $access->canManageOrganizationBranding($user, $owner),
            $owner instanceof Department => $access->canManageDepartmentBranding($user, $owner),
            $owner instanceof Team => $access->canManageTeamBranding($user, $owner),
            default => $access->canManageEventBranding($user, $owner),
        };
    }

    private function denied(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 403);
    }

    private function rejected(BrandingValidationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'failures' => $exception->failurePayload(),
        ], 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationPayload(Organization $organization, BrandingResolver $resolver): array
    {
        $profile = $resolver->forOrganization($organization);
        $governance = app(BrandingGovernance::class);

        return [
            'organization_id' => (string) $organization->getKey(),
            'display_name' => $profile->displayName,
            'is_branded' => $profile->isBranded,
            'palette' => $profile->palette->toArray(),
            'department_branding_enabled' => $profile->departmentOverridesEnabled,
            'lettermark' => $profile->lettermark(),
            'full_lockup_url' => $profile->fullLockupAttachmentId !== null
                ? route('branding.asset', ['attachment' => $profile->fullLockupAttachmentId])
                : null,
            'compact_mark_url' => $profile->compactMarkAttachmentId !== null
                ? route('branding.asset', ['attachment' => $profile->compactMarkAttachmentId])
                : null,
            'editable' => $governance->isEditable((string) $organization->getKey()),
            'branding_updated_at' => $organization->branding_updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function departmentPayload(Department $department): array
    {
        return [
            'department_id' => (string) $department->getKey(),
            'organization_id' => (string) $department->organization_id,
            'name' => $department->name,
            'accent' => $department->branding_accent_color,
            'surface' => $department->branding_surface_color,
            'logo_url' => $department->branding_logo_attachment_id !== null
                ? route('branding.asset', ['attachment' => $department->branding_logo_attachment_id])
                : null,
            'lettermark' => Lettermark::forName((string) $department->name),
            'branding_updated_at' => $department->branding_updated_at?->toIso8601String(),
        ];
    }
}
