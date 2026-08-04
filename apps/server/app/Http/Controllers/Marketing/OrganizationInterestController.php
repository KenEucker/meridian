<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingSurface;
use App\Services\Marketing\OrganizationInterestRateLimitException;
use App\Services\Marketing\OrganizationInterestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The organization interest form (M18.23; PUBLIC-002 through PUBLIC-006).
 *
 * The trap field is named for something a form filler would plausibly ask for
 * and a person will never see, and is described to assistive technology as a
 * field to leave alone. Naming it `email` or `url` would invite a browser's
 * autofill to complete it on a real visitor's behalf, which would turn the
 * protection into the blocked legitimate use PUBLIC-005 rules out; naming it
 * `honeypot` would announce it.
 */
class OrganizationInterestController extends Controller
{
    public const TRAP_FIELD = 'organization_reference_code';

    public function __construct(
        private readonly MarketingSurface $surface,
        private readonly OrganizationInterestService $interest,
    ) {}

    /**
     * Accept an organization's interest (PUBLIC-002, PUBLIC-003).
     *
     * Validation refuses what cannot be read rather than what looks
     * unpromising: a short description is a real inquiry, and a form that
     * argued with a prospective organization about how much it had written
     * would be a worse first impression than an empty console row.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->surface->abortUnlessServed();

        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $submission = $this->interest->submit(
                fields: $validated,
                clientKey: $request->ip(),
                trapFieldFilled: trim((string) $request->input(self::TRAP_FIELD, '')) !== '',
                formRenderedAt: $this->formRenderedAt($request),
            );
        } catch (OrganizationInterestRateLimitException $exception) {
            return back()
                ->withInput()
                ->withErrors(['contact_email' => $exception->getMessage()]);
        }

        if (! $submission->isAccepted()) {
            return back()
                ->withInput()
                ->withErrors([
                    'description' => __('That submission arrived faster than a form can be filled in. Check it over and send it again.'),
                ]);
        }

        // One render of the confirmation, flashed rather than routed to a page
        // of its own: there is nothing at the far end to come back to, and a
        // refresh should not re-post the form.
        return redirect()
            ->route('public.marketing.interest.received')
            ->with('organization_interest_submitted', $validated['organization_name']);
    }

    /**
     * The confirmation an organization sees after writing in (PUBLIC-002).
     *
     * It says what happens next and what does not: nothing was created, and
     * somebody will read it. PUBLIC-004 makes that literally true — an
     * organization exists when a God Mode operator creates one, and this page
     * should not imply otherwise.
     */
    public function received(Request $request): View|RedirectResponse
    {
        $this->surface->abortUnlessServed();

        if (! $request->session()->has('organization_interest_submitted')) {
            return redirect()->route('public.marketing.landing');
        }

        return view('marketing.interest-received', [
            'organizationName' => $request->session()->get('organization_interest_submitted'),
        ]);
    }

    /**
     * When this visitor was handed the form, where the session still remembers.
     */
    private function formRenderedAt(Request $request): ?Carbon
    {
        $renderedAt = $request->session()->get('organization_interest_form_rendered_at');

        if (! is_string($renderedAt) || $renderedAt === '') {
            return null;
        }

        try {
            return Carbon::parse($renderedAt);
        } catch (\Exception) {
            return null;
        }
    }
}
