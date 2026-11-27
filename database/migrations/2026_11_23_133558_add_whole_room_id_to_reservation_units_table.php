<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A whole-dorm booking: every bed unit of the booking that buys out one dorm points at that dorm.
     * See docs/12-reservations/03-bed-bookings.md.
     */
    public function up(): void
    {
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->foreignId('whole_room_id')->nullable()->after('room_type_id')->constrained('rooms')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whole_room_id');
        });
    }
};
