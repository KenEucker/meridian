<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event Info section assignment for policy/procedure documents (M11.20).
 *
 * Nullable because assignment is opt-in placement metadata: most documents
 * never appear on Event Info, and a document that loses its section assignment
 * stays a normal published document in the library rather than disappearing.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['policy_documents', 'procedure_documents'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('event_info_section')->nullable()->after('slug');
            });

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->index(
                    ['organization_id', 'event_info_section', 'state'],
                    $table.'_event_info_section_index',
                );
            });
        }
    }

    public function down(): void
    {
        foreach (['policy_documents', 'procedure_documents'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex($table.'_event_info_section_index');
                $blueprint->dropColumn('event_info_section');
            });
        }
    }
};
