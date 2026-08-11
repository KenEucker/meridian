<?php

use App\Http\Middleware\ReportCentralReach;
use App\Http\Middleware\ResolveOrganizationHost;
use App\Services\EventMode\EventModeNotReadyException;
use App\Services\Modules\ModuleInactiveException;
use App\Services\Node\EventAuthorityException;
use App\Services\Secrets\DefaultSecretsException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Framework defaults, with one addition. Meridian's own aliases went with
    // the `local.field` shared-token middleware (M16.11); the API authenticates
    // through the `sanctum` and `workstation` guards, which are configured in
    // config/auth.php rather than aliased here. The call itself is also what
    // installs the default `web` and `api` middleware groups on the HTTP kernel.
    //
    // `ReportCentralReach` tells every device what this node can reach beyond
    // itself (M18.52; technical spec 9.6). It authorizes nothing and reads no
    // request state. It is on the group rather than on routes because the tier
    // is as true of a refusal as of a success, and a device that only learned it
    // from certain endpoints would learn it at whatever rate it happened to call
    // them — and it is *prepended* so it is still holding the response when a
    // later middleware refuses one. A 401 from an expired token is exactly the
    // moment a device should not stop being told what its node can reach.
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [ReportCentralReach::class]);

        // Organization subdomain resolution (M19.8; ORG-022 through ORG-025;
        // technical spec 8.7). Global rather than grouped because the answer is
        // a property of the request host, not of any route: an unknown
        // subdomain of the platform domain is not found whatever the path asks
        // for — web, API, console, or health — and a known one binds the
        // organization host context everything downstream reads.
        $middleware->prepend(ResolveOrganizationHost::class);

        // The host-scope check runs before binding substitution: it refuses on
        // the raw slug, and it forgets the domain pattern's suffix parameter
        // before the framework hands route parameters to controllers
        // positionally and resolves scoped child bindings against the
        // parameter that precedes them.
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnforceOrganizationHostScope::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // A write refused by an event authority rule is a conflict with the
        // current state of the event, not a server fault and not an
        // authorization failure. The same actor may make the same change on the
        // authoritative node, or — for governance content frozen during the
        // active event window — once the window ends (technical spec 10.2,
        // 21.10). `authoritative_node_id` is null for a freeze, because no node
        // may make that edit while the window is open.
        $exceptions->render(function (EventAuthorityException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'reason_code' => $exception->reason,
                    'event_id' => $exception->eventId,
                    'authoritative_node_id' => $exception->authoritativeNodeId,
                ], 409);
            }

            return response($exception->getMessage(), 409);
        });

        // A request to capability the organization does not run (M19.12;
        // MOD-012, MOD-013; technical spec 15A.4; data/API 5.9).
        //
        // 404 rather than 403, and identical for every caller: an inactive
        // module is absent from the product rather than withheld from a person,
        // and the gate answers before any permission is consulted. The reason
        // names the module because module state is an organization's own
        // configuration and not a secret from its members — it is what lets the
        // client say "your organization does not use Scheduling" instead of
        // showing a permission denial for something nobody can reach.
        $exceptions->render(function (ModuleInactiveException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json($exception->payload(), 404);
            }

            return response($exception->getMessage(), 404);
        });

        // A node refusing to boot on default secrets (technical spec 26.2). It
        // is thrown from provider boot, before routing, so every request gets
        // the same answer including `/up` — a node that will not serve must not
        // report itself healthy to the container that is waiting on it.
        //
        // 503 rather than 500: the deployment is not broken, it is unfinished,
        // and the same node serves normally the moment its environment carries
        // real secrets. The message names variables and remedies and no values,
        // which is what makes it safe to render with APP_DEBUG off — the
        // alternative is an operator staring at a blank 500 on an event morning.
        $exceptions->render(function (DefaultSecretsException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'reason_code' => 'default_secrets',
                    'secrets' => $exception->readiness->failedNames(),
                ], 503);
            }

            return response($exception->getMessage(), 503);
        });

        // A node in event mode refusing to serve because a fail-closed check
        // fails (technical spec 8.2, 8.6, 26.2): HTTPS validation, or the
        // offline read set. Thrown from boot enforcement before routing, so
        // `/up` gets the same answer — a node that will not serve must not
        // report itself healthy. 503 for the same reason as the secrets
        // refusal: the node is not broken, it is misconfigured, and it serves
        // the moment the finding is resolved. Reasons name checks and never
        // values, so the message is safe with APP_DEBUG off.
        $exceptions->render(function (EventModeNotReadyException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'reason_code' => 'event_mode_not_ready',
                    'checks' => array_map(
                        static fn ($check): string => $check->key,
                        $exception->readiness->failures(),
                    ),
                ], 503);
            }

            return response($exception->getMessage(), 503);
        });
    })->create();
