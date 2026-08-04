<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Domain\Marketing\OrganizationInterestOutcome;
use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingSurface;
use App\Services\Marketing\OrganizationInterestFormToken;
use App\Services\Marketing\OrganizationInterestRateLimitException;
use App\Services\Marketing\OrganizationInterestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The organization interest form's server side (M18.23; PUBLIC-002 through
 * PUBLIC-006).
 *
 * The marketing surface itself is a client application view. What the node
 * owns is this pair of endpoints: whether the surface may be shown at all, and
 * what a submission is allowed to create.
 *
 * Unauthenticated, like the participation surface beside it, and for the same
 * reason: the person filling this in has no Meridian account and is writing in
 * to ask whether they should. What bounds it is not a credential but what it
 * will do — one row in one table that joins to nothing.
 *
 * The trap field is named for something a form filler would plausibly ask for
 * and a person will never see. Naming it `email` or `url` would invite a
 * browser's autofill to complete it on a real visitor's behalf, which would
 * turn the protection into the blocked legitimate use PUBLIC-005 rules out;
 * naming it `honeypot` would announce it.
 */
class OrganizationInterestController extends Controller
{
    public const TRAP_FIELD = 'organization_reference_code';

    public function __construct(
        private readonly MarketingSurface $surface,
        private readonly OrganizationInterestService $interest,
        private readonly OrganizationInterestFormToken $formTokens,
    ) {}

    /**
     * Whether this node serves the marketing surface, and the token a
     * submission from it has to carry (PUBLIC-005, PUBLIC-006).
     *
     * The client asks before it renders. On an on-site node or a node locked
     * to an event this is a 404, and the surface is not shown — there the page
     * is not there rather than withheld, and the client has somewhere honest to
     * send the visitor instead.
     */
    public function show(): JsonResponse
    {
        $this->surface->abortUnlessServed();

        return response()->json([
            'available' => true,
            'form_token' => $this->formTokens->issue(),
            'minimum_seconds_on_form' => $this->interest->minimumSecondsOnForm(),
        ]);
    }

    /**
     * Submit an organization's interest (PUBLIC-002, PUBLIC-003).
     *
     * Validation refuses what cannot be read rather than what looks
     * unpromising: a short description is a real inquiry, and a form that
     * argued with a prospective organization about how much it had written
     * would be a worse first impression than a thin console row.
     *
     * The response for a recorded submission and for one discarded by the
     * hidden field is the same object. The two refusals that are answered
     * honestly — a form token too young, and one missing or stale — are
     * recoverable by definition: the client still holds everything the visitor
     * typed, so asking costs them a button press rather than their message.
     */
    public function store(Request $request): JsonResponse
    {
        $this->surface->abortUnlessServed();

        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'form_token' => ['nullable', 'string', 'max:2048'],
        ]);

        try {
            $submission = $this->interest->submit(
                fields: $validated,
                clientKey: $request->ip(),
                formToken: $validated['form_token'] ?? null,
                trapFieldFilled: trim((string) $request->input(self::TRAP_FIELD, '')) !== '',
            );
        } catch (OrganizationInterestRateLimitException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'retry_after_seconds' => $exception->availableInSeconds,
            ], 429);
        }

        if ($submission->outcome === OrganizationInterestOutcome::TooFast) {
            throw ValidationException::withMessages([
                'description' => __('That arrived faster than a form can be filled in. Check it over and send it again.'),
            ]);
        }

        if ($submission->outcome === OrganizationInterestOutcome::StaleForm) {
            throw ValidationException::withMessages([
                'form_token' => __('This form has been open too long. Reload the page and send it again.'),
            ]);
        }

        return response()->json([
            'submitted' => true,
        ], 201);
    }
}
