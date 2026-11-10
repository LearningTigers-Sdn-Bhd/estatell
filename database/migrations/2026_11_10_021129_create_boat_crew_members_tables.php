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
        Schema::create('boat_crew_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('phone', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX boat_crew_members_property_name_unique ON boat_crew_members (property_id, lower(name))');

        Schema::create('boat_departure_crew', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boat_departure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_crew_member_id')->constrained()->restrictOnDelete();
            $table->boolean('in_charge')->default(false);
            $table->timestamps();
            $table->unique(['boat_departure_id', 'boat_crew_member_id']);
        });

        DB::statement('CREATE UNIQUE INDEX boat_departure_crew_one_in_charge_unique ON boat_departure_crew (boat_departure_id) WHERE in_charge');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boat_departure_crew');
        Schema::dropIfExists('boat_crew_members');
    }
};
