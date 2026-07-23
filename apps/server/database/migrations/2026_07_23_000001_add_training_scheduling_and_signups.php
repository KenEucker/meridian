<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->string('delivery')->default('in_person')->after('expires_after_days');
            $table->string('online_url')->nullable()->after('delivery');
            $table->timestamp('scheduled_start_at')->nullable()->after('online_url');
            $table->timestamp('scheduled_end_at')->nullable()->after('scheduled_start_at');
            $table->string('location')->nullable()->after('scheduled_end_at');
            $table->unsignedInteger('capacity')->nullable()->after('location');
            $table->string('time_commitment')->nullable()->after('capacity');
            $table->text('after_training')->nullable()->after('time_commitment');
            $table->text('provisions')->nullable()->after('after_training');
            $table->foreignUuid('linked_shift_id')->nullable()->after('provisions')
                ->constrained('shifts')->nullOnDelete();
        });

        Schema::create('training_signups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('training_id')->constrained('trainings')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->timestamp('signed_up_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['training_id', 'staff_id']);
            $table->index(['staff_id', 'cancelled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_signups');

        Schema::table('trainings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_shift_id');
            $table->dropColumn([
                'delivery',
                'online_url',
                'scheduled_start_at',
                'scheduled_end_at',
                'location',
                'capacity',
                'time_commitment',
                'after_training',
                'provisions',
            ]);
        });
    }
};
