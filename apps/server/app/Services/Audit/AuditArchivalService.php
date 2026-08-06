<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Enforce an organization's audit limits by archiving, never by deleting
 * (requirements 2.4; development process 7.5; data/API 14.1).
 *
 * Meridian's audit table is append-only and the model refuses removal. A limit
 * on how much history is kept is a real operational need on a node running many
 * organizations for many years, and it is also the one thing that could quietly
 * turn an audit trail into a record of only what nobody minded keeping. So the
 * limits are enforced by moving history out of the table rather than by
 * destroying it:
 *
 *  1. The oldest rows past the limit are written to a JSON Lines archive on the
 *     private disk, one object per row, with every column including both
 *     payloads.
 *  2. The archival is recorded as its own audit entry — `audit.archived`, which
 *     sits in {@see \App\Domain\Audit\AuditActionCatalog::REQUIRED} so no
 *     verbosity setting can hide it — naming the file, the row count, and the
 *     time range removed.
 *  3. Only then are the rows removed, inside {@see AuditEvent::withArchival()},
 *     which is the only path in the application permitted to remove them.
 *
 * The order is the point. A crash between steps leaves an archive with no
 * deletion, or an archive and a record with no deletion — never a deletion with
 * no archive.
 *
 * **Newest-first is never removed.** Limits trim the oldest, because the
 * question an audit trail answers most often is about something recent, and
 * because trimming by any other rule — least important, least read — would mean
 * somebody deciding which history is worth keeping.
 */
final class AuditArchivalService
{
    /** Where archives are written, on the private disk. */
    private const ARCHIVE_DIRECTORY = 'audit-archives';

    /**
     * How many rows are archived in one pass.
     *
     * A bound rather than a preference: an organization switching a limit on
     * for the first time may be over it by millions of rows, and one statement
     * holding all of them would be one transaction holding the table.
     */
    private const BATCH = 5_000;

    public function __construct(
        private readonly AuditUsage $usage,
        private readonly AuditService $audit,
    ) {}

    /**
     * Bring one organization within its configured limits.
     *
     * @return array{archived: int, file: string|null, reasons: list<string>}
     */
    public function enforce(Organization $organization, ?User $actor = null): array
    {
        if (! $organization->hasAuditLimits()) {
            return ['archived' => 0, 'file' => null, 'reasons' => []];
        }

        $doomed = $this->rowsPastTheLimit($organization);

        if ($doomed['ids'] === []) {
            return ['archived' => 0, 'file' => null, 'reasons' => []];
        }

        $rows = AuditEvent::query()
            ->whereIn('id', $doomed['ids'])
            ->orderBy('created_at')
            ->get();

        $file = $this->write($organization, $rows);

        // Recorded before the removal, so a failure between the two leaves a
        // record of an archive that still has its rows rather than rows that
        // vanished with no record.
        $this->audit->record(
            action: 'audit.archived',
            entityType: (new Organization)->getMorphClass(),
            entityId: (string) $organization->getKey(),
            actorUser: $actor,
            organizationId: (string) $organization->getKey(),
            after: [
                'archive_file' => $file,
                'rows' => $rows->count(),
                'oldest_at' => optional($rows->first())->created_at?->toIso8601String(),
                'newest_at' => optional($rows->last())->created_at?->toIso8601String(),
                'limits_exceeded' => $doomed['reasons'],
            ],
            reason: 'Audit history archived to stay within the configured limits.',
        );

        AuditEvent::withArchival(function () use ($doomed): void {
            foreach (array_chunk($doomed['ids'], 500) as $chunk) {
                DB::table('audit_events')->whereIn('id', $chunk)->delete();
            }
        });

        return [
            'archived' => $rows->count(),
            'file' => $file,
            'reasons' => $doomed['reasons'],
        ];
    }

    /**
     * The ids of the oldest rows that have to go, and why.
     *
     * Each configured limit contributes a set and the union is removed, so an
     * organization that is over on rows and over on age loses everything either
     * rule condemns rather than whichever rule was evaluated last.
     *
     * @return array{ids: list<string>, reasons: list<string>}
     */
    private function rowsPastTheLimit(Organization $organization): array
    {
        $ids = [];
        $reasons = [];

        if ($organization->audit_retention_days !== null) {
            $cutoff = CarbonImmutable::now()->subDays($organization->audit_retention_days);

            $aged = AuditEvent::query()
                ->where('organization_id', $organization->getKey())
                ->where('created_at', '<', $cutoff)
                ->orderBy('created_at')
                ->limit(self::BATCH)
                ->pluck('id')
                ->all();

            if ($aged !== []) {
                $ids = array_merge($ids, $aged);
                $reasons[] = 'retention_days';
            }
        }

        $usage = $this->usage->forOrganization($organization);

        if ($organization->audit_max_rows !== null && $usage['rows'] > $organization->audit_max_rows) {
            $excess = min($usage['rows'] - $organization->audit_max_rows, self::BATCH);

            $ids = array_merge($ids, $this->oldest($organization, $excess));
            $reasons[] = 'max_rows';
        }

        if ($organization->audit_max_bytes !== null && $usage['bytes'] > $organization->audit_max_bytes) {
            /*
             * Rows rather than bytes, because bytes are what the limit is
             * expressed in and rows are what can actually be removed. The
             * average row size is used to estimate how many rows the overage is
             * worth; the next pass measures again, so an estimate that came up
             * short simply archives again rather than leaving the organization
             * permanently over.
             */
            $averageBytes = $usage['rows'] > 0 ? max(1, (int) ($usage['bytes'] / $usage['rows'])) : 1;
            $excess = min(
                (int) ceil(($usage['bytes'] - $organization->audit_max_bytes) / $averageBytes),
                self::BATCH,
            );

            $ids = array_merge($ids, $this->oldest($organization, $excess));
            $reasons[] = 'max_bytes';
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @return list<string>
     */
    private function oldest(Organization $organization, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        return AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($count)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * Write the rows out as JSON Lines.
     *
     * One object per line rather than one document, so an archive can be read
     * back a row at a time by anything, including a person with `grep`, and so
     * appending is possible without rewriting. Every column is carried,
     * including both payloads — the archive is the history, and an archive that
     * dropped the values would make the removal a deletion after all.
     *
     * @param  \Illuminate\Support\Collection<int, AuditEvent>  $rows
     */
    private function write(Organization $organization, $rows): string
    {
        $path = sprintf(
            '%s/%s/%s.jsonl',
            self::ARCHIVE_DIRECTORY,
            $organization->getKey(),
            CarbonImmutable::now()->format('Y-m-d-His-u'),
        );

        $lines = $rows
            ->map(fn (AuditEvent $entry): string => (string) json_encode([
                'id' => (string) $entry->getKey(),
                'organization_id' => $entry->organization_id,
                'event_id' => $entry->event_id,
                'department_id' => $entry->department_id,
                'actor_user_id' => $entry->actor_user_id,
                'actor_device_id' => $entry->actor_device_id,
                'actor_node_id' => $entry->actor_node_id,
                'action' => $entry->action,
                'entity_type' => $entry->entity_type,
                'entity_id' => $entry->entity_id,
                'before_json' => $entry->before_json,
                'after_json' => $entry->after_json,
                'reason' => $entry->reason,
                'source_context' => $entry->source_context,
                'signature_metadata_json' => $entry->signature_metadata_json,
                'created_at' => $entry->created_at?->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->implode(PHP_EOL);

        Storage::disk('local')->put($path, $lines.PHP_EOL);

        return $path;
    }
}
