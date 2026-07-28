<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event branding logos (BRAND-028).
 *
 * An event gets a logo and nothing else, for the same reason a team does: the
 * palette is the organization's and a second settable palette would be a second
 * set of contrast pairs nobody validated.
 *
 * The logo is not decoration. Most staff working an event were recruited by the
 * event, not by the production company running it, and a large share of them
 * will never have heard the organization's name. An install locked to an event
 * that shows only the producer's mark asks those people to recognise a brand
 * they have no reason to know. The event's own mark is the one they can
 * identify, so where an install is locked to an event, that is the mark the
 * chrome carries.
 *
 * Nothing here changes who the software belongs to: the organization still owns
 * the palette, the display name, and every surface that is not event-locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->foreignUuid('branding_logo_attachment_id')
                ->nullable()
                ->after('status')
                ->constrained('attachments')
                ->nullOnDelete();
            $table->timestamp('branding_updated_at')
                ->nullable()
                ->after('branding_logo_attachment_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branding_logo_attachment_id');
            $table->dropColumn('branding_updated_at');
        });
    }
};
