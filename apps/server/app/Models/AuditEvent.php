<?php

namespace App\Models;

use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;
use RuntimeException;

/**
 * Immutable system audit event (data/API specification section 14.1, technical
 * spec section 23). Audit rows are append-only: once written they cannot be
 * updated or deleted, preserving operational history (requirements 2.4).
 *
 * `AsSource` and `Filterable` are for the God Mode audit trail (M18.34), which
 * is a read screen over these rows. Neither is a write path: the model refuses
 * updates and deletes below regardless of who is looking at it.
 */
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use AsSource;
    use Filterable;
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Audit events only record a creation timestamp.
     */
    public const UPDATED_AT = null;

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_WEB = 'web';

    public const SOURCE_ORCHID = 'orchid';

    public const SOURCE_API = 'api';

    public const SOURCE_SYNC = 'sync';

    public const SOURCE_CONSOLE = 'console';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'event_id',
        'department_id',
        'actor_user_id',
        'actor_device_id',
        'actor_node_id',
        'action',
        'entity_type',
        'entity_id',
        'before_json',
        'after_json',
        'reason',
        'source_context',
        'signature_metadata_json',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'action' => Like::class,
        'entity_type' => Like::class,
        'entity_id' => Where::class,
        'source_context' => Where::class,
        'actor_user_id' => Where::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'action',
        'entity_type',
        'source_context',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'signature_metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Whether the one path allowed to remove rows is running.
     *
     * @see withArchival()
     */
    private static bool $archiving = false;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Audit events are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            // Updating stays forbidden unconditionally. Removal has exactly one
            // legitimate caller, and it is not "the operator changed their
            // mind": AuditArchivalService writes the rows to a signed archive
            // and records the archival before anything is removed.
            if (! self::$archiving) {
                throw new RuntimeException('Audit events are immutable and cannot be deleted.');
            }
        });
    }

    /**
     * Run a callback with removal permitted (M18.34; data/API 14.1).
     *
     * Deliberately a scoped escape rather than a flag anyone can set, and
     * deliberately narrow: the guard is what makes the table trustworthy, and a
     * retention limit is the only reason Meridian has to lift it. The `finally`
     * matters more than it looks — a throw inside archival that left the flag
     * raised would leave the whole process able to delete audit history.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withArchival(callable $callback): mixed
    {
        $previous = self::$archiving;
        self::$archiving = true;

        try {
            return $callback();
        } finally {
            self::$archiving = $previous;
        }
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'actor_device_id');
    }

    public function actorNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'actor_node_id');
    }

    /**
     * Scope audit events to a specific entity type and identifier.
     *
     * @param  Builder<AuditEvent>  $query
     * @return Builder<AuditEvent>
     */
    public function scopeForEntity(Builder $query, string $entityType, int|string $entityId): Builder
    {
        return $query
            ->where('entity_type', $entityType)
            ->where('entity_id', (string) $entityId);
    }

    /**
     * Who acted, in the plainest terms this row supports (requirements 2.4).
     *
     * On the model rather than on each surface, because the product audit
     * review, the God Mode trail, and the God Mode entry screen all have to
     * answer it and three copies of one fallback ladder is three chances to
     * word "nobody" differently. A row with no user is a scheduled job — the
     * lifecycle evaluator (ORG-019), the automatic no-show — or an exchange a
     * device or node made on its own; saying which beats a blank that reads
     * like the record lost somebody's name.
     */
    public function describeActor(): string
    {
        $this->loadMissing('actorUser');

        if ($this->actorUser !== null) {
            return (string) $this->actorUser->name;
        }

        if ($this->actor_node_id !== null) {
            return __('A node');
        }

        if ($this->actor_device_id !== null) {
            return __('A device');
        }

        return __('A scheduled job');
    }

    /**
     * A readable name for what this entry is about.
     *
     * `entity_type` holds a morph class, which is a fully-qualified class name
     * for everything the application has not aliased. Nobody reading their own
     * organization's history should have to know that
     * `App\Models\StaffOrganizationStatus` is a staff status, and a support
     * operator scanning the trail should not be reading namespaces.
     */
    public function describeEntityType(): string
    {
        $type = (string) $this->entity_type;
        $base = str_contains($type, '\\') ? Str::afterLast($type, '\\') : $type;
        $spaced = preg_replace('/(?<!^)[A-Z]/', ' $0', $base) ?? $base;

        return ucfirst(strtolower(str_replace('_', ' ', $spaced)));
    }

    /**
     * The names of the fields this entry recorded a change to, without their
     * values.
     *
     * The union of both snapshots rather than only the keys whose values
     * differ: a creation has no before at all, and a path that snapshotted a
     * field it did not change still says the field was part of what it wrote.
     *
     * Shared with the God Mode screens so that "which fields moved" means the
     * same thing on both sides of the boundary — the product surface serves
     * this list *instead of* the values, and God Mode serves it alongside them.
     *
     * @return list<string>
     */
    public function changedFieldNames(): array
    {
        $before = is_array($this->before_json) ? $this->before_json : [];
        $after = is_array($this->after_json) ? $this->after_json : [];

        $fields = array_values(array_unique([
            ...array_keys($before),
            ...array_keys($after),
        ]));

        sort($fields);

        return array_map(static fn ($field): string => (string) $field, $fields);
    }

    /**
     * The organization / department / team narrowing the God Mode list screens
     * share (M18.34).
     *
     * The first two are columns the audit row already carries, which is why an
     * audit entry has always recorded the scope it happened in (data/API 14.1).
     *
     * @param  Builder<AuditEvent>  $query
     * @return Builder<AuditEvent>
     */
    public function scopeInOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @return Builder<AuditEvent>
     */
    public function scopeInDepartment(Builder $query, string $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Audit rows about one team.
     *
     * Unlike the two above, there is no `team_id` on an audit row and there
     * should not be: a team is not a scope a change happens *in* the way an
     * organization or a department is — it is a thing changes happen *to*. So
     * this narrows by subject rather than by scope: the team's own row, plus the
     * rows of the records that belong to it.
     *
     * The map is explicit for the same reason
     * {@see \App\Services\Node\EventScopedWriteGuard} keeps an explicit parent
     * map rather than guessing: a record reaches a team through a named column,
     * and a rule that inferred the relationship would quietly include or exclude
     * whatever a future model happened to call its foreign key. A model added to
     * the domain and not added here is absent from this filter rather than
     * wrongly attributed, and `ConsoleAuditTest` asserts the map covers every
     * team-owned model that exists today.
     *
     * @param  Builder<AuditEvent>  $query
     * @return Builder<AuditEvent>
     */
    public function scopeInTeam(Builder $query, string $teamId): Builder
    {
        return $query->where(function (Builder $scoped) use ($teamId): void {
            $scoped->where(fn (Builder $own) => $own
                ->where('entity_type', (new Team)->getMorphClass())
                ->where('entity_id', $teamId));

            foreach (self::teamOwnedEntities() as $modelClass => $foreignKey) {
                /** @var Model $model */
                $model = new $modelClass;

                $scoped->orWhere(fn (Builder $owned) => $owned
                    ->where('entity_type', $model->getMorphClass())
                    ->whereIn('entity_id', function ($subQuery) use ($model, $foreignKey, $teamId): void {
                        $subQuery
                            ->select('id')
                            ->from($model->getTable())
                            ->where($foreignKey, $teamId);
                    }));
            }
        });
    }

    /**
     * Records that belong to a team, and the column each reaches it through.
     *
     * @return array<class-string<Model>, string>
     */
    public static function teamOwnedEntities(): array
    {
        return [
            TeamGrant::class => 'team_id',
            TeamMembership::class => 'team_id',
            TeamDesignation::class => 'team_id',
            Training::class => 'team_id',
            // A shift has exactly one team, and names it `eligible_team_id`.
            Shift::class => 'eligible_team_id',
        ];
    }
}
