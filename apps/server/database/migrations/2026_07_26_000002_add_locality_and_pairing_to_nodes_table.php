<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Once a node pairs with central, the `nodes` table holds records for peer
     * nodes as well as this install's own node (technical spec 7.3, 7.4).
     * `is_local` keeps the install's own node resolvable, and `paired_at`
     * records when a peer completed pairing.
     */
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('is_local')->default(false)->after('node_role');
            $table->timestamp('paired_at')->nullable()->after('central_node_url');
        });

        // Every existing row was created by first-run setup for this install.
        DB::table('nodes')->update(['is_local' => true]);

        Schema::table('nodes', function (Blueprint $table) {
            $table->index('is_local');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex(['is_local']);
            $table->dropColumn(['is_local', 'paired_at']);
        });
    }
};
