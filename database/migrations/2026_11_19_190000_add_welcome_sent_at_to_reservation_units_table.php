<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the welcome of a checked-in stay (the email and `stay.welcome_ready`) was sent. The welcome is claimed with an atomic update on this
 * column, so it is sent exactly once per check-in, whether the door PIN or the deadline triggers it first. A stay checked in again later has
 * a `checked_in_at` after it, so it is welcomed again. See docs/30-concierge/01-overview.md#the-welcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->timestamp('welcome_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->dropColumn('welcome_sent_at');
        });
    }
};
