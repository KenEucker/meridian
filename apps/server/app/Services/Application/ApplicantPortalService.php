<?php

namespace App\Services\Application;

use App\Mail\ApplicantPortalLinkMail;
use App\Models\AuditEvent;
use App\Models\EventApplication;
use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Applicant self-service (M18.22; APP-004, APP-012 through APP-015; AUTH-010).
 *
 * An applicant is the one person in Meridian who has no account. They filled in
 * a form, and until somebody approved it there was no way for them to see what
 * had become of it — the decision arrived by email or it did not arrive at all.
 * This is the path back to their own record, and it has to work for somebody
 * holding nothing: no session, no staff profile, no user row.
 *
 * What they hold instead is the email address they applied with, and control of
 * that address is what the link proves. It is the same signed-URL mechanism as
 * primary email verification (AUTH-010), pointed at a page rather than at a
 * session: following it signs nobody in and grants nothing beyond reading and
 * withdrawing applications made under that one address.
 *
 * Two silences are deliberate, and they are the same silence twice.
 *
 * A request for a link answers identically whether or not the address has ever
 * applied (APP-014), and a link is mailed only where there is something to
 * show. The alternative — mailing every address that asks — would answer the
 * question "has this person applied?" in the recipient's inbox rather than in
 * the response, which is the same disclosure taking one hop longer.
 *
 * A Do Not Staff auto-rejection is absent rather than labelled (STAT-006). An
 * application in that state is not in the portal, is not counted when deciding
 * whether to issue a link, and — where it is the only application an address
 * holds — leaves that address indistinguishable from one that never applied.
 * That is the point: the rejection is invisible to the applicant, and a portal
 * that showed it or that changed its behaviour around it would be the one place
 * Meridian told them.
 */
class ApplicantPortalService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ApplicantPortalThrottle $throttle,
    ) {}

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * Mail a portal link to an address that has applications to show.
     *
     * Returns nothing in every case. The caller cannot tell whether a link was
     * sent, because the caller is the applicant's browser and telling it would
     * be telling anybody who typed an address into the form.
     *
     * @param  string|null  $clientKey  the requesting client, normally its IP address
     *
     * @throws ApplicantPortalRateLimitException
     */
    public function requestLink(string $email, ?string $clientKey = null): void
    {
        $normalizedEmail = $this->normalizeEmail($email);

        if ($normalizedEmail === '' || ! filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // Before the lookup, so that whether a request is refused depends on how
        // many requests have been made and never on what Meridian knows about
        // the address.
        $this->throttle->guard($normalizedEmail, $clientKey);

        $applications = $this->applicationsFor($normalizedEmail);

        if ($applications->isEmpty()) {
            return;
        }

        $portalUrl = $this->createPortalUrl($normalizedEmail);

        if (config('mail.default') === 'log') {
            Log::info('Meridian applicant portal link (copy this URL): '.$portalUrl);
        }

        Mail::to($normalizedEmail)->send(new ApplicantPortalLinkMail(
            $portalUrl,
            $this->linkExpiresMinutes(),
        ));

        // APP-015. One row per issuance rather than one per application: the
        // thing that happened is that a link capable of reaching this address's
        // applications was put into the world. No organization is named,
        // because an address may hold applications to several and the link
        // covers all of them.
        $this->audit->record(
            action: 'applicant_portal.link_issued',
            entityType: 'applicant_portal_link',
            entityId: (string) Str::uuid(),
            after: [
                'applicant_email' => $normalizedEmail,
                'application_count' => $applications->count(),
                'expires_at' => now()->addMinutes($this->linkExpiresMinutes())->toISOString(),
            ],
            reason: 'Applicant portal link issued.',
            sourceContext: AuditEvent::SOURCE_WEB,
        );
    }

    /**
     * Every application this address may see, newest first (APP-013).
     *
     * Auto-rejected Do Not Staff applications are excluded here rather than in
     * the view, so no caller can reintroduce them by rendering the wrong list.
     *
     * @return Collection<int, EventApplication>
     */
    public function applicationsFor(string $normalizedEmail): Collection
    {
        return EventApplication::query()
            ->with(['event', 'organization'])
            ->whereRaw('LOWER(applicant_email) = ?', [$normalizedEmail])
            ->where('status', '!=', EventApplication::STATUS_AUTO_REJECTED_DNS)
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * One application this address may see, or null.
     *
     * Null covers an application that does not exist, one belonging to another
     * address, and one auto-rejected for Do Not Staff. The caller turns all
     * three into the same answer, because distinguishing them is exactly the
     * disclosure APP-014 refuses.
     */
    public function visibleApplication(string $normalizedEmail, string $applicationId): ?EventApplication
    {
        return $this->applicationsFor($normalizedEmail)
            ->firstWhere('id', $applicationId);
    }

    /**
     * Whether this application is still withdrawable by this applicant
     * (APP-004, APP-013).
     */
    public function canWithdraw(EventApplication $application, string $normalizedEmail): bool
    {
        return $application->isSubmitted()
            && $this->normalizeEmail((string) $application->applicant_email) === $normalizedEmail;
    }

    public function createPortalUrl(string $normalizedEmail): string
    {
        $relativeSignedUrl = URL::temporarySignedRoute(
            'applicant-portal.open',
            now()->addMinutes($this->linkExpiresMinutes()),
            ['email' => $normalizedEmail],
            absolute: false,
        );

        return URL::to($relativeSignedUrl);
    }

    public function linkExpiresMinutes(): int
    {
        return $this->positiveConfig('meridian.applicant_portal.link_expires_minutes', 60);
    }

    public function sessionMinutes(): int
    {
        return $this->positiveConfig('meridian.applicant_portal.session_minutes', 60);
    }

    /**
     * A missing, non-numeric, zero, or negative setting falls back to the
     * documented default. A zero lifetime would issue links that are expired on
     * arrival, which reads to the applicant as Meridian being broken.
     */
    private function positiveConfig(string $key, int $default): int
    {
        $configured = config($key, $default);

        if (! is_numeric($configured)) {
            return $default;
        }

        $value = (int) $configured;

        return $value > 0 ? $value : $default;
    }
}
