<?php

namespace App\Services\Departments;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Product-path create/update/archive/restore for organization departments (M11.12).
 *
 * Soft archive preserves history (archived_at). Creating a department relies on
 * the Department model boot hook to attach the default team (TEAM-002).
 */
final class DepartmentAdminService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{name: string, code: string, description?: string|null}  $attributes
     */
    public function create(
        Organization $organization,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Department {
        $name = trim($attributes['name']);
        $code = trim($attributes['code']);
        $description = $this->normalizeDescription($attributes['description'] ?? null);

        if ($name === '' || $code === '') {
            throw new DepartmentAdminException('Department name and code are required.');
        }

        $this->assertCodeUnique($organization, $code);

        return DB::transaction(function () use ($organization, $name, $code, $description, $actor, $sourceContext): Department {
            $department = Department::query()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'code' => $code,
                'description' => $description,
            ]);

            $department->refresh();

            $this->audit->recordForEntity(
                entity: $department,
                action: 'department.created',
                actorUser: $actor,
                organizationId: (string) $organization->id,
                departmentId: (string) $department->id,
                after: $this->snapshot($department),
                sourceContext: $sourceContext,
            );

            return $department;
        });
    }

    /**
     * @param  array{name: string, code: string, description?: string|null}  $attributes
     */
    public function update(
        Department $department,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Department {
        $name = trim($attributes['name']);
        $code = trim($attributes['code']);
        $description = $this->normalizeDescription($attributes['description'] ?? null);

        if ($name === '' || $code === '') {
            throw new DepartmentAdminException('Department name and code are required.');
        }

        $this->assertCodeUnique($department->organization, $code, $department);

        return DB::transaction(function () use ($department, $name, $code, $description, $actor, $sourceContext): Department {
            $before = $this->snapshot($department);

            $department->forceFill([
                'name' => $name,
                'code' => $code,
                'description' => $description,
            ])->save();

            $department->refresh();
            $after = $this->snapshot($department);

            if ($before === $after) {
                return $department;
            }

            $this->audit->recordForEntity(
                entity: $department,
                action: 'department.updated',
                actorUser: $actor,
                organizationId: (string) $department->organization_id,
                departmentId: (string) $department->id,
                before: $before,
                after: $after,
                sourceContext: $sourceContext,
            );

            return $department;
        });
    }

    public function archive(
        Department $department,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Department {
        if ($department->isArchived()) {
            throw new DepartmentAdminException('Department is already archived.');
        }

        return DB::transaction(function () use ($department, $actor, $sourceContext): Department {
            $before = $this->snapshot($department);

            $department->forceFill(['archived_at' => now()])->save();
            $department->refresh();

            $this->audit->recordForEntity(
                entity: $department,
                action: 'department.archived',
                actorUser: $actor,
                organizationId: (string) $department->organization_id,
                departmentId: (string) $department->id,
                before: $before,
                after: $this->snapshot($department),
                sourceContext: $sourceContext,
            );

            return $department;
        });
    }

    public function restore(
        Department $department,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Department {
        if (! $department->isArchived()) {
            throw new DepartmentAdminException('Department is not archived.');
        }

        return DB::transaction(function () use ($department, $actor, $sourceContext): Department {
            $before = $this->snapshot($department);

            $department->forceFill(['archived_at' => null])->save();
            $department->refresh();

            $this->audit->recordForEntity(
                entity: $department,
                action: 'department.restored',
                actorUser: $actor,
                organizationId: (string) $department->organization_id,
                departmentId: (string) $department->id,
                before: $before,
                after: $this->snapshot($department),
                sourceContext: $sourceContext,
            );

            return $department;
        });
    }

    private function assertCodeUnique(
        Organization $organization,
        string $code,
        ?Department $ignore = null,
    ): void {
        $query = Department::query()
            ->where('organization_id', $organization->id)
            ->where('code', $code);

        if ($ignore !== null) {
            $query->whereKeyNot($ignore->id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => ['A department with this code already exists in the organization.'],
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
     *     organization_id: string,
     *     name: string,
     *     code: string,
     *     description: string|null,
     *     default_team_id: string|null,
     *     archived_at: string|null
     * }
     */
    private function snapshot(Department $department): array
    {
        return [
            'id' => (string) $department->id,
            'organization_id' => (string) $department->organization_id,
            'name' => $department->name,
            'code' => $department->code,
            'description' => $department->description,
            'default_team_id' => $department->default_team_id !== null
                ? (string) $department->default_team_id
                : null,
            'archived_at' => $department->archived_at?->toIso8601String(),
        ];
    }
}
