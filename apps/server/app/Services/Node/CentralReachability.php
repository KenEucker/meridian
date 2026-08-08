<?php

declare(strict_types=1);

namespace App\Services\Node;

use App\Models\Node;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether this node can reach central, as this node has observed it (M18.52;
 * technical spec 9.6, 10.2; UI implementation contract 11.13, 16.1).
 *
 * A device knows one thing about connectivity on its own: whether the node it is
 * pointed at answers. It cannot know the second thing UI contract 16.1 has always
 * asked it to say — whether that node's own sync to central is working — because
 * the device never talks to central. Only the node can answer that, so the node
 * answers it, on every API response, through {@see self::HEADER}.
 *
 * **Observed, not probed.** Nothing here opens a connection to find out. Node
 * sync runs every minute (routes/console.php), and one exchange with central is
 * the observation: the peer answered, or the request never completed. Recording
 * what the sync loop already found costs nothing and is the same discipline the
 * client's own `nodeReachability` follows — a node that is not syncing learns
 * nothing, and says so rather than assuming either answer.
 *
 * **Any answer counts as reachable, a refusal included.** A central node that
 * refused an exchange is a central node that is *there*; what `unreachable`
 * means is that nothing was at the other end of the request. That is the
 * distinction the person reading "Central unreachable" needs — sync is not
 * moving because the link is gone — and a refused exchange is a different
 * problem, reported by the `sync.node` diagnostic with the reason attached.
 *
 * The four states divide by what is actually known:
 *
 *   `not_applicable` this node does not sync with a central node — it is central
 *                    itself, or a development node. The node the device is
 *                    pointed at *is* the expected sync target, so there is no
 *                    second tier and the device reports plain `online`.
 *   `reachable`      the last exchange reached central.
 *   `unreachable`    the last exchange did not.
 *   `unknown`        this node pairs with central and has no recent observation:
 *                    it has just started, its scheduler is not running, or it has
 *                    not finished pairing. The device may say its local node is
 *                    reachable and must not say central is.
 */
final class CentralReachability
{
    /** The response header every API response carries this on. */
    public const HEADER = 'Meridian-Central-Reach';

    public const REACHABLE = 'reachable';

    public const UNREACHABLE = 'unreachable';

    public const UNKNOWN = 'unknown';

    public const NOT_APPLICABLE = 'not_applicable';

    /** Where the sync loop's last observation is kept. */
    public const CACHE_KEY = 'meridian.node.central-reach';

    /**
     * Where the "does this node sync with central at all" answer is kept.
     *
     * Cached because it is read on every API request and changes about as often
     * as a node is set up or paired. The TTL is what bounds how long a freshly
     * paired node keeps reporting the answer it gave before pairing.
     */
    private const TIER_CACHE_KEY = 'meridian.node.central-reach.tier';

    private const TIER_TTL_SECONDS = 60;

    /**
     * How long an observation stands for.
     *
     * Node sync runs every minute, so five minutes is five missed runs — the
     * same window the scheduler heartbeat check uses, and for the same reason: a
     * node whose scheduler stopped is a node that has stopped finding out, and
     * the last thing it found out is no longer a claim it may make.
     */
    private const STALE_AFTER_MINUTES = 5;

    public function __construct(
        private readonly NodeSetupService $nodes,
        private readonly NodePairingState $pairing,
    ) {}

    /** The exchange reached central. Any answer counts, a refusal included. */
    public function recordReached(?CarbonImmutable $at = null): void
    {
        $this->record(self::REACHABLE, $at);
    }

    /** The exchange never completed, so nothing is known to be there. */
    public function recordUnreachable(?CarbonImmutable $at = null): void
    {
        $this->record(self::UNREACHABLE, $at);
    }

    /**
     * What this node may say about central right now.
     *
     * Never throws. This is read from a middleware on every API response, and a
     * connectivity report that could fail a request would be worse than no
     * report at all; a node that cannot answer reports `unknown`, which is true
     * of it.
     */
    public function state(): string
    {
        try {
            if (! $this->syncsWithCentral()) {
                return self::NOT_APPLICABLE;
            }

            return $this->observation()['state'] ?? self::UNKNOWN;
        } catch (Throwable) {
            return self::UNKNOWN;
        }
    }

    /**
     * The state and when it was observed, for diagnostics and for tests.
     *
     * @return array{state: string, observed_at: ?string}
     */
    public function describe(): array
    {
        $state = $this->state();

        return [
            'state' => $state,
            'observed_at' => in_array($state, [self::REACHABLE, self::UNREACHABLE], true)
                ? ($this->observation()['at'] ?? null)
                : null,
        ];
    }

    /** Forget what was observed, and re-read whether this node pairs at all. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::TIER_CACHE_KEY);
    }

    private function record(string $state, ?CarbonImmutable $at): void
    {
        $observedAt = $at ?? CarbonImmutable::now();

        try {
            Cache::put(
                self::CACHE_KEY,
                ['state' => $state, 'at' => $observedAt->toIso8601String()],
                $observedAt->addMinutes(self::STALE_AFTER_MINUTES * 2),
            );
        } catch (Throwable) {
            // A node with no usable cache store reports `unknown` rather than
            // failing a sync run over its own bookkeeping.
        }
    }

    /**
     * The last observation, or an empty array once it is too old to stand for
     * anything.
     *
     * @return array{state?: string, at?: string}
     */
    private function observation(): array
    {
        $record = Cache::get(self::CACHE_KEY);

        if (! is_array($record) || ! is_string($record['state'] ?? null) || ! is_string($record['at'] ?? null)) {
            return [];
        }

        $observedAt = CarbonImmutable::parse($record['at']);

        if ($observedAt->diffInMinutes(CarbonImmutable::now()) > self::STALE_AFTER_MINUTES) {
            return [];
        }

        return ['state' => $record['state'], 'at' => $observedAt->toIso8601String()];
    }

    /**
     * Whether there is a central node beyond this one at all.
     *
     * An unpaired node and a node whose pairing needs rechecking both count: they
     * are meant to reach central and are not, which is a thing to be `unknown`
     * about rather than a role with no central to reach. Only central itself, a
     * development node, and an install with no node configured have no second
     * tier.
     */
    private function syncsWithCentral(): bool
    {
        $status = Cache::remember(
            self::TIER_CACHE_KEY,
            self::TIER_TTL_SECONDS,
            function (): string {
                $node = $this->nodes->activeNode();

                return $node instanceof Node
                    ? $this->pairing->status($node)
                    : NodePairingState::STATUS_NOT_CONFIGURED;
            },
        );

        return ! in_array($status, [
            NodePairingState::STATUS_NOT_CONFIGURED,
            NodePairingState::STATUS_NOT_APPLICABLE,
        ], true);
    }
}
