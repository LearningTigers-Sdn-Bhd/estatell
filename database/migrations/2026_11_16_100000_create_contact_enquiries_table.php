<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquiries from the public Contact Us form. Platform data: no property, so no property_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('hotel_name', 150);
            $table->string('location', 255);
            $table->string('name', 120);
            $table->string('email', 255);
            $table->string('phone', 30);
            $table->unsignedSmallInteger('room_count');
            $table->text('message');
            $table->string('status', 20)->default('new');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_enquiries');
    }
};
