<?php

namespace App\Http\Controllers\Application;

use App\Http\Controllers\Controller;
use App\Models\EventApplication;
use App\Models\User;
use App\Services\Application\ApplicantPortalRateLimitException;
use App\Services\Application\ApplicantPortalService;
use App\Services\Application\ApplicationApplicantAccess;
use App\Services\Application\ApplicationWithdrawalException;
use App\Services\Application\EventApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The applicant portal (M18.22; APP-004, APP-012 through APP-015).
 *
 * Server-rendered and session-scoped, like the login surfaces beside it, for
 * the same reason they are: the person reading it holds no bearer token and no
 * account to attach one to. The signed link is the credential, it is spent
 * once on arrival, and what it leaves behind is a bounded portal session naming
 * one verified email address.
 *
 * The address never comes from the request after that. It is read from the
 * session on every action, so a portal opened for one applicant cannot be
 * pointed at another's application by editing a form, and an expired session is
 * a closed portal rather than a page that keeps rendering from whatever it was
 * last given.
 *
 * Every refusal here is the same refusal: an application that does not exist,
 * one belonging to a different address, and one auto-rejected for Do Not Staff
 * are all a 404. Telling them apart is the disclosure APP-014 exists to
 * prevent.
 */
class ApplicantPortalController extends Controller
{
    private const SESSION_EMAIL = 'applicant_portal_email';

    private const SESSION_EXPIRES_AT = 'applicant_portal_expires_at';

    public function __construct(
        private readonly ApplicantPortalService $portal,
        private readonly EventApplicationService $applications,
        private readonly ApplicationApplicantAccess $applicantAccess,
    ) {}

    /** The form an applicant asks for a link from (APP-012). */
    public function requestForm(Request $request): View
    {
        return view('application.portal-request', [
            'openPortalEmail' => $this->verifiedEmail($request),
        ]);
    }

    /**
     * Ask for a link.
     *
     * The answer is the same page with the same words for an address with
     * applications, an address with none, and an address whose only application
     * was auto-rejected (APP-014). A rate-limited request says something
     * different — but it says it about the requester rather than about the
     * address, so it discloses nothing either (APP-015).
     */
    public function requestLink(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        try {
            $this->portal->requestLink($validated['email'], $request->ip());
        } catch (ApplicantPortalRateLimitException $exception) {
            return back()
                ->withInput()
                ->withErrors(['email' => $exception->getMessage()]);
        }

        return redirect()
            ->route('applicant-portal.request.sent')
            ->with('applicant_portal_requested_email', $this->portal->normalizeEmail($validated['email']));
    }

    public function sent(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('applicant_portal_requested_email')) {
            return redirect()->route('applicant-portal.request');
        }

        return view('application.portal-request-sent', [
            'email' => $request->session()->get('applicant_portal_requested_email'),
        ]);
    }

    /**
     * The far end of the signed link (APP-012).
     *
     * `signed:relative` on the route has already proved both the address and the
     * expiry, so this opens the portal session and redirects to an address that
     * carries no credential — the signed URL stays out of the browser history
     * the applicant hands round, exactly as the magic-link landing does.
     */
    public function open(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_EMAIL, $this->portal->normalizeEmail($validated['email']));
        $request->session()->put(
            self::SESSION_EXPIRES_AT,
            now()->addMinutes($this->portal->sessionMinutes())->toISOString(),
        );

        return redirect()->route('applicant-portal.show');
    }

    /** Everything submitted under the verified address (APP-013). */
    public function show(Request $request): View|RedirectResponse
    {
        $email = $this->verifiedEmail($request);

        if ($email === null) {
            return redirect()
                ->route('applicant-portal.request')
                ->with('status', 'That link has expired. Ask for a new one and we will email it.');
        }

        $applications = $this->portal->applicationsFor($email);

        return view('application.portal', [
            'email' => $email,
            'applications' => $applications,
            'withdrawableIds' => $applications
                ->filter(fn (EventApplication $application): bool => $this->portal->canWithdraw($application, $email))
                ->map(fn (EventApplication $application): string => (string) $application->id)
                ->values()
                ->all(),
            'withdrawnApplicationId' => $request->session()->get('applicant_portal_withdrawn'),
        ]);
    }

    /** Withdraw one of the applicant's own applications (APP-004, APP-013). */
    public function withdraw(Request $request, string $application): RedirectResponse
    {
        $email = $this->verifiedEmail($request);

        if ($email === null) {
            return redirect()
                ->route('applicant-portal.request')
                ->with('status', 'That link has expired. Ask for a new one and we will email it.');
        }

        $record = $this->portal->visibleApplication($email, $application);

        abort_if($record === null, 404);
        abort_unless($this->portal->canWithdraw($record, $email), 404);

        try {
            $this->applications->withdraw(
                $record,
                $this->actorFor($request, $record),
                'Withdrawn by the applicant through the applicant portal.',
            );
        } catch (ApplicationWithdrawalException $exception) {
            return redirect()
                ->route('applicant-portal.show')
                ->withErrors(['withdraw' => $exception->getMessage()]);
        }

        return redirect()
            ->route('applicant-portal.show')
            ->with('applicant_portal_withdrawn', (string) $record->id);
    }

    /**
     * Close the portal.
     *
     * Present because the browser this opens in is often not the applicant's
     * own — a library machine, a friend's phone — and leaving is otherwise
     * something you can only do by waiting.
     */
    public function close(Request $request): RedirectResponse
    {
        $request->session()->forget([self::SESSION_EMAIL, self::SESSION_EXPIRES_AT]);
        $request->session()->regenerate();

        return redirect()
            ->route('applicant-portal.request')
            ->with('status', 'You have been signed out of your applications.');
    }

    /**
     * The user to record as the actor, where there is one.
     *
     * A portal session names an address rather than a user, and the browser it
     * runs in may hold somebody else's Meridian session — a shared machine, a
     * lead helping a new applicant. Recording whoever happened to be signed in
     * would name the wrong person in a permanent record, so the session's user
     * is credited only where it is demonstrably the applicant.
     */
    private function actorFor(Request $request, EventApplication $application): ?User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return $this->applicantAccess->userMatchesApplicantEmail($user, (string) $application->applicant_email)
            ? $user
            : null;
    }

    /**
     * The verified address this portal session was opened for, or null once it
     * has run out.
     *
     * An expired session is cleared as it is read rather than left to be found
     * again by the next request.
     */
    private function verifiedEmail(Request $request): ?string
    {
        $email = $request->session()->get(self::SESSION_EMAIL);
        $expiresAt = $request->session()->get(self::SESSION_EXPIRES_AT);

        if (! is_string($email) || $email === '' || ! is_string($expiresAt)) {
            return null;
        }

        if (Carbon::parse($expiresAt)->isPast()) {
            $request->session()->forget([self::SESSION_EMAIL, self::SESSION_EXPIRES_AT]);

            return null;
        }

        return $email;
    }
}
