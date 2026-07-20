<?php

use App\Models\Incident;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * M11.7A adds IMS priority, configurable type labels, and involved
     * Rangers/responders to incident create/edit (INC-006, INC-007, INC-014;
     * data/API 10.16).
     */
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('priority_label', 32)
                ->default(Incident::PRIORITY_ROUTINE)
                ->after('status')
                ->index();
        });

        Schema::create('incident_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 100);
            $table->dateTimeTz('created_at');
            $table->dateTimeTz('archived_at')->nullable()->index();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'archived_at']);
        });

        Schema::create('incident_incident_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('incident_id')->constrained('incidents')->restrictOnDelete();
            $table->foreignUuid('incident_type_id')->constrained('incident_types')->restrictOnDelete();
            $table->dateTimeTz('created_at');

            $table->unique(['incident_id', 'incident_type_id']);
        });

        Schema::create('incident_staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('incident_id')->constrained('incidents')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('relationship_label', 80)->default('Responder');
            $table->dateTimeTz('created_at');

            $table->unique(['incident_id', 'staff_id']);
            $table->index(['staff_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_staff');
        Schema::dropIfExists('incident_incident_types');
        Schema::dropIfExists('incident_types');

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('priority_label');
        });
    }
};
