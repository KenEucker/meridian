<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\ClientAppController;
use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingSurface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The public marketing surface at the deployment root (M18.23; PUBLIC-001,
 * PUBLIC-002, PUBLIC-006).
 *
 * The root address answers two different people, and this controller decides
 * which one is asking.
 *
 * PUBLIC-001 describes the surface as being for "organizations that do not yet
 * use it", which is not the person who signed in this morning to run a shift.
 * So a browser holding a Meridian session gets the product it came for, and a
 * visitor holding nothing gets the page describing what Meridian is. Nobody is
 * redirected either way: a signed-in user who wants to read the marketing page
 * is a rare enough visitor that a link is a better answer than a rule, and a
 * signed-out one is never bounced away from the root of the deployment.
 *
 * Client applications are unaffected in both directions. They authenticate with
 * a bearer token rather than a session and load their own bundled shell, and
 * every client route other than the root is served by the fallback in
 * `routes/web.php` regardless of who is asking.
 *
 * Server-rendered rather than built into the Vue client, for the same reason
 * the login and application surfaces are: it has to render for somebody who has
 * downloaded nothing, holds no token, and may never sign in at all. It carries
 * Meridian's own identity and resolves no organization branding profile
 * (PUBLIC-001, BRAND-003) — there is no organization in this request to resolve
 * one from, and the page exists precisely for people who belong to none.
 *
 * The feature tour, the Northwood screenshots, and the offerings descriptions
 * (PUBLIC-007 through PUBLIC-009) are Milestone 20's, and are deliberately
 * absent here rather than stubbed.
 */
class MarketingSurfaceController extends Controller
{
    public function __construct(
        private readonly MarketingSurface $surface,
        private readonly ClientAppController $clientApp,
    ) {}

    /**
     * The deployment root.
     *
     * Falls through to the client application on a node that does not serve the
     * marketing surface (PUBLIC-006) and for a browser that already holds a
     * session, so an on-site laptop and a signed-in operator both reach the
     * product at the address they have always reached it at.
     */
    public function __invoke(Request $request): View|BinaryFileResponse|Response
    {
        if (! $this->surface->isServed() || $request->user() !== null) {
            return ($this->clientApp)();
        }

        return $this->landing($request);
    }

    /**
     * The marketing surface at its own address as well as at the root.
     *
     * Reachable by a signed-in user who followed a link to it, and the address
     * the interest form returns to, so a validation error does not have to be
     * re-rendered at a URL that answers differently depending on who is asking.
     */
    public function show(Request $request): View
    {
        $this->surface->abortUnlessServed();

        return $this->landing($request);
    }

    private function landing(Request $request): View
    {
        // Stamped when the form is handed out and read back when it returns
        // (PUBLIC-005). It lives in the session rather than in a hidden field
        // so a client cannot choose its own value, and the page is already
        // uncacheable because it carries a CSRF token.
        $request->session()->put('organization_interest_form_rendered_at', now()->toISOString());

        return view('marketing.landing');
    }
}
