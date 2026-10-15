<?php

namespace App\Actions\Onboarding;

use App\Actions\Catalogues\CreatePaymentMethod;
use App\Actions\Catalogues\UpdatePaymentMethod;
use App\Enums\PaymentMethodType;
use App\Enums\SurchargeType;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveOnboardingPaymentMethods
{
    public function __construct(
        private CreatePaymentMethod $createPaymentMethod,
        private UpdatePaymentMethod $updatePaymentMethod,
    ) {}

    /**
     * Save the Payment methods table in one transaction. A row with an id updates that method: its name, its On switch
     * and its reference box, while the type, the code and any surcharge stay. City ledger keeps its On and reference
     * values, because Companies turns it on. A row without an id creates a method with a code derived from the name,
     * no surcharge, and the type's default reference rule overridden by the row. Saved methods without a row (OTA
     * collected) are left alone, and methods are never deleted.
     *
     * @param  list<array{id: int|null, name: string, type: PaymentMethodType|null, active: bool, requires_reference: bool}>  $rows
     */
    public function __invoke(Property $property, User $actor, array $rows): void
    {
        DB::transaction(function () use ($property, $actor, $rows): void {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $saved = $property->paymentMethods()->with('transactionCode')->get()->keyBy('id');

            foreach ($rows as $row) {
                if ($row['id'] !== null) {
                    $this->update($actor, $saved->findOrFail($row['id']), $row);

                    continue;
                }

                $type = $row['type'] ?? PaymentMethodType::Cash;

                ($this->createPaymentMethod)($property, $actor, [
                    'name' => $row['name'],
                    'type' => $type->value,
                    'code' => $this->derivedCode($property, $row['name']),
                    'surcharge_type' => SurchargeType::None->value,
                    'surcharge_value' => null,
                    'surcharge_charge_item_id' => null,
                    'requires_reference' => $row['requires_reference'],
                    'active' => $row['active'],
                ]);
            }
        });
    }

    /**
     * @param  array{id: int|null, name: string, type: PaymentMethodType|null, active: bool, requires_reference: bool}  $row
     */
    private function update(User $actor, PaymentMethod $paymentMethod, array $row): void
    {
        $isCityLedger = $paymentMethod->type === PaymentMethodType::DirectBill;

        ($this->updatePaymentMethod)($actor, $paymentMethod, [
            'name' => $row['name'],
            'code' => $paymentMethod->transactionCode->code,
            'surcharge_type' => $paymentMethod->surcharge_type->value,
            'surcharge_value' => $paymentMethod->surcharge_value,
            'surcharge_charge_item_id' => $paymentMethod->surcharge_charge_item_id,
            'requires_reference' => $isCityLedger ? $paymentMethod->requires_reference : $row['requires_reference'],
            'active' => $isCityLedger ? $paymentMethod->active : $row['active'],
        ]);
    }

    /**
     * The code of a new method: the name in capitals with underscores (DuitNow QR → DUITNOW_QR), at most 20
     * characters, and a number when the code is taken by any transaction code of the property. Settings can edit it
     * later.
     */
    private function derivedCode(Property $property, string $name): string
    {
        $base = Str::of($name)->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString();
        $base = $base === '' ? 'PAYMENT' : $base;

        for ($number = 1; ; $number++) {
            $suffix = $number === 1 ? '' : "_{$number}";
            $candidate = trim(substr($base, 0, 20 - strlen($suffix)), '_').$suffix;

            $taken = $property->transactionCodes()->whereRaw('lower(code) = ?', [mb_strtolower($candidate)])->exists();

            if (! $taken) {
                return $candidate;
            }
        }
    }
}
