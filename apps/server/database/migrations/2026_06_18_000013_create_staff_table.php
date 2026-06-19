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
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            $table->string('preferred_name');
            $table->string('handle');
            $table->text('formerly_known_as')->nullable();
            $table->string('email')->unique();
            $table->string('phone');
            $table->string('city');
            $table->string('state');
            $table->date('date_of_birth');
            $table->string('emergency_contact_name');
            $table->string('emergency_contact_phone');
            $table->string('profile_picture_path')->nullable();
            $table->string('profile_picture_mime_type')->nullable();
            $table->unsignedBigInteger('profile_picture_size_bytes')->nullable();
            $table->unsignedInteger('profile_picture_width')->nullable();
            $table->unsignedInteger('profile_picture_height')->nullable();
            $table->timestamp('profile_picture_uploaded_at')->nullable();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable()->index();

            $table->index(['handle', 'archived_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
