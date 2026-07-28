<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team branding logos (BRAND-025).
 *
 * Teams get a logo and nothing else. A department carries a logo, an accent,
 * and a surface background because a department scopes whole surfaces; a team
 * scopes rows, rosters, and pickers inside a department's surface. Giving a
 * team its own accent would mean two competing identity colors on one screen
 * and a contrast pair nobody validated, which is the same reason BRAND-011
 * stops at three values for a department.
 *
 * The column mirrors the department's: an attachment reference, not a blob,
 * so replace and remove work by repointing and nulling while the attachment
 * row stays immutable (technical spec 18.4, BRAND-023).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->foreignUuid('branding_logo_attachment_id')
                ->nullable()
                ->after('is_default')
                ->constrained('attachments')
                ->nullOnDelete();
            $table->timestamp('branding_updated_at')
                ->nullable()
                ->after('branding_logo_attachment_id');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branding_logo_attachment_id');
            $table->dropColumn('branding_updated_at');
        });
    }
};
