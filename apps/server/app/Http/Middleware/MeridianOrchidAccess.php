<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Orchid\Platform\Http\Middleware\Access;

/**
 * Keeps Orchid's normal permission gate while preserving an authenticated
 * logout path for users who lack the dashboard's main permission.
 */
final class MeridianOrchidAccess extends Access
{
    /**
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $permission = 'platform.index')
    {
        if ($request->user() !== null && $request->routeIs('platform.profile')) {
            return $next($request);
        }

        if (
            $request->user() !== null
            && ! $request->user()->hasAccess($permission)
            && $request->routeIs('platform.index', 'platform.main')
        ) {
            return redirect()->route('platform.profile');
        }

        return parent::handle($request, $next, $permission);
    }
}
