<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Native POS staff time clock: when a waiter or cashier clocked in and out at an outlet. A person has at most one open
     * clock. See docs/34-native-pos/01-overview.md.
     */
    public function up(): void
    {
        Schema::create('pos_time_clocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('clocked_in_at');
            $table->timestamp('clocked_out_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'user_id', 'clocked_out_at']);
        });

        DB::statement('CREATE UNIQUE INDEX pos_time_clocks_one_open_per_user ON pos_time_clocks (user_id) WHERE clocked_out_at IS NULL');
        DB::statement('ALTER TABLE pos_time_clocks ADD CONSTRAINT pos_time_clocks_order_check CHECK (clocked_out_at IS NULL OR clocked_out_at >= clocked_in_at)');
    }
};
