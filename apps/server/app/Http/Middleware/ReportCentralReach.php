<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Node\CentralReachability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Tell every device what this node can reach (M18.52; technical spec 9.6; UI
 * implementation contract 11.13, 16.1).
 *
 * A header rather than a field in some payload, because the question is asked of
 * every request and answered by none of them. A device learns whether its node
 * answers from the traffic it was already making — that is how
 * `nodeReachability` works on the client — and this puts the second tier on
 * exactly the same traffic, at the same moment, so the two signals cannot
 * disagree with each other or go stale at different rates. A field on one
 * endpoint would only be as fresh as the last call to that endpoint, and a
 * dedicated poll would be the client probing for something no request needed.
 *
 * On every API response, refusals included. A 401 is a node that answered, and
 * what it can reach is as true of that response as of a 200.
 *
 * The header is exposed through CORS (config/cors.php) because the client's dev
 * server is a different origin from the node; in a deployment the node serves
 * the client and the question does not arise.
 */
final class ReportCentralReach
{
    public function __construct(private readonly CentralReachability $reach) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        try {
            $response->headers->set(CentralReachability::HEADER, $this->reach->state());
        } catch (Throwable) {
            // Reporting connectivity must never be the reason a request fails.
            // A device that receives no header treats the tier as unreported and
            // says nothing about central, which is the same silence it keeps
            // before the first answer arrives.
        }

        return $response;
    }
}
