<?php

declare(strict_types=1);

namespace App\Http\Controllers\Application;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Services\Application\ApplicantPortalRateLimitException;
use App\Services\Application\ApplicantPortalService;
use App\Services\Application\ApplicantPortalThrottle;
use App\Services\Application\DuplicateApplicationException;
use App\Services\Application\EventApplicationService;
use App\Services\Application\EventNotOpenForApplicationsException;
use App\Services\Branding\BrandingProfile;
use App\Services\Branding\Lettermark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The public participation surface's reads and its one write (M18.21A;
 * APP-016, APP-017, APP-018).
 *
 * Unauthenticated on purpose, and the only unauthenticated write in the API.
 * An application is an offer made by somebody who has no account yet — that is
 * the whole point of it — so a credential cannot be the gate, and the gate is
 * instead what the endpoint will *say*: this controller publishes an
 * organization's identity, its open events, and nothing else.
 *
 * What is deliberately absent from the read is the interesting part.
 * Departments appear only where APP-011 already puts them — as the eligible
 * interest list for one named event — and staff, teams, documents, shifts,
 * counts of applications, and the existence of any particular person do not
 * appear at all. An organization page that reported "42 applications" or named
 * its departments would be an intelligence surface wearing a recruitment form's
 * clothes.
 *
 * Nothing here discloses whether an address has applied before. A duplicate is
 * refused in the same words for an address that already applied and an address
 * that is simply wrong about the event, and a Do Not Staff match is
 * indistinguishable from an ordinary submission (STAT-006, NOTIFY-002): the
 * response is the same, and no notification is sent.
 */
class PublicParticipationController extends Controller
{
    public function __construct(private readonly EventApplicationService $applications) {}

    /**
     * The organization participation page (APP-016).
     *
     * Carries the branding profile so the page renders as the organization
     * before a session exists, exactly as the branding read already does for
     * signed-in chrome (BRAND-002).
     */
    public function organization(Organization $organization): JsonResponse
    {
        abort_if($organization->archived_at !== null, 404);

        $profile = BrandingProfile::forOrganization($organization);

        return response()->json([
            'organization' => [
                'id' => (string) $organization->id,
                'slug' => (string) $organization->slug,
                'name' => $profile->identityName(),
                'accepts_organization_applications' => $organization->acceptsOrganizationApplications(),
            ],
            'branding' => $this->brandingPayload($profile, $organization),
            'events' => $this->openEvents($organization),
        ]);
    }

