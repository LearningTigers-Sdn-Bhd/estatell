<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three more policy texts for the guest guide (room terms, payment and deposits, other), a topic for each FAQ, a main and a
 * hidden flag for each Wi-Fi network, a switch that asks a guest to be at the property to check in, structured parking details (availability, type, price, spaces, height limit, EV charging), and
 * a front desk phone, email and extension.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->string('room_policy', 2000)->nullable();
            $table->string('payment_policy', 2000)->nullable();
            $table->string('other_policies', 2000)->nullable();
            $table->string('front_desk_phone', 30)->nullable();
            $table->string('front_desk_email', 120)->nullable();
            $table->string('front_desk_extension', 10)->nullable();
            $table->boolean('concierge_location_check_enabled')->default(false);
        });

        Schema::table('property_faqs', function (Blueprint $table) {
            $table->string('topic', 100)->nullable();
        });

        Schema::table('property_wifi_networks', function (Blueprint $table) {
            $table->boolean('active')->default(true);
            $table->boolean('primary_network')->default(false);
        });

        Schema::table('property_transport_details', function (Blueprint $table) {
            $table->string('parking_availability', 10)->nullable();
            $table->string('parking_type', 10)->nullable();
            $table->decimal('parking_price', 10, 2)->nullable();
            $table->string('parking_price_unit', 10)->nullable();
            $table->unsignedSmallInteger('parking_spaces')->nullable();
            $table->decimal('parking_height_limit_m', 4, 2)->nullable();
            $table->boolean('parking_ev_charging')->default(false);
            $table->boolean('parking_booking_required')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('property_wifi_networks', function (Blueprint $table) {
            $table->dropColumn(['active', 'primary_network']);
        });

        Schema::table('property_faqs', function (Blueprint $table) {
            $table->dropColumn('topic');
        });

        Schema::table('property_transport_details', function (Blueprint $table) {
            $table->dropColumn(['parking_availability', 'parking_type', 'parking_price', 'parking_price_unit', 'parking_spaces', 'parking_height_limit_m', 'parking_ev_charging', 'parking_booking_required']);
        });

        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn(['room_policy', 'payment_policy', 'other_policies', 'front_desk_phone', 'front_desk_email', 'front_desk_extension', 'concierge_location_check_enabled']);
        });
    }
};
