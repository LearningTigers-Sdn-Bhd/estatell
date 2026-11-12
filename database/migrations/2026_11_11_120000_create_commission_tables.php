<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commission rates, the commission ledger and the channel manager commission start date.
 * See docs/30-commission-fees/03-ledger.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rates', function (Blueprint $table) {
            $table->id();
            $table->string('service', 30);
            $table->decimal('rate', 5, 2);
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['service', 'effective_from']);
        });

        DB::statement("ALTER TABLE commission_rates ADD CONSTRAINT commission_rates_service_check CHECK (service IN ('booking_engine', 'channel_manager'))");
        DB::statement('ALTER TABLE commission_rates ADD CONSTRAINT commission_rates_rate_check CHECK (rate >= 0 AND rate <= 100)');

        DB::table('commission_rates')->insert([
            ['service' => 'booking_engine', 'rate' => '10.00', 'effective_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()],
            ['service' => 'channel_manager', 'rate' => '3.00', 'effective_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('service', 30)->nullable();
            $table->decimal('base_amount', 12, 2)->nullable();
            $table->decimal('rate', 5, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('due_date')->nullable();
            $table->foreignId('gateway_payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('gateway_refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('commission_entries')->restrictOnDelete();
            $table->string('idempotency_key')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['property_id', 'idempotency_key']);
            $table->index(['property_id', 'due_date']);
            $table->index('reservation_id');
            $table->index('gateway_payment_id');
            $table->index('gateway_refund_id');
        });

        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_type_check CHECK (type IN ('collected', 'refunded', 'refund_fee', 'commission', 'adjustment', 'carried_forward'))");
        DB::statement('ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_amount_check CHECK (amount <> 0)');
        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_commission_check CHECK ((type = 'commission') = (service IS NOT NULL AND base_amount IS NOT NULL AND rate IS NOT NULL))");
        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_reservation_check CHECK (type NOT IN ('collected', 'refunded', 'refund_fee', 'commission') OR reservation_id IS NOT NULL)");
        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_payment_check CHECK (type <> 'collected' OR gateway_payment_id IS NOT NULL)");
        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_refund_check CHECK (type NOT IN ('refunded', 'refund_fee') OR gateway_refund_id IS NOT NULL)");
        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_adjustment_check CHECK (type <> 'adjustment' OR reason IS NOT NULL)");

        Schema::table('platform_settings', function (Blueprint $table) {
            $table->date('channel_manager_commission_from')->nullable()->after('media_disk');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('channel_manager_commission_from');
        });

        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commission_rates');
    }
};
