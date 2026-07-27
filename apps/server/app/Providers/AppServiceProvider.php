<?php

namespace App\Providers;

use App\Http\Middleware\MeridianOrchidAccess;
use App\Models\Attachment;
use App\Models\DeviceTrust;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\FieldReport;
use App\Models\OrchidAttachment;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Policies\DeviceTrustPolicy;
use App\Policies\FieldReportPolicy;
use App\Services\Node\EventScopedWriteGuard;
use App\Services\Node\GovernanceWriteGuard;
use App\Services\Node\NodeOperationApplierRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
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
        $this->app->singleton(NodeOperationApplierRegistry::class);

        // One guard instance holds the enforcement state, so the receive path
        // standing it down while it applies an operation stands down the same
        // guard the rest of the request writes through (technical spec 10.2).
        $this->app->singleton(EventScopedWriteGuard::class);
        $this->app->singleton(GovernanceWriteGuard::class);
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
        ]);

        Gate::policy(DeviceTrust::class, DeviceTrustPolicy::class);
        Gate::policy(FieldReport::class, FieldReportPolicy::class);

        // During an active event window the on-site primary node is
        // authoritative for event-scoped records, so every local write path on a
        // node that does not hold authority is refused rather than each call
        // site remembering to ask (technical spec 10.2).
        $this->app->make(EventScopedWriteGuard::class)->register();

        // Policy/procedure and fragment edits are blocked for the duration of
        // the active event window on every node, so the same fail-closed
        // boundary covers governance content (technical spec 10.2, 21.10).
        $this->app->make(GovernanceWriteGuard::class)->register();
    }
}
