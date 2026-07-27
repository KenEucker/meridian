<?php

use App\Services\Node\EventAuthorityException;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'local.field' => \App\Http\Middleware\AuthenticateLocalFieldApi::class,
        ]);
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
    })->create();
