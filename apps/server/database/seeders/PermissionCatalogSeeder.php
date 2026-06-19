<?php

namespace Database\Seeders;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Permission;
use App\Models\PermissionRole;
use Illuminate\Database\Seeder;

class PermissionCatalogSeeder extends Seeder
{
    /**
     * Idempotently upsert the canonical permission catalog from the single
     * source-of-truth definition so re-seeding a development database keeps the
     * roles, permissions, and mappings in sync.
     */
    public function run(): void
    {
        $roles = [];

        foreach (PermissionCatalog::roles() as $code => $role) {
            $roles[$code] = PermissionRole::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $role['name'], 'scope_type' => $role['scope_type']],
            );
        }

        $permissions = [];

        foreach (PermissionCatalog::permissions() as $code => $description) {
            $permissions[$code] = Permission::query()->updateOrCreate(
                ['code' => $code],
                ['description' => $description],
            );
        }

        foreach (PermissionCatalog::rolePermissions() as $roleCode => $permissionCodes) {
            $permissionIds = collect($permissionCodes)
                ->map(fn (string $permissionCode): string => $permissions[$permissionCode]->id)
                ->all();

            $roles[$roleCode]->permissions()->sync($permissionIds);
        }
    }
}
