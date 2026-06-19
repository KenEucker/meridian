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
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->uuid('default_ic_department_id')->nullable()->index();
            $table->uuid('default_credit_policy_id')->nullable()->index();
            $table->unsignedSmallInteger('active_inactive_threshold_years')->nullable();
            $table->unsignedSmallInteger('prospective_inactive_threshold_years')->nullable();
            $table->unsignedTinyInteger('calendar_year_start_month')->nullable();
            $table->unsignedTinyInteger('calendar_year_start_day')->nullable();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
