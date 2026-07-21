<?php

namespace App\Services\Teams;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Product-path create/update/archive/restore for department teams (M11.13).
 *
 * Soft archive preserves history (archived_at). Default teams may be renamed
 * but cannot be archived (TEAM-002, TEAM-003, TEAM-005).
 */
final class TeamAdminService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{name: string, code: string, description?: string|null}  $attributes
     */
    public function create(
        Department $department,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Team {
        if ($department->isArchived()) {
            throw new TeamAdminException('Cannot create teams in an archived department.');
        }

        $name = trim($attributes['name']);
        $code = trim($attributes['code']);
        $description = $this->normalizeDescription($attributes['description'] ?? null);

        if ($name === '' || $code === '') {
            throw new TeamAdminException('Team name and code are required.');
        }

        $this->assertCodeUnique($department, $code);

        return DB::transaction(function () use ($department, $name, $code, $description, $actor, $sourceContext): Team {
            $team = Team::query()->create([
                'department_id' => $department->id,
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'is_default' => false,
            ]);

            $team->refresh();

            $this->audit->recordForEntity(
                entity: $team,
                action: 'team.created',
                actorUser: $actor,
                organizationId: (string) $department->organization_id,
                departmentId: (string) $department->id,
                after: $this->snapshot($team),
                sourceContext: $sourceContext,
            );

            return $team;
        });
    }

    /**
     * @param  array{name: string, code: string, description?: string|null}  $attributes
     */
    public function update(
        Team $team,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Team {
        $name = trim($attributes['name']);
        $code = trim($attributes['code']);
        $description = $this->normalizeDescription($attributes['description'] ?? null);

        if ($name === '' || $code === '') {
            throw new TeamAdminException('Team name and code are required.');
        }

        $department = $team->department;
        $this->assertCodeUnique($department, $code, $team);

        return DB::transaction(function () use ($team, $department, $name, $code, $description, $actor, $sourceContext): Team {
            $before = $this->snapshot($team);

            $team->forceFill([
                'name' => $name,
                'code' => $code,
                'description' => $description,
            ])->save();

            $team->refresh();
            $after = $this->snapshot($team);

            if ($before === $after) {
                return $team;
            }

            $this->audit->recordForEntity(
                entity: $team,
                action: 'team.updated',
                actorUser: $actor,
                organizationId: (string) $department->organization_id,
                departmentId: (string) $department->id,
                before: $before,
                after: $after,
                sourceContext: $sourceContext,
            );

            return $team;
        });
    }

    public function archive(
        Team $team,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Team {
        if ($team->is_default) {
            throw new TeamAdminException('Default teams cannot be archived.');
        }

        if ($team->isArchived()) {
            throw new TeamAdminException('Team is already archived.');
        }

        $department = $team->department;

        return DB::transaction(function () use ($team, $department, $actor, $sourceContext): Team {
            $before = $this->snapshot($team);

            $team->forceFill(['archived_at' => now()])->save();
            $team->refresh();

            $this->audit->recordForEntity(
                entity: $team,
                action: 'team.archived',
                actorUser: $actor,
                organizationId: (string) $department->organization_id,
                departmentId: (string) $department->id,
                before: $before,
                after: $this->snapshot($team),
                sourceContext: $sourceContext,
            );

            return $team;
        });
    }

    public function restore(
        Team $team,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Team {
        if (! $team->isArchived()) {
            throw new TeamAdminException('Team is not archived.');
        }

        $department = $team->department;

        return DB::transaction(function () use ($team, $department, $actor, $sourceContext): Team {
            $before = $this->snapshot($team);

            $team->forceFill(['archived_at' => null])->save();
            $team->refresh();

            $this->audit->recordForEntity(
                entity: $team,
                action: 'team.restored',
                actorUser: $actor,
                organizationId: (string) $department->organization_id,
                departmentId: (string) $department->id,
                before: $before,
                after: $this->snapshot($team),
                sourceContext: $sourceContext,
            );

            return $team;
        });
    }

    private function assertCodeUnique(
        Department $department,
        string $code,
        ?Team $ignore = null,
    ): void {
        $query = Team::query()
            ->where('department_id', $department->id)
            ->where('code', $code);

        if ($ignore !== null) {
            $query->whereKeyNot($ignore->id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => ['A team with this code already exists in the department.'],
            ]);
        }
    }

    private function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $trimmed = trim($description);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{
     *     id: string,
     *     department_id: string,
     *     name: string,
     *     code: string,
     *     description: string|null,
     *     is_default: bool,
     *     archived_at: string|null
     * }
     */
    private function snapshot(Team $team): array
    {
        return [
            'id' => (string) $team->id,
            'department_id' => (string) $team->department_id,
            'name' => $team->name,
            'code' => $team->code,
            'description' => $team->description,
            'is_default' => (bool) $team->is_default,
            'archived_at' => $team->archived_at?->toIso8601String(),
        ];
    }
}
