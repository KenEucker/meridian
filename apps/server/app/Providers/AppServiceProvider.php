<?php

namespace App\Providers;

use App\Models\DeviceTrust;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\FieldReport;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Policies\DeviceTrustPolicy;
use App\Policies\FieldReportPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY => PolicyDocument::class,
            DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_PROCEDURE => ProcedureDocument::class,
        ]);

        Gate::policy(DeviceTrust::class, DeviceTrustPolicy::class);
        Gate::policy(FieldReport::class, FieldReportPolicy::class);
    }
}
