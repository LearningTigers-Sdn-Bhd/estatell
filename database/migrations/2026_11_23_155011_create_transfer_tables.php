<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Land transfers: routes, vehicles, drivers and one transfer per unit and direction.
 * See docs/35-transfers/01-land-transfers.md#data-model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10);
            $table->string('name', 60);
            $table->string('place', 120);
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->foreignId('shared_charge_item_id')->nullable()->constrained('charge_items')->restrictOnDelete();
            $table->foreignId('private_charge_item_id')->nullable()->constrained('charge_items')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX transfer_routes_property_name_unique ON transfer_routes (property_id, lower(name))');
        DB::statement('ALTER TABLE transfer_routes ADD CONSTRAINT transfer_routes_minutes_check CHECK (minutes IS NULL OR minutes > 0)');
        DB::statement("ALTER TABLE transfer_routes ADD CONSTRAINT transfer_routes_direction_check CHECK (direction IN ('in', 'out'))");

        Schema::create('transfer_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('plate', 20)->nullable();
            $table->unsignedSmallInteger('seats')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX transfer_vehicles_property_name_unique ON transfer_vehicles (property_id, lower(name))');
        DB::statement('ALTER TABLE transfer_vehicles ADD CONSTRAINT transfer_vehicles_seats_check CHECK (seats IS NULL OR seats > 0)');

        Schema::create('transfer_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('phone', 30)->nullable();
            $table->string('subcontractor', 60)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX transfer_drivers_property_name_unique ON transfer_drivers (property_id, lower(name))');

        Schema::create('unit_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_unit_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10);
            $table->string('mode', 10);
            $table->foreignId('transfer_route_id')->constrained()->restrictOnDelete();
            $table->time('pickup_time');
            $table->string('flight_number', 10)->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignId('transfer_vehicle_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('transfer_driver_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['reservation_unit_id', 'direction']);
        });

        DB::statement("ALTER TABLE unit_transfers ADD CONSTRAINT unit_transfers_direction_check CHECK (direction IN ('in', 'out'))");
        DB::statement("ALTER TABLE unit_transfers ADD CONSTRAINT unit_transfers_mode_check CHECK (mode IN ('shared', 'private', 'included'))");

        Schema::create('unit_transfer_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_add_on_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_transfer_add_ons');
        Schema::dropIfExists('unit_transfers');
        Schema::dropIfExists('transfer_drivers');
        Schema::dropIfExists('transfer_vehicles');
        Schema::dropIfExists('transfer_routes');
    }
};
