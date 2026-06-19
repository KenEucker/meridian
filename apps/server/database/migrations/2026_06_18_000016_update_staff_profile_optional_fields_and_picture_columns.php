<?php

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
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            foreach ([
                'preferred_name',
                'handle',
                'phone',
                'city',
                'state',
                'date_of_birth',
                'emergency_contact_name',
                'emergency_contact_phone',
            ] as $column) {
                DB::statement(sprintf('ALTER TABLE staff ALTER COLUMN %s DROP NOT NULL', $column));
            }
        }

        Schema::table('staff', function (Blueprint $table) {
            if (! Schema::hasColumn('staff', 'profile_picture_path')) {
                $table->string('profile_picture_path')->nullable()->after('emergency_contact_phone');
            }

            if (! Schema::hasColumn('staff', 'profile_picture_mime_type')) {
                $table->string('profile_picture_mime_type')->nullable()->after('profile_picture_path');
            }

            if (! Schema::hasColumn('staff', 'profile_picture_size_bytes')) {
                $table->unsignedBigInteger('profile_picture_size_bytes')->nullable()->after('profile_picture_mime_type');
            }

            if (! Schema::hasColumn('staff', 'profile_picture_width')) {
                $table->unsignedInteger('profile_picture_width')->nullable()->after('profile_picture_size_bytes');
            }

            if (! Schema::hasColumn('staff', 'profile_picture_height')) {
                $table->unsignedInteger('profile_picture_height')->nullable()->after('profile_picture_width');
            }

            if (! Schema::hasColumn('staff', 'profile_picture_uploaded_at')) {
                $table->timestamp('profile_picture_uploaded_at')->nullable()->after('profile_picture_height');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            foreach ([
                'profile_picture_path',
                'profile_picture_mime_type',
                'profile_picture_size_bytes',
                'profile_picture_width',
                'profile_picture_height',
                'profile_picture_uploaded_at',
            ] as $column) {
                if (Schema::hasColumn('staff', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
