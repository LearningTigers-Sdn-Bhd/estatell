<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The staff member who sold the booking, apart from the source it came through and the user who keyed it in
 * (`created_by`). Optional. Deleting the user clears it; a deactivated user stays on the bookings they sold.
 * See docs/12-reservations/12-sales-person.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('sales_person_id')->nullable()->after('booking_source_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_person_id');
        });
    }
};
