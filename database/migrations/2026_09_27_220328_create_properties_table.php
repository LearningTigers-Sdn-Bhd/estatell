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
        DB::statement('CREATE SEQUENCE property_code_seq START WITH 10101');

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('code')->unique()->default(DB::raw("nextval('property_code_seq')"));
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('status')->default('onboarding');
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state_code')->nullable();
            $table->string('postal_code')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('tin')->nullable();
            $table->timestamp('onboarding_started_at')->nullable();
            $table->timestamp('went_live_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER SEQUENCE property_code_seq OWNED BY properties.code');
        DB::statement("ALTER TABLE properties ADD CONSTRAINT properties_type_check CHECK (type IN ('hotel', 'hostel', 'resort', 'serviced_apartment', 'homestay'))");
        DB::statement("ALTER TABLE properties ADD CONSTRAINT properties_status_check CHECK (status IN ('onboarding', 'pending_approval', 'live', 'suspended'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
