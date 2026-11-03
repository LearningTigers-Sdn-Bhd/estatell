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
        Schema::create('boats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('registration', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX boats_property_name_unique ON boats (property_id, lower(name))');

        Schema::create('boat_departure_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_departure_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('boat_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('crew', 120)->nullable();
            $table->timestamps();
            $table->unique(['boat_departure_id', 'date']);
        });

        DB::statement('ALTER TABLE boat_departure_runs ADD CONSTRAINT boat_departure_runs_not_empty_check CHECK (boat_id IS NOT NULL OR crew IS NOT NULL)');

        Schema::table('property_settings', function (Blueprint $table) {
            $table->foreignId('charter_charge_item_id')->nullable()->constrained('charge_items')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('charter_charge_item_id');
        });
        Schema::dropIfExists('boat_departure_runs');
        Schema::dropIfExists('boats');
    }
};
