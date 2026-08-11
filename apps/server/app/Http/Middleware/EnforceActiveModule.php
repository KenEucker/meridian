<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Modules\ModuleKey;
use App\Services\Modules\ActiveModuleResolver;
use App\Services\Modules\ModuleInactiveException;
use App\Services\Modules\RequestOrganizationScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The module gate (MOD-012, MOD-013; technical spec 15A.4, 15A.5; data/API 5.9;
 * M19.12).
 *
 * Technical spec 15A.4's evaluation order, as a middleware:
 *
 *     resolve organization -> module gate -> permission check -> handler
 *
 * The gate sits on the route rather than in the controller, which is what lets
 * a route group declare its module once (data/API 5.9) and what makes the
 * route-coverage test possible: a module-owned endpoint that forgot to gate is
 * a missing middleware on a route, which a test can see, rather than a missing
 * `if` inside a method, which it cannot.
 *
 * It runs *after* authentication and *before* authorization. Meridian
 * authorizes inside controllers and services — `abort_unless`, an access
 * object, a policy — so every one of those decisions is downstream of this, and
 * MOD-013's "evaluated before permission checks" holds without the gate having
 * to know what any of them check. Authentication is the other way round on
 * purpose: an anonymous request is refused for having no credential before
 * anything asks what the credential's organization runs, which is the same
 * order every other endpoint answers in and discloses nothing.
 *
 * The refusal does not vary by caller. A permitted user, an unpermitted user,
 * and a God Mode operator all receive the same `404` naming the same module,
 * because a capability an organization does not run is absent rather than
 * withheld and there is nobody for whom it is present (data/API 6.8). God Mode
 * changes module state from the console; it does not pass through the gate.
 *
 * What is *not* gated matters as much. Session resolution, the offline read
 * set, the department operations reads, and the Event Horizon are core
 * endpoints that compose module-owned data, and data/API 5.9 is explicit that
 * they omit an inactive module's contribution rather than refusing (MOD-019).
 * They carry no gate here, and M19.18 is where their omission is built.
 */
class EnforceActiveModule
{
    public function __construct(
        private readonly ActiveModuleResolver $modules,
        private readonly RequestOrganizationScope $scope,
    ) {}

    /**
     * The middleware string for a module, for route declarations.
     *
     * A helper rather than a literal so a route names a catalogue case instead
     * of a string: a typo is a fatal error at boot rather than a route that
     * quietly gates nothing.
     */
    public static function for(ModuleKey $module): string
    {
        return self::class.':'.$module->value;
    }

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $key = ModuleKey::from($module);

        $named = $this->scope->named($request);

        if ($named !== []) {
            // An organization the request names decides it. More than one named
            // means the request reaches across them — a command naming both a
            // department and the event it belongs to, usually — and every one
            // of them has to run the module, because a request the node would
            // have to touch an inactive module to answer is a request it does
            // not answer.
            foreach ($named as $organizationId) {
                if (! $this->modules->isActive($organizationId, $key)) {
                    throw new ModuleInactiveException($key, $organizationId);
                }
            }

            return $next($request);
        }

        // The request names no organization: it is one of the module-owned
        // reads that answers about the caller across all of theirs. Absent for
        // them only if none of their organizations runs it — otherwise the read
        // proceeds and its own scoping is what keeps the inactive
        // organization's rows out of the answer.
        $membership = $this->scope->membership($request->user());

        if ($membership === []) {
            return $next($request);
        }

        foreach ($membership as $organizationId) {
            if ($this->modules->isActive($organizationId, $key)) {
                return $next($request);
            }
        }

        throw new ModuleInactiveException($key);
    }
}
