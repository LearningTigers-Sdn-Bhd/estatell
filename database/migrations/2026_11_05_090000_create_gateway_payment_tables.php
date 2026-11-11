<?php

use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Services\Property\CountryProfiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Online payments through Curlec: one payment attempt for each hold, the webhook events, and the system Online payment
 * method for every property. See docs/29-booking-engine/04-payment.md.
 */
return new class extends Migration
{
    private const string CATEGORIES_BEFORE = "'room', 'food_and_beverage', 'laundry', 'minibar', 'transport', 'fee', 'tax', 'discount', 'cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill', 'deposit', 'refund', 'adjustment', 'opening_balance', 'old_system', 'other'";

    private const string TYPES_BEFORE = "'cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill', 'old_system'";

    public function up(): void
    {
        Schema::create('gateway_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_hold_id')->unique()->constrained()->restrictOnDelete();
            $table->string('gateway_account');
            $table->string('order_id')->unique();
            $table->string('gateway_payment_id')->nullable()->unique();
            $table->decimal('amount', 12, 2);
            $table->string('status');
            $table->jsonb('gateway_payload')->nullable();
            $table->timestamps();

            $table->index('property_id');
        });

        DB::statement("ALTER TABLE gateway_payments ADD CONSTRAINT gateway_payments_status_check CHECK (status IN ('created', 'paid', 'failed'))");
        DB::statement('ALTER TABLE gateway_payments ADD CONSTRAINT gateway_payments_amount_check CHECK (amount > 0)');

        Schema::create('gateway_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_account');
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->jsonb('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        $this->replaceChecks(self::CATEGORIES_BEFORE.", 'online'", self::TYPES_BEFORE.", 'online'");
        $this->backfillOnlineMethod();
    }

    public function down(): void
    {
        DB::table('payment_methods')->where('type', PaymentMethodType::Online->value)->delete();
        DB::table('transaction_codes')->where('system_key', SystemTransactionCode::OnlinePayment->value)->delete();

        $this->replaceChecks(self::CATEGORIES_BEFORE, self::TYPES_BEFORE);

        Schema::dropIfExists('gateway_events');
        Schema::dropIfExists('gateway_payments');
    }

    /**
     * The seed action adds only the codes a property lacks. A property whose own method already has the name keeps it.
     */
    private function backfillOnlineMethod(): void
    {
        $seed = app(SeedTransactionCodes::class);

        Property::query()->lazyById()->each(function (Property $property) use ($seed): void {
            $seed($property, CountryProfiles::for((string) $property->country_code));

            $code = $property->transactionCodes()->where('system_key', SystemTransactionCode::OnlinePayment->value)->first();

            if ($code === null || $property->paymentMethods()->where('transaction_code_id', $code->id)->exists()) {
                return;
            }

            $nameTaken = $property->paymentMethods()->whereRaw('lower(name) = ?', [mb_strtolower($code->name)])->exists();

            DB::table('payment_methods')->insert([
                'property_id' => $property->id,
                'transaction_code_id' => $code->id,
                'name' => $nameTaken ? $code->name.' (booking engine)' : $code->name,
                'type' => PaymentMethodType::Online->value,
                'surcharge_type' => 'none',
                'requires_reference' => false,
                'position' => ((int) $property->paymentMethods()->max('position')) + 1,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function replaceChecks(string $categories, string $types): void
    {
        DB::statement('ALTER TABLE transaction_codes DROP CONSTRAINT transaction_codes_category_check');
        DB::statement("ALTER TABLE transaction_codes ADD CONSTRAINT transaction_codes_category_check CHECK (category IN ({$categories}))");

        DB::statement('ALTER TABLE folio_routing_rules DROP CONSTRAINT folio_routing_rules_category_check');
        DB::statement("ALTER TABLE folio_routing_rules ADD CONSTRAINT folio_routing_rules_category_check CHECK (category IS NULL OR category IN ({$categories}))");

        DB::statement('ALTER TABLE payment_methods DROP CONSTRAINT payment_methods_type_check');
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_type_check CHECK (type IN ({$types}))");
    }
};
