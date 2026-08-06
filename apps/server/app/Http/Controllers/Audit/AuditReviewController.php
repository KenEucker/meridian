<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditReviewAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Audit review as a product surface (M18.29; requirements 2.4; UI contract
 * 12.6 `organizer.audit`).
 *
 * Requirements 2.4 asks for two things of an audit record: that changes are
 * attributed to the person who made them, and that important history is
 * preserved. This surface answers the first. Every row says who acted, what
 * they acted on, when, from where, why where a reason was required, and which
 * fields moved.
 *
 * **It does not serve the before and after values.** An audit payload is a
 * verbatim copy of whatever the writing path snapshotted, so the set of fields
 * an organizer would read here is the union of every field every audited path
 * happens to record — including a staff member's phone number, which
 * REPORT-002 keeps out of the roster an organizer exports. Naming the fields
 * that changed answers "who changed what, when, and why" without the surface
 * quietly becoming an unscoped read of everything else. Repair work that needs
 * the values is God Mode's, and M18.34 builds that screen.
 *
 * The IMS exclusion lives in {@see AuditReviewAccess}, not here: ORG-015 is a
 * rule about what organizing reaches, and a filter a controller applied would
 * be a rule one endpoint kept.
 */
final class AuditReviewController extends Controller
{
    /** Enough to read a shift's worth of history without paging forever. */
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    /** How many distinct actions and entity types the filter lists offer. */
    private const FILTER_OPTION_LIMIT = 200;

    /**
     * How long the filter option lists are held.
     *
     * Short enough that an organization recording a brand-new action sees it in
     * the dropdown within a minute, long enough that paging through a long
     * record does not re-run two `DISTINCT` scans per page.
     */
    private const FILTER_OPTION_TTL_SECONDS = 60;

    public function index(
        Request $request,
        Organization $organization,
        AuditReviewAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! $access->canReviewAudit($user, $organization)) {
            return response()->json([
                'message' => 'Only organizers may review this organization\'s audit record.',
            ], 403);
        }

        $validated = $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:255'],
            'entity_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'event_id' => ['sometimes', 'nullable', 'uuid'],
            'department_id' => ['sometimes', 'nullable', 'uuid'],
            'actor_user_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);

        $scoped = fn (): Builder => $access->scopeReviewable(
            AuditEvent::query(),
            $organization,
        );

        $page = $this->filtered($scoped(), $validated)
            ->with(['actorUser', 'event', 'department'])
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json([
            'organization_id' => (string) $organization->getKey(),
            'entries' => collect($page->items())
                ->map(fn (AuditEvent $entry): array => $this->row($entry))
                ->values()
                ->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            /*
             * Filter values are read from the rows this caller may see rather
             * than from a hardcoded list, so a filter can never offer an action
             * that only exists in a part of the record they are not shown.
             *
             * Cached, because this is the expensive half of the request and the
             * cheap half of the answer: a `DISTINCT` over an organization's
             * whole history ran on every page turn, while the set of actions an
             * organization has ever recorded changes a few times a year. Keyed
             * by organization and held briefly, so a newly-recorded action
             * appears within the minute rather than instantly — which is the
             * right trade for a control that exists to narrow a list somebody
             * is already looking at.
             */
            'options' => Cache::remember(
                self::filterOptionsCacheKey($organization),
                self::FILTER_OPTION_TTL_SECONDS,
                fn (): array => [
                    'actions' => $this->distinct($scoped(), 'action'),
                    'entity_types' => $this->distinct($scoped(), 'entity_type'),
                ],
            ),
        ]);
    }

    /**
     * The cache key the filter options are held under, so the write path can
     * drop them when an organization records an action it has not used before.
     */
    public static function filterOptionsCacheKey(Organization $organization): string
    {
        return 'audit.filter-options.'.$organization->getKey();
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditEvent>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        foreach (['action', 'entity_type', 'event_id', 'department_id', 'actor_user_id'] as $column) {
            $value = $filters[$column] ?? null;

            if ($value !== null && $value !== '') {
                $query->where($column, (string) $value);
            }
        }

        if (($filters['from'] ?? null) !== null && $filters['from'] !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse((string) $filters['from']));
        }

        if (($filters['to'] ?? null) !== null && $filters['to'] !== '') {
            $query->where('created_at', '<=', CarbonImmutable::parse((string) $filters['to']));
        }

        return $query;
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @return list<string>
     */
    private function distinct(Builder $query, string $column): array
    {
        return $query
            ->select($column)
            ->distinct()
            ->orderBy($column)
            ->limit(self::FILTER_OPTION_LIMIT)
            ->pluck($column)
            ->map(fn ($value): string => (string) $value)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AuditEvent $entry): array
    {
        return [
            'id' => (string) $entry->getKey(),
            'action' => (string) $entry->action,
            'entity_type' => (string) $entry->entity_type,
            'entity_label' => $entry->describeEntityType(),
            'entity_id' => (string) $entry->entity_id,
            /*
             * Attribution, which is what requirements 2.4 asks of every change.
             * A row with no actor is a scheduled job — the lifecycle evaluator
             * (ORG-019), the automatic no-show — and says so rather than
             * showing a blank that reads like missing data.
             */
            'actor_name' => $entry->actorUser?->name,
            'actor_label' => $entry->describeActor(),
            'actor_user_id' => $entry->actor_user_id !== null ? (string) $entry->actor_user_id : null,
            'actor_device_id' => $entry->actor_device_id !== null ? (string) $entry->actor_device_id : null,
            'actor_node_id' => $entry->actor_node_id !== null ? (string) $entry->actor_node_id : null,
            'event_id' => $entry->event_id !== null ? (string) $entry->event_id : null,
            'event_name' => $entry->event?->name,
            'department_id' => $entry->department_id !== null ? (string) $entry->department_id : null,
            'department_name' => $entry->department?->name,
            'reason' => $entry->reason,
            'source_context' => (string) $entry->source_context,
            'recorded_at' => $entry->created_at?->toIso8601String(),
            /*
             * The names of the fields that moved, without their values. See the
             * class docblock for why the values stay in God Mode. The list
             * itself comes from the model, so "which fields moved" means the
             * same thing here and on the God Mode entry screen that serves it
             * alongside the values (M18.34).
             */
            'changed_fields' => $entry->changedFieldNames(),
        ];
    }
}
