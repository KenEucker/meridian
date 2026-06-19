<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('department_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable()->index();

            $table->unique(['department_id', 'code']);
            $table->index(['department_id', 'archived_at']);
        });

        DB::table('departments')
            ->orderBy('id')
            ->each(function (object $department): void {
                $now = now();

                $defaultTeamId = (string) Str::uuid();

                DB::table('teams')->insert([
                    'id' => $defaultTeamId,
                    'department_id' => $department->id,
                    'name' => 'Default',
                    'code' => 'DEFAULT',
                    'description' => null,
                    'is_default' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'archived_at' => null,
                ]);

                DB::table('departments')
                    ->where('id', $department->id)
                    ->update([
                        'default_team_id' => $defaultTeamId,
                        'updated_at' => $now,
                    ]);
            });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('default_team_id')->references('id')->on('teams')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['default_team_id']);
        });

        Schema::dropIfExists('teams');
    }
};
