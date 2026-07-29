<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Orchid\PlatformProvider;
use App\Providers\AppServiceProvider;
use App\Services\Audit\AuditService;
use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use App\Services\Node\EventScopedWriteGuard;
use App\Services\Node\GovernanceWriteGuard;
use App\Services\Node\NodeOperationApplierRegistry;
use App\Services\SystemConfig\ApplySystemConfigOverrides;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Providers, container bindings, and routes Meridian cannot run without
 * (SYS-033: wiring category). Only required core components are listed here —
 * optional modules must not fail this check by being absent (SYS-032).
 */
class WiringCheck implements DiagnosticCheck
{
    private const REQUIRED_PROVIDERS = [
        AppServiceProvider::class,
        PlatformProvider::class,
    ];

    private const REQUIRED_SINGLETONS = [
        NodeOperationApplierRegistry::class,
        EventScopedWriteGuard::class,
        GovernanceWriteGuard::class,
        ApplySystemConfigOverrides::class,
        AuditService::class,
    ];

    private const REQUIRED_ROUTES = [
        'api.health',
        'platform.main',
        'platform.node.config',
    ];

    public function __construct(private readonly Application $app) {}

    public function key(): string
    {
        return 'wiring.core';
    }

    public function label(): string
    {
        return 'Core providers, bindings, and routes';
    }

    public function category(): string
    {
        return DiagnosticCategory::WIRING;
    }

    public function required(): bool
    {
        return true;
    }

    public function run(): DiagnosticResult
    {
        $missing = [];
        $loaded = $this->app->getLoadedProviders();

        foreach (self::REQUIRED_PROVIDERS as $provider) {
            if (($loaded[$provider] ?? false) !== true) {
                $missing[] = "provider {$provider}";
            }
        }

        foreach (self::REQUIRED_SINGLETONS as $abstract) {
            try {
                $this->app->make($abstract);
            } catch (Throwable) {
                $missing[] = "binding {$abstract}";
            }
        }

        foreach (self::REQUIRED_ROUTES as $route) {
            if (! Route::has($route)) {
                $missing[] = "route {$route}";
            }
        }

        $details = [
            'providers_loaded' => count($loaded),
            'required_providers' => count(self::REQUIRED_PROVIDERS),
            'required_bindings' => count(self::REQUIRED_SINGLETONS),
            'required_routes' => count(self::REQUIRED_ROUTES),
        ];

        if ($missing !== []) {
            return DiagnosticResult::critical(
                'Required application wiring is missing: '.implode('; ', $missing).'.',
                $details,
                'This install is incomplete or the build is corrupted; redeploy the Meridian server build.',
            );
        }

        return DiagnosticResult::healthy('Required providers, bindings, and routes are present.', $details);
    }
}