    /**
     * One event's application form (APP-016), with the APP-011 department
     * interest options the form offers.
     */
    public function event(Organization $organization, Event $event): JsonResponse
    {
        abort_if($organization->archived_at !== null, 404);
        abort_if((string) $event->organization_id !== (string) $organization->id, 404);

        $profile = BrandingProfile::forOrganization($organization);

        return response()->json([
            'organization' => [
                'id' => (string) $organization->id,
                'slug' => (string) $organization->slug,
                'name' => $profile->identityName(),
            ],
            'branding' => $this->brandingPayload($profile, $organization),
            'event' => [
                'id' => (string) $event->id,
                'slug' => (string) $event->slug,
                'name' => (string) $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'timezone' => $event->timezone,
                // An archived event still resolves, so somebody following an
                // old link is told the applications closed rather than meeting
                // a 404 that reads as a broken link.
                'accepting_applications' => ! $event->isArchived(),
            ],
            'department_interests' => $this->applications->eligibleDepartmentInterests($event)
                ->map(fn (Department $department): array => [
                    'id' => (string) $department->id,
                    'name' => (string) $department->name,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Submit an application, to the organization or to one of its events
     * (APP-001).
     *
     * Rate limited on the route. The response never varies with what Meridian
     * knows about the applicant: an accepted submission answers the same way
     * whether it was recorded as Submitted or auto-rejected for a Do Not Staff
     * match, because a different answer would be a disclosure (STAT-006).
     */
    public function submit(Request $request, Organization $organization): JsonResponse
    {
        abort_if($organization->archived_at !== null, 404);

        $validated = $request->validate([
            'event_slug' => ['nullable', 'string', 'max:255'],
            'applicant_legal_name' => ['required', 'string', 'max:255'],
            'applicant_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'department_interest_ids' => ['sometimes', 'array'],
            'department_interest_ids.*' => ['string', 'uuid', 'distinct'],
        ]);

        $eventSlug = trim((string) ($validated['event_slug'] ?? ''));

        try {
            if ($eventSlug === '') {
                $application = $this->applications->submitToOrganization(
                    organization: $organization,
                    applicantLegalName: $validated['applicant_legal_name'],
                    applicantEmail: $validated['applicant_email'],
                    applicant: $request->user(),
                );
            } else {
                $event = Event::query()
                    ->where('organization_id', $organization->id)
                    ->where('slug', $eventSlug)
                    ->first();

                if (! $event instanceof Event) {
                    throw ValidationException::withMessages([
                        'event_slug' => __('This event is not accepting applications.'),
                    ]);
                }

                $application = $this->applications->submit(
                    event: $event,
                    applicantLegalName: $validated['applicant_legal_name'],
                    applicantEmail: $validated['applicant_email'],
                    applicant: $request->user(),
                    departmentInterestIds: $validated['department_interest_ids'] ?? [],
                );
            }
        } catch (EventNotOpenForApplicationsException $exception) {
            throw ValidationException::withMessages([
                'event_slug' => $exception->getMessage(),
            ]);
        } catch (DuplicateApplicationException $exception) {
            throw ValidationException::withMessages([
                'applicant_email' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'submitted' => true,
            // The identifier is returned so the surface can say something
            // specific, and nothing else about the record is: its status in
            // particular stays unpublished, because that is where a Do Not
            // Staff auto-rejection would otherwise be visible.
            'application_id' => (string) $application->id,
            'scope' => $application->isOrganizationScoped() ? 'organization' : 'event',
        ], 201);
    }

    /**
     * Ask for an applicant portal link (M18.22; APP-012, APP-014, APP-015).
     *
     * The public participation surface's second write, and the only one that is
     * not an application. It belongs here because APP-012 puts the request on
     * the public application surface: somebody who applied last month and heard
     * nothing comes back to the page they applied on, not to a sign-in screen
     * for an account they do not have.
     *
     * Organization-independent on purpose. An address may hold applications to
     * several organizations, and a link scoped to the one whose page it was
     * asked from would show the applicant part of their own record.
     *
     * The response is fixed. It is the same object for an address with
     * applications, an address with none, and an address whose only application
     * was auto-rejected for Do Not Staff, because a response that varied would
     * make this endpoint the enumeration oracle the whole surface avoids being.
     * Rate limiting lives in {@see ApplicantPortalThrottle} rather than on the
     * route, so this and the server-rendered form share one set of counters
     * instead of each holding half a limit.
     */
    public function requestPortalLink(Request $request, ApplicantPortalService $portal): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        try {
            $portal->requestLink($validated['email'], $request->ip());
        } catch (ApplicantPortalRateLimitException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'retry_after_seconds' => $exception->availableInSeconds,
            ], 429);
        }

        return response()->json([
            'requested' => true,
        ], 202);
    }

    /**
     * The events an applicant may apply to right now.
     *
     * Non-archived, and ordered by when they start so the next one is first.
     * An event with no start date sorts last rather than being hidden — a
     * recruiting organization that has not fixed dates yet is a normal state.
     *
     * @return list<array<string, mixed>>
     */
    private function openEvents(Organization $organization): array
    {
        return Event::query()
            ->where('organization_id', $organization->id)
            ->whereNull('archived_at')
            ->orderByRaw('starts_at is null')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Event $event): array => [
                'slug' => (string) $event->slug,
                'name' => (string) $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'timezone' => $event->timezone,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function brandingPayload(BrandingProfile $profile, Organization $organization): array
    {
        return [
            'display_name' => $profile->identityName(),
            'lettermark' => Lettermark::forName($profile->identityName()),
            'palette' => $profile->hasCustomPalette ? $profile->palette->toArray() : null,
            'full_lockup_url' => $profile->fullLockupAttachmentId !== null
                ? route('branding.asset', ['attachment' => $profile->fullLockupAttachmentId])
                : null,
            'compact_mark_url' => $profile->compactMarkAttachmentId !== null
                ? route('branding.asset', ['attachment' => $profile->compactMarkAttachmentId])
                : null,
        ];
    }
}
