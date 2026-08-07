<?php

declare(strict_types=1);

namespace App\Http\Controllers\Offline;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Offline\OfflineReadSetComposer;
use App\Services\Session\SessionContextException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /api/offline-read-set` — everything the calling device may hold offline
 * (CLIENT-021, CLIENT-022, MOD-016; technical spec 9.3, 9.5, 11A.7; data/API
 * 7.1, 7.3; ADR-0003).
 *
 * One authenticated read composes the section 9.3 set for the caller. It
 * replaces PowerSync's server-to-device replication with a set the server
 * builds through the same resolver every other API read answers from, which is
 * the single change ADR-0003 turns on: one authorization model, in PHP,
 * exercised by the same tests as everything else.
 *
 * There is no parameter naming whose set to return, in the same way there is
 * none on `GET /api/me`. `event_id` selects which of the caller's own events
 * roles and staleness are resolved at; it narrows the answer and never widens
 * it, and is refused with the same reason codes session resolution uses.
 *
 * **Conditional by design.** The response carries an `ETag` over the set's
 * content, and a caller sending it back as `If-None-Match` on an unchanged set
 * gets a 304 with no body. The device asking for this is the one on a weak
 * connection at an event, so the refresh that finds nothing new has to cost
 * approximately nothing on the wire. The set is still composed to answer the
 * conditional request — there is no stored version to compare against, because
 * storing one would mean serving a set from before a grant was withdrawn.
 *
 * The read writes nothing and audits nothing. What a device holds is derived
 * from what its user may read, and reading it is not an event.
 */
final class OfflineReadSetController extends Controller
{
    public function show(Request $request, OfflineReadSetComposer $composer): JsonResponse|Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'event_id' => ['sometimes', 'uuid'],
        ]);

        try {
            $set = $composer->compose($user, $validated['event_id'] ?? null);
        } catch (SessionContextException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason_code' => $exception->reason,
                'node_locked_event_id' => $exception->lockedEventId,
            ], $exception->status);
        }

        $etag = $set->etag();

        if ($this->isUnchanged($request->header('If-None-Match'), $etag)) {
            return response()->noContent(Response::HTTP_NOT_MODIFIED)
                ->withHeaders($this->cacheHeaders($etag));
        }

        return response()
            ->json($set->toArray())
            ->withHeaders($this->cacheHeaders($etag));
    }

    /**
     * Whether the caller already holds this exact set.
     *
     * `If-None-Match` is a comma-separated list and its entries may be weakly
     * tagged, so both are handled rather than assuming the header contains
     * exactly what was last issued. `*` matches anything, which for a
     * conditional GET means "whatever you have".
     */
    private function isUnchanged(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*') {
                return true;
            }

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Revalidate every time, and never store this anywhere but the device it
     * was issued to.
     *
     * `no-cache` rather than `no-store`: the point of the ETag is that a client
     * *may* keep the set and ask whether it is still current. What must not
     * happen is a shared cache holding one user's authorized records and
     * answering another user with them.
     *
     * @return array<string, string>
     */
    private function cacheHeaders(string $etag): array
    {
        return [
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache',
        ];
    }
}
