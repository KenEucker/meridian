<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization and department branding profiles (M15A.2; requirements 3.42,
 * BRAND-001, BRAND-004, BRAND-006, BRAND-009, BRAND-013; data/API 10.1, 10.6).
 *
 * Branding lives as columns on the two entities it belongs to rather than in a
 * `branding_profiles` table. A profile has exactly one owner, is created and
 * destroyed with that owner, and is read on nearly every request that renders a
 * surface; a separate table would add a join to the hottest read in the product
 * to model a one-to-one relationship that never varies.
 *
 * The palette is one JSON column rather than ten string columns. The ten
 * settable values are meaningful only together — a validator checks pairs
 * across them and a token resolver consumes the whole set — so the row's real
 * unit of change is the palette, not an individual color. Storing it whole also
 * means adding a settable value later is a serializer change rather than a
 * migration against a live table. Nothing queries by an individual color.
 *
 * Logos are attachment references, not blobs. BRAND-023 puts branding assets on
 * the existing attachment path, so these columns name which attachment is
 * *current*; the attachment row itself stays immutable (technical spec 18.4).
 * That is also how "replace" and "remove" work without destroying anything:
 * replacing repoints the reference at a new attachment, removing nulls it, and
 * BRAND-004 explicitly does not require preserving superseded assets.
 *
 * `department_branding_enabled` is the organization-wide switch from BRAND-013.
 * It defaults to true because a department that has set no branding renders
 * exactly as before either way, so the permissive default costs nothing and
 * matches what an organization that has never thought about the switch expects.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A branding logo is uploaded from a browser by an organizer, not from
        // a trusted field device, so there is no originating device to record.
        // The node is nullable for the same reason at a different point in the
        // install's life: an organization sets its branding during first-run
        // configuration, which is before node setup has necessarily run, and
        // refusing the upload then would gate identity behind pairing. Both
        // columns stay present and are still filled by device-origin uploads
        // such as Field Report photos, which have both to record.
        Schema::table('attachments', function (Blueprint $table): void {
            $table->uuid('origin_device_id')->nullable()->change();
            $table->uuid('origin_node_id')->nullable()->change();
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('branding_display_name')->nullable()->after('slug');
            $table->json('branding_palette_json')->nullable()->after('branding_display_name');
            $table->foreignUuid('branding_full_lockup_attachment_id')
                ->nullable()
                ->after('branding_palette_json')
                ->constrained('attachments')
                ->nullOnDelete();
            $table->foreignUuid('branding_compact_mark_attachment_id')
                ->nullable()
                ->after('branding_full_lockup_attachment_id')
                ->constrained('attachments')
                ->nullOnDelete();
            $table->boolean('department_branding_enabled')
                ->default(true)
                ->after('branding_compact_mark_attachment_id');
            $table->timestamp('branding_updated_at')
                ->nullable()
                ->after('department_branding_enabled');
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->foreignUuid('branding_logo_attachment_id')
                ->nullable()
                ->after('default_team_id')
                ->constrained('attachments')
                ->nullOnDelete();
            // Stored as authored: a seven-character `#rrggbb`. The column is
            // wider than that so a rejected value is still readable in the row
            // rather than silently truncated on its way to the validator.
            $table->string('branding_accent_color', 32)
                ->nullable()
                ->after('branding_logo_attachment_id');
            $table->string('branding_surface_color', 32)
                ->nullable()
                ->after('branding_accent_color');
            $table->timestamp('branding_updated_at')
                ->nullable()
                ->after('branding_surface_color');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branding_logo_attachment_id');
            $table->dropColumn([
                'branding_accent_color',
                'branding_surface_color',
                'branding_updated_at',
            ]);
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branding_full_lockup_attachment_id');
            $table->dropConstrainedForeignId('branding_compact_mark_attachment_id');
            $table->dropColumn([
                'branding_display_name',
                'branding_palette_json',
                'department_branding_enabled',
                'branding_updated_at',
            ]);
        });

        Schema::table('attachments', function (Blueprint $table): void {
            $table->uuid('origin_device_id')->nullable(false)->change();
            $table->uuid('origin_node_id')->nullable(false)->change();
        });
    }
};
