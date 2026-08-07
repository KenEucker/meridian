<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;
use App\Models\Event;
use Illuminate\Support\Carbon;

/**
 * One composed offline read set: the authorized records, what they are bounded
 * by, and the version a device negotiates against (technical spec 9.3, 9.5,
 * 11A.4, 11A.7).
 *
 * The version is a hash of the sections, the deferrals, and the module set — of
 * the *content*, and of nothing about the moment it was composed. That is what
 * makes a 304 possible at all: a version that moved because the clock did would
 * make every refresh a transfer, and the device asking for this is the one on a
 * weak connection.
 *
 * Composition is still done to answer a conditional request. What the 304 saves
 * is the payload rather than the work, which is the trade this shape accepts
 * knowingly: the set is composed from the caller's live grants on every request
 * (CLIENT-022), so there is no stored version to compare against without
 * composing, and a stored one could report a set the caller is no longer
 * entitled to.
 */
final class OfflineReadSet
{
    /**
     * @param  list<OfflineReadSetSection>  $sections
     * @param  list<DeferredOfflineReadSetSection>  $deferred
     * @param  list<ModuleKey>  $activeModules
     * @param  list<string>  $roleCodes
     */
    public function __construct(
        private readonly array $sections,
        private readonly array $deferred,
        private readonly array $activeModules,
        private readonly array $roleCodes,
        private readonly ?Event $contextEvent,
        private readonly ?string $nodeLockedEventId,
        private readonly Carbon $composedAt,
    ) {}

    /**
     * The content-addressed version of this set.
     */
    public function version(): string
    {
        return hash('sha256', json_encode($this->versionedContent(), JSON_THROW_ON_ERROR));
    }

    /**
     * The ETag a client sends back as `If-None-Match`.
     */
    public function etag(): string
    {
        return '"'.$this->version().'"';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version(),
            'sections' => $this->sectionRows(),
            'readiness' => $this->readiness(),
        ];
    }

    /**
     * The measured size of this set as it goes on the wire, in bytes.
     *
     * M18.48 chooses the client's storage layer from this number rather than
     * from a judgement about how big the section 9.3 set "feels", which is why
     * the set can report it rather than leaving it to be estimated.
     */
    public function payloadBytes(): int
    {
        return strlen(json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * Row counts per section, which is the shape a size measurement is read
     * against — 400 kB of shifts and 400 kB of documents are different answers
     * to whether the set can refresh whole.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->sections as $section) {
            $counts[$section->name] = count($section->rows);
        }

        return $counts;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function sectionRows(): array
    {
        $rows = [];

        foreach ($this->sections as $section) {
            $rows[$section->name] = $section->rows;
        }

        ksort($rows);

        return $rows;
    }

    /**
     * The readiness and sync state technical spec 9.3 asks the device to hold.
     *
     * `usable_until` is the 11A.4 staleness rule stated as a moment rather than
     * as a rule a client re-derives: the cached set is usable for the duration
     * of the event the node is locked to, and a client holding no event context
     * has nothing to bound staleness by and must refresh before it is trusted.
     *
     * @return array<string, mixed>
     */
    private function readiness(): array
    {
        return [
            'composed_at' => $this->composedAt->toIso8601String(),
            'context_event_id' => $this->contextEvent === null
                ? null
                : (string) $this->contextEvent->getKey(),
            'node_locked_event_id' => $this->nodeLockedEventId,
            'usable_until' => $this->contextEvent?->active_event_window_ends_at?->toIso8601String(),
            /*
             * The boundary this set was composed under, reported rather than
             * implied. A device that can say which roles and which modules
             * produced what it holds can also say why a section it held
             * yesterday is gone today (CLIENT-022, MOD-016).
             */
            'effective_role_codes' => $this->roleCodes,
            'active_modules' => array_map(
                static fn (ModuleKey $module): string => $module->value,
                $this->activeModules,
            ),
            'deferred_sections' => array_map(
                static fn (DeferredOfflineReadSetSection $section): array => $section->toArray(),
                $this->deferred,
            ),
            'aggregate_freshness' => $this->aggregateFreshness(),
            'counts' => $this->counts(),
        ];
    }

    /**
     * When the node computed the sections a device cannot recompute (SLB-019;
     * technical spec 9.3).
     *
     * Every other section is stored rows, and "how fresh" is answered for the
     * whole set by `composed_at`. A computed section needs saying separately
     * because a stale count reads exactly like a current one: a Planning Table
     * showing eleven checked in is making a claim about now, and a device with
     * no signal has to be able to say when that stopped being checked.
     *
     * It lives in readiness rather than on the rows, and that placement is the
     * design. Readiness is outside the version; a timestamp inside a section
     * would move the version every time the set was composed, and every refresh
     * would become a full transfer on precisely the connection this endpoint is
     * conditional for.
     *
     * @return list<array<string, mixed>>
     */
    private function aggregateFreshness(): array
    {
        $freshness = [];

        foreach ($this->sections as $section) {
            if (! $section->computed) {
                continue;
            }

            $freshness[] = [
                'section' => $section->name,
                'computed_at' => $this->composedAt->toIso8601String(),
                'usable_until' => $this->contextEvent?->active_event_window_ends_at?->toIso8601String(),
            ];
        }

        return $freshness;
    }

    /**
     * Everything the version is computed from.
     *
     * Deliberately not `composed_at`, and deliberately not `counts`, which is
     * derived from the sections and would only restate them.
     *
     * @return array<string, mixed>
     */
    private function versionedContent(): array
    {
        return [
            'sections' => $this->sectionRows(),
            'context_event_id' => $this->contextEvent === null
                ? null
                : (string) $this->contextEvent->getKey(),
            'effective_role_codes' => $this->roleCodes,
            'active_modules' => array_map(
                static fn (ModuleKey $module): string => $module->value,
                $this->activeModules,
            ),
            'deferred_sections' => array_map(
                static fn (DeferredOfflineReadSetSection $section): array => $section->toArray(),
                $this->deferred,
            ),
        ];
    }
}
