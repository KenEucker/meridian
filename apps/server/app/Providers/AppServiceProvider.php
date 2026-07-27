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
    }
}
