<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Models\Event;
use App\Models\Node;
use App\Services\Node\EventAuthority;
use App\Services\Node\NodeConfigResolver;
use App\Services\Node\NodeSetupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Refreshes the packaged changelog from the source repository (GOD-022 through
 * GOD-026; technical spec 22.7.2).
 *
 * Three rules shape this, and they all point the same way — the page must
 * render:
 *
 *   - Refresh is never required. Absent network, missing credential, or a
 *     failed call degrades to the packaged baseline and shows the time of the
 *     last successful refresh (GOD-023).
 *   - Only the central node refreshes. On-site, standalone, and development
 *     nodes render the baseline (GOD-024).
 *   - Refresh does not block rendering and does not run during the active event
 *     window (GOD-025). The work is deferred until after the response is sent,
 *     so a slow or hanging source repository costs the reader nothing.
 *
 * The credential is read through the existing configuration mechanism, is
 * read-only in scope, and is never returned, displayed, or logged (GOD-026).
 * Nothing in this class puts the token into a message, a cache entry, or an
 * exception.
 */
final class ChangelogRefresh
{
    /** This node refreshed successfully and the entries below are from it. */
    public const STATUS_REFRESHED = 'refreshed';

    /** A refresh was attempted or wanted and the baseline is being shown. */
    public const STATUS_DEGRADED = 'degraded';

    /** This node does not refresh at all. */
    public const STATUS_BASELINE = 'baseline';

    private const CACHE_ENTRIES = 'meridian.changelog.refresh.entries';

    private const CACHE_LAST_SUCCESS = 'meridian.changelog.refresh.last_success_at';

    private const CACHE_ATTEMPTED_AT = 'meridian.changelog.refresh.attempted_at';

    private const CACHE_REASON = 'meridian.changelog.refresh.reason';

    public function __construct(
        private readonly NodeSetupService $nodes,
        private readonly NodeConfigResolver $configResolver,
        private readonly EventAuthority $eventAuthority,
    ) {}

    /**
     * Entries merged in on top of the packaged baseline. Empty when this node
     * has never refreshed, which is the normal state on an on-site node.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        $cached = Cache::get(self::CACHE_ENTRIES);

        return is_array($cached) ? $cached : [];
    }

    public function lastSuccessAt(): ?string
    {
        $value = Cache::get(self::CACHE_LAST_SUCCESS);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{status: string, status_label: string, reason: ?string, last_success_at: ?string}
     */
    public function state(): array
    {
        $reason = $this->unavailableReason();

        if ($reason !== null) {
            return $this->describe(self::STATUS_BASELINE, $reason);
        }

        $cachedReason = Cache::get(self::CACHE_REASON);

        if (is_string($cachedReason) && $cachedReason !== '') {
            return $this->describe(self::STATUS_DEGRADED, $cachedReason);
        }

        return $this->entries() === []
            ? $this->describe(self::STATUS_DEGRADED, 'No refresh has completed yet on this node.')
            : $this->describe(self::STATUS_REFRESHED, null);
    }

    /**
     * Queue a refresh to run after the response has been sent, if this node may
     * refresh and the cached result is stale. The caller never waits.
     */
    public function refreshAfterResponse(): void
    {
        if ($this->unavailableReason() !== null || ! $this->isStale()) {
            return;
        }

        defer(fn () => $this->refresh());
    }

    /**
     * Perform the refresh. Returns true when newer entries were stored.
     *
     * Every failure path is swallowed into a reason string, because the only
     * consequence of a failed refresh is that the page shows the baseline.
     */
    public function refresh(): bool
    {
        $reason = $this->unavailableReason();

        if ($reason !== null) {
            return false;
        }

        Cache::put(self::CACHE_ATTEMPTED_AT, now()->toIso8601String(), $this->cacheTtl());

        try {
            $entries = $this->fetch();
        } catch (Throwable $exception) {
            // The exception message is not stored: a failed HTTP client
            // exception can carry the request, and the request carries the
            // credential (GOD-026).
            Cache::put(
                self::CACHE_REASON,
                'The source repository could not be reached.',
                $this->cacheTtl(),
            );

            return false;
        }

        if ($entries === null) {
            Cache::put(
                self::CACHE_REASON,
                'The source repository refused the request.',
                $this->cacheTtl(),
            );

            return false;
        }

        Cache::forget(self::CACHE_REASON);
        Cache::put(self::CACHE_ENTRIES, $entries, $this->cacheTtl());
        Cache::put(self::CACHE_LAST_SUCCESS, now()->toIso8601String(), $this->cacheTtl());

        return true;
    }

