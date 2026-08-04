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
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
                Checks\PowerSyncCheck::class,
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

        // Valid node-local database overrides are applied over the (possibly
        // cached) file configuration, so application code keeps reading
        // config() and the precedence stays database, then environment/.env,
        // then Laravel default (technical spec 22A.6). Fails soft: an
        // unreadable override table leaves the node on environment
        // configuration and surfaces through diagnostics (SYS-022).
        $this->app->make(ApplySystemConfigOverrides::class)->apply();
    }
}
