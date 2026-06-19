<?php

use App\Domain\Permissions\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('permission_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('scope_type');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('description');
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_role_id')->constrained('permission_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['permission_role_id', 'permission_id']);
        });

        Schema::create('team_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->restrictOnDelete();
            $table->foreignId('permission_role_id')->constrained('permission_roles')->restrictOnDelete();
            $table->timestamps();
            $table->timestamp('revoked_at')->nullable()->index();

            $table->index(['team_id', 'revoked_at']);
            $table->index(['event_id', 'revoked_at']);
        });

        $this->seedCatalog();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_grants');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('permission_roles');
    }

    /**
     * Seed the canonical permission catalog so every environment starts with
     * the documented effective roles, capabilities, and role mappings.
     */
    private function seedCatalog(): void
    {
        $now = now();

        $roleIds = [];

        foreach (PermissionCatalog::roles() as $code => $role) {
            $roleIds[$code] = DB::table('permission_roles')->insertGetId([
                'name' => $role['name'],
                'code' => $code,
                'scope_type' => $role['scope_type'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = [];

        foreach (PermissionCatalog::permissions() as $code => $description) {
            $permissionIds[$code] = DB::table('permissions')->insertGetId([
                'code' => $code,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (PermissionCatalog::rolePermissions() as $roleCode => $permissionCodes) {
            foreach ($permissionCodes as $permissionCode) {
                DB::table('role_permissions')->insert([
                    'permission_role_id' => $roleIds[$roleCode],
                    'permission_id' => $permissionIds[$permissionCode],
                    'created_at' => $now,
                ]);
            }
        }
    }
};