    /**
     * Why this node will not refresh, or null when it will. The reason is shown
     * to the operator so "the changelog looks old" has an answer.
     */
    public function unavailableReason(): ?string
    {
        if (! (bool) config('meridian.changelog.refresh.enabled', true)) {
            return 'Changelog refresh is disabled on this node.';
        }

        if ($this->effectiveNodeRole() !== Node::ROLE_CENTRAL) {
            return 'Only the central node refreshes the changelog. This node renders the packaged baseline.';
        }

        if ($this->credential() === null) {
            return 'No source-repository credential is configured, so the packaged baseline is shown.';
        }

        if ($this->activeEventWindowIsOpen()) {
            return 'An event is in its active window. Refresh is skipped until the window closes.';
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function fetch(): ?array
    {
        $repository = (string) config('meridian.changelog.refresh.repository', '');
        $apiUrl = rtrim((string) config('meridian.changelog.refresh.api_url', ''), '/');

        if ($repository === '' || $apiUrl === '') {
            return null;
        }

        $response = Http::withToken((string) $this->credential())
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->timeout((float) config('meridian.changelog.refresh.request_timeout_seconds', 5))
            ->get($apiUrl.'/repos/'.$repository.'/pulls', [
                'state' => 'closed',
                'base' => 'production',
                'sort' => 'updated',
                'direction' => 'desc',
                'per_page' => 100,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $entries = [];

        foreach ((array) $response->json() as $pull) {
            if (! is_array($pull) || ! is_string($pull['merged_at'] ?? null)) {
                continue;
            }

            $number = (int) ($pull['number'] ?? 0);

            if ($number <= 0) {
                continue;
            }

            $entries[] = [
                'number' => $number,
                'title' => (string) ($pull['title'] ?? ''),
                'body' => (string) ($pull['body'] ?? ''),
                'author' => (string) ($pull['user']['login'] ?? 'unknown'),
                'merged_at' => $pull['merged_at'],
                // The source repository does not know which Meridian version a
                // change shipped in, so a refreshed entry stays under the
                // version the packaged baseline already assigned it. Entries the
                // baseline has never seen are the ones merged since packaging,
                // which is the version now running.
                'version' => $this->versionFor($number),
            ];
        }

        return $entries;
    }

    private function versionFor(int $number): string
    {
        foreach (app(Changelog::class)->releases() as $release) {
            foreach ($release['entries'] as $entry) {
                if ((int) ($entry['number'] ?? 0) === $number) {
                    return (string) $release['version'];
                }
            }
        }

        return (string) config('meridian.version');
    }

    private function isStale(): bool
    {
        $attemptedAt = Cache::get(self::CACHE_ATTEMPTED_AT);

        if (! is_string($attemptedAt) || $attemptedAt === '') {
            return true;
        }

        return Carbon::parse($attemptedAt)
            ->addMinutes(max(1, (int) config('meridian.changelog.refresh.cache_minutes', 60)))
            ->isPast();
    }

    private function cacheTtl(): int
    {
        // Held well past the refresh interval so a node that loses its network
        // keeps showing the last successful refresh time rather than forgetting
        // that it ever refreshed (GOD-023).
        return max(1, (int) config('meridian.changelog.refresh.cache_minutes', 60)) * 60 * 24;
    }

    private function credential(): ?string
    {
        $token = config('meridian.changelog.refresh.token');

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    private function activeEventWindowIsOpen(): bool
    {
        return Event::query()
            ->whereNotNull('active_event_window_starts_at')
            ->whereNull('archived_at')
            ->get()
            ->contains(fn (Event $event): bool => $this->eventAuthority->isActive($event));
    }

    private function effectiveNodeRole(): string
    {
        $node = $this->nodes->activeNode();

        foreach ($this->configResolver->valuesFor($node) as $value) {
            if ($value['key'] === 'node_role') {
                $role = $value['value'];

                return is_string($role) && $role !== '' ? $role : Node::ROLE_DEVELOPMENT;
            }
        }

        return Node::ROLE_DEVELOPMENT;
    }

    /**
     * @return array{status: string, status_label: string, reason: ?string, last_success_at: ?string}
     */
    private function describe(string $status, ?string $reason): array
    {
        return [
            'status' => $status,
            'status_label' => match ($status) {
                self::STATUS_REFRESHED => 'Refreshed from the source repository',
                self::STATUS_DEGRADED => 'Showing the packaged baseline',
                default => 'Packaged baseline',
            },
            'reason' => $reason,
            'last_success_at' => $this->lastSuccessAt(),
        ];
    }
}
