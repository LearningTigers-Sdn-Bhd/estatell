<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More of what a property tells its guests on the Concierge page: a short description, arrival and departure
 * instructions, how to get there, extra topics, the front desk hours and who to call in an emergency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->text('about')->nullable();
            $table->string('arrival_instructions', 1000)->nullable();
            $table->string('departure_instructions', 1000)->nullable();
            $table->boolean('front_desk_open_24h')->default(true);
            $table->time('front_desk_opens_at')->nullable();
            $table->time('front_desk_closes_at')->nullable();
            $table->string('front_desk_whatsapp', 30)->nullable();
            $table->string('after_hours_message', 500)->nullable();
            $table->string('duty_manager_phone', 30)->nullable();
            $table->string('emergency_phone', 30)->nullable();
            $table->string('emergency_services_number', 30)->nullable();
            $table->string('emergency_instructions', 1000)->nullable();
        });

        Schema::create('property_transport_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('directions')->nullable();
            $table->unsignedSmallInteger('airport_distance_km')->nullable();
            $table->unsignedSmallInteger('airport_travel_minutes')->nullable();
            $table->unsignedSmallInteger('station_distance_km')->nullable();
            $table->unsignedSmallInteger('station_travel_minutes')->nullable();
            $table->unsignedSmallInteger('city_centre_distance_km')->nullable();
            $table->unsignedSmallInteger('city_centre_travel_minutes')->nullable();
            $table->string('nearest_transit_stop', 200)->nullable();
            $table->boolean('airport_transfer_offered')->default(false);
            $table->decimal('airport_transfer_price', 12, 2)->nullable();
            $table->unsignedSmallInteger('airport_transfer_lead_hours')->nullable();
            $table->string('pickup_point', 200)->nullable();
            $table->string('shuttle_schedule', 200)->nullable();
            $table->string('parking_notes', 500)->nullable();
            $table->string('transport_notes', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('property_info_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100);
            $table->string('body', 2000);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['property_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_info_topics');
        Schema::dropIfExists('property_transport_details');

        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn([
                'about', 'arrival_instructions', 'departure_instructions', 'front_desk_open_24h', 'front_desk_opens_at',
                'front_desk_closes_at', 'front_desk_whatsapp', 'after_hours_message', 'duty_manager_phone', 'emergency_phone',
                'emergency_services_number', 'emergency_instructions',
            ]);
        });
    }
};
