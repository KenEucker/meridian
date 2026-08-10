<?php

namespace App\Providers;

use App\Http\Middleware\MeridianOrchidAccess;
use App\Models\ApiToken;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\DeviceTrust;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\FieldReport;
use App\Models\OrchidAttachment;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\SharedWorkstation;
use App\Models\User;
use App\Policies\DeviceTrustPolicy;
use App\Policies\FieldReportPolicy;
use App\Services\Auth\ApiTokenAuthentication;
use App\Services\Auth\SharedWorkstationSessionKey;
use App\Services\Auth\SharedWorkstationSessionService;
use App\Services\Diagnostics\Checks;
use App\Services\Diagnostics\DiagnosticRunner;
use App\Services\Node\EventScopedWriteGuard;
use App\Services\Node\GovernanceWriteGuard;
use App\Services\Node\NodeOperationApplierRegistry;
use App\Services\Notifications\NotificationOperationApplier;
use App\Services\SystemConfig\ApplySystemConfigOverrides;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Orchid\Attachment\Models\Attachment as OrchidPlatformAttachment;
use Orchid\Platform\Dashboard;
use Orchid\Platform\Http\Middleware\Access as OrchidAccess;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OrchidAccess::class, MeridianOrchidAccess::class);

        // Appliers are registered against one shared registry so a node
        // operation received later resolves the appliers registered earlier
        // (technical spec 10.1).
        $this->app->singleton(NodeOperationApplierRegistry::class, function ($app): NodeOperationApplierRegistry {
            $registry = new NodeOperationApplierRegistry;

            // Notifications an on-site node generated during the event window,
            // which only central sends (M18.21; NOTIFY-008).
            $registry->register($app->make(NotificationOperationApplier::class));

            return $registry;
        });

        // One guard instance holds the enforcement state, so the receive path
        // standing it down while it applies an operation stands down the same
        // guard the rest of the request writes through (technical spec 10.2).
        $this->app->singleton(EventScopedWriteGuard::class);
        $this->app->singleton(GovernanceWriteGuard::class);

        // One applier instance per boot: diagnostics reads the same load
        // state the boot pass produced (technical spec 22A.6).
        $this->app->singleton(ApplySystemConfigOverrides::class);

        // The diagnostics runner and its registered checks (technical spec
        // 22A.8). Registration order is display order.
        $this->app->singleton(DiagnosticRunner::class, function ($app): DiagnosticRunner {
            $runner = new DiagnosticRunner;

            foreach ([
                Checks\ApplicationCheck::class,
                Checks\SecurityCheck::class,
                Checks\SystemConfigOverridesCheck::class,
                Checks\DatabaseCheck::class,
                Checks\CacheCheck::class,
                Checks\QueueCheck::class,
                Checks\SchedulerHeartbeatCheck::class,
                Checks\StorageCheck::class,
                Checks\WiringCheck::class,
                Checks\OfflineReadSetCheck::class,
                Checks\NodeSyncCheck::class,
                Checks\NodeIdentityCheck::class,
                Checks\IntegrationsCheck::class,
            ] as $check) {
                $runner->register($app->make($check));
            }

            return $runner;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Dashboard::useModel(OrchidPlatformAttachment::class, OrchidAttachment::class);

        Relation::morphMap([
            DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY => PolicyDocument::class,
            DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_PROCEDURE => ProcedureDocument::class,
            Attachment::MORPH_FIELD_REPORT => FieldReport::class,
            Attachment::MORPH_ORGANIZATION => Organization::class,
            Attachment::MORPH_DEPARTMENT => Department::class,
        ]);

        Gate::policy(DeviceTrust::class, DeviceTrustPolicy::class);
        Gate::policy(FieldReport::class, FieldReportPolicy::class);

        // Meridian's own token model carries the device binding and revocation
        // stamp that Sanctum's does not (AUTH-021, AUTH-022; data/API 12.5).
        // Registering it here rather than only at issuance means the guard
        // resolves the same model on an incoming request.
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        // Revocation is evaluated at request time, so a revoked token stops
        // authenticating on its next request without the client cooperating
        // (AUTH-023, technical spec 11.4).
        Sanctum::authenticateAccessTokensUsing(
            fn (mixed $accessToken, bool $isValid): bool => $this->app
                ->make(ApiTokenAuthentication::class)
                ->accepts($accessToken, $isValid)
        );

        // Meridian Kiosk presents the session a login code established, in its
        // own header, and resolves to the active user (AUTH-030; technical spec
        // 13.3). Registered as a guard driver rather than as middleware so a
        // route can accept it alongside `sanctum` and `$request->user()` answers
        // the same way either way — the permission checks behind a route do not
        // need to know which kind of credential got the caller there.
        //
        // Resolution slides the inactivity window, so the five-minute timeout is
        // enforced by the node on every request rather than by a countdown in a
        // renderer that a Kiosk in a stranger's hands could be persuaded to skip.
        Auth::viaRequest('workstation', function (Request $request): ?User {
            $key = trim((string) $request->header(SharedWorkstationSessionKey::HEADER));

            return $key === ''
                ? null
                : $this->app->make(SharedWorkstationSessionService::class)->resolveUser($key);
        });

        // During an active event window the on-site primary node is
        // authoritative for event-scoped records, so every local write path on a
        // node that does not hold authority is refused rather than each call
        // site remembering to ask (technical spec 10.2).
        $this->app->make(EventScopedWriteGuard::class)->register();

        // Policy/procedure and fragment edits are blocked for the duration of
        // the active event window on every node, so the same fail-closed
        // boundary covers governance content (technical spec 10.2, 21.10).
        $this->app->make(GovernanceWriteGuard::class)->register();

        $this->registerKioskRateLimiters();

        // Valid node-local database overrides are applied over the (possibly
        // cached) file configuration, so application code keeps reading
        // config() and the precedence stays database, then environment/.env,
        // then Laravel default (technical spec 22A.6). Fails soft: an
        // unreadable override table leaves the node on environment
        // configuration and surfaces through diagnostics (SYS-022).
        $this->app->make(ApplySystemConfigOverrides::class)->apply();
    }

    /**
     * Named limiters for the Kiosk's sign-in request routes (M18.67; AUTH-037).
     *
     * These need buckets of their own, and the reason is a property of Laravel's
     * default rather than of these routes. `throttle:n,1` resolves an
     * unauthenticated caller's signature to `sha1(domain|ip)` — the *route is
     * not part of the key* — so every unauthenticated route on a node shares one
     * counter per client address, and the route with the lowest limit is the
     * first to refuse.
     *
     * A locked Kiosk polls for its grant every few seconds, which is a great
     * many requests from one address by design. Left on the default those polls
     * drained the shared bucket and the node then refused *login* — the one
     * thing a workstation must always be able to attempt — with a rate limit
     * nobody had reached. A person entering their first code was told "Too Many
     * Attempts".
     *
     * Keyed per workstation, so a busy Kiosk bounds only itself: it cannot
     * exhaust another machine's budget, and it cannot touch the login, magic
     * link, or node-pairing routes at all. The domain limits in
     * {@see SharedWorkstationSignInRequestThrottle} are unchanged and still the
     * ones AUTH-037 names; these bound how hard one machine may ask before any
     * state is read.
     */
    private function registerKioskRateLimiters(): void
    {
        $perWorkstation = static function (Request $request, int $perMinute): Limit {
            $workstation = $request->route('sharedWorkstation');

            $key = $workstation instanceof SharedWorkstation
                ? (string) $workstation->getKey()
                : (string) ($request->route('sharedWorkstation') ?? $request->ip());

            return Limit::perMinute($perMinute)->by('workstation:'.$key);
        };

        // Opening. Bounded tightly: a Kiosk opens one request per expiry, and a
        // person retrying a refused presentation adds a few.
        RateLimiter::for(
            'kiosk-sign-in-request-open',
            static fn (Request $request): Limit => $perWorkstation($request, 30),
        );

        // Collecting. The poll a waiting Kiosk makes every few seconds, which is
        // why it is the loosest of the three and why it must not share.
        RateLimiter::for(
            'kiosk-sign-in-request-collect',
            static fn (Request $request): Limit => $perWorkstation($request, 180),
        );

        // Re-authentication runs behind the workstation guard, so the caller is
        // a resolved user and the default signature would be that user's — which
        // the same polling would drain for every other request they make.
        RateLimiter::for(
            'kiosk-reauthentication-request',
            static fn (Request $request): Limit => Limit::perMinute(180)
                ->by('workstation-session:'.((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))),
        );

        // Entering a login code. Its own bucket for the opposite reason to the
        // polls: this is the request a workstation must always be able to make,
        // and on the shared signature anything else noisy from the same address
        // could refuse somebody's first attempt. Keyed by the workstation the
        // code is being entered at, which is also the scope AUTH-029's domain
        // limit counts in, so the two bound the same thing.
        RateLimiter::for(
            'kiosk-workstation-session',
            static fn (Request $request): Limit => Limit::perMinute(20)
                ->by('workstation:'.((string) ($request->input('shared_workstation_id') ?? $request->ip()))),
        );
    }
}
