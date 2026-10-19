<?php

use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Services\Property\CountryProfiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every existing property the OPENING_BAL and OLD_SYSTEM codes from its own country profile, and the inactive
     * Old system payment method. The seed action only adds the codes a property lacks. Other deleted methods stay deleted.
     */
    public function up(): void
    {
        $seed = app(SeedTransactionCodes::class);

        Property::query()->lazyById()->each(function (Property $property) use ($seed): void {
            $seed($property, CountryProfiles::for((string) $property->country_code));

            $code = $property->transactionCodes()->where('system_key', SystemTransactionCode::OldSystemPayment->value)->first();

            if ($code === null || $property->paymentMethods()->where('transaction_code_id', $code->id)->exists()) {
                return;
            }

            $nameTaken = $property->paymentMethods()->whereRaw('lower(name) = ?', [mb_strtolower($code->name)])->exists();

            DB::table('payment_methods')->insert([
                'property_id' => $property->id,
                'transaction_code_id' => $code->id,
                'name' => $nameTaken ? $code->name.' (old PMS)' : $code->name,
                'type' => PaymentMethodType::OldSystem->value,
                'surcharge_type' => 'none',
                'requires_reference' => true,
                'position' => ((int) $property->paymentMethods()->max('position')) + 1,
                'active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Remove the rows that nothing uses.
     */
    public function down(): void
    {
        $keys = [SystemTransactionCode::OpeningBalance->value, SystemTransactionCode::OldSystemPayment->value];

        DB::table('payment_methods')
            ->where('type', PaymentMethodType::OldSystem->value)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('payments')->whereColumn('payments.payment_method_id', 'payment_methods.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('ar_payments')->whereColumn('ar_payments.payment_method_id', 'payment_methods.id'))
            ->delete();

        DB::table('transaction_codes')
            ->whereIn('system_key', $keys)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('folio_lines')->whereColumn('folio_lines.transaction_code_id', 'transaction_codes.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('payment_methods')->whereColumn('payment_methods.transaction_code_id', 'transaction_codes.id'))
            ->delete();
    }
};
