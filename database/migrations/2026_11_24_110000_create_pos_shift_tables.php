<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Native POS registers, register shifts and cash movements. Money tables use restrict foreign keys, and the frozen
     * Z-report is an issued-document snapshot. See docs/34-native-pos/05-shifts-audit-and-einvoice.md.
     */
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->string('name', 80);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX pos_registers_name_unique ON pos_registers (pos_outlet_id, lower(name))');

        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->restrictOnDelete();
            $table->date('opened_business_date');
            $table->date('closed_business_date')->nullable();
            $table->string('shift_number', 40);
            $table->string('status', 20)->default('open');
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('opening_cash', 12, 2);
            $table->decimal('cash_payments', 12, 2)->default(0);
            $table->decimal('cash_refunds', 12, 2)->default(0);
            $table->decimal('paid_in', 12, 2)->default(0);
            $table->decimal('paid_out', 12, 2)->default(0);
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->unsignedSmallInteger('recount_count')->default(0);
            $table->decimal('cash_variance', 12, 2)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('closing_note')->nullable();
            $table->jsonb('z_report_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'shift_number']);
            $table->index(['property_id', 'closed_business_date', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX pos_shifts_one_open_per_register ON pos_shifts (pos_register_id) WHERE status <> 'closed'");
        DB::statement("ALTER TABLE pos_shifts ADD CONSTRAINT pos_shifts_status_check CHECK (status IN ('open', 'closing_control', 'closed'))");
        DB::statement('ALTER TABLE pos_shifts ADD CONSTRAINT pos_shifts_opening_cash_check CHECK (opening_cash >= 0)');

        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $table->date('business_date');
            $table->string('idempotency_key', 120);
            $table->string('kind', 20);
            $table->foreignId('counter_transaction_code_id')->nullable()->constrained('transaction_codes')->restrictOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('reason', 200);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(['property_id', 'idempotency_key']);
            $table->index(['pos_shift_id', 'kind']);
            $table->index(['property_id', 'business_date']);
        });

        DB::statement('ALTER TABLE pos_cash_movements ADD CONSTRAINT pos_cash_movements_amount_check CHECK (amount >= 0)');
        DB::statement("ALTER TABLE pos_cash_movements ADD CONSTRAINT pos_cash_movements_kind_check CHECK (kind IN ('float_in', 'safe_drop', 'payout', 'no_sale_open'))");
        DB::statement("ALTER TABLE pos_cash_movements ADD CONSTRAINT pos_cash_movements_counter_code_check CHECK (kind = 'no_sale_open' OR counter_transaction_code_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_shifts');
        Schema::dropIfExists('pos_registers');
    }
};
