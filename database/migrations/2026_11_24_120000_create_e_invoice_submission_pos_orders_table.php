<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The walk-in POS orders a monthly summary or an individual e-invoice reports, one row per order and document line. A
 * rejected or cancelled document flips its rows to `is_active = false` (history stays, the order is free to be filed
 * again); one order is in at most one active document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_invoice_submission_pos_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('e_invoice_submissions')->restrictOnDelete();
            $table->foreignId('pos_order_id')->constrained('pos_orders')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['submission_id', 'pos_order_id']);
            $table->index('pos_order_id');
        });

        DB::statement('CREATE UNIQUE INDEX e_invoice_submission_pos_orders_active_unique ON e_invoice_submission_pos_orders (pos_order_id) WHERE is_active');
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoice_submission_pos_orders');
    }
};
