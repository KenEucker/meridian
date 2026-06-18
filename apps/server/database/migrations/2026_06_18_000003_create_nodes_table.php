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
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->string('node_name')->unique();
            $table->string('node_role', 32)->index();
            $table->text('public_key');
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('event_id')->nullable()->index();
            $table->text('central_node_url')->nullable();
            $table->timestamps();
            $table->timestamp('revoked_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
