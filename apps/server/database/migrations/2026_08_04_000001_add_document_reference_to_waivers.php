<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A waiver may reference a published policy/procedure document as the text
     * the staff member is agreeing to (WAIVER-007), and a completion of a
     * document-backed waiver records the acknowledged document and version
     * (WAIVER-008) under the same version-recording rule acknowledgments use
     * (POL-043). Both references are nullable because a waiver is never
     * required to carry one (WAIVER-009), and neither table stores signed
     * document contents (WAIVER-004).
     */
    public function up(): void
    {
        Schema::table('waivers', function (Blueprint $table) {
            $table->string('document_type')->nullable()->after('expires_after_days');
            $table->uuid('document_id')->nullable()->after('document_type');

            $table->index(['document_type', 'document_id']);
        });

        Schema::table('waiver_completions', function (Blueprint $table) {
            $table->string('document_type')->nullable()->after('recorded_by_user_id');
            $table->uuid('document_id')->nullable()->after('document_type');
            $table->unsignedInteger('document_revision')->nullable()->after('document_id');
            $table->unsignedInteger('fragment_revision')->nullable()->after('document_revision');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('waiver_completions', function (Blueprint $table) {
            $table->dropColumn([
                'document_type',
                'document_id',
                'document_revision',
                'fragment_revision',
            ]);
        });

        Schema::table('waivers', function (Blueprint $table) {
            $table->dropIndex(['document_type', 'document_id']);
            $table->dropColumn(['document_type', 'document_id']);
        });
    }
};
