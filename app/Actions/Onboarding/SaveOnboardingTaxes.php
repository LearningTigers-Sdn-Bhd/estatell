<?php

namespace App\Actions\Onboarding;

use App\Actions\Catalogues\CreateTax;
use App\Actions\Catalogues\UpdateRoomRevenueTaxes;
use App\Actions\Catalogues\UpdateTax;
use App\Data\Catalogues\TaxInputData;
use App\Enums\OnboardingStep;
use App\Enums\TaxRateType;
use App\Models\Property;
use App\Models\Tax;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveOnboardingTaxes
{
    public function __construct(
        private CreateTax $createTax,
        private UpdateTax $updateTax,
        private UpdateRoomRevenueTaxes $updateRoomRevenueTaxes,
        private SkipOnboardingStep $skipStep,
        private UnskipOnboardingStep $unskipStep,
    ) {}

    /**
     * Save the Taxes table in one transaction. With `$noTax` every tax is turned off and the step is skipped ("We don't
     * charge tax"). Otherwise the skip is removed, a row with an id updates that tax (its short code stays), a row
     * without one creates a custom tax with a code derived from the name, and the rows ticked "Applies to rooms" become
     * the room revenue taxes. Saved taxes without a row are left alone: removing one is a separate step.
     *
     * @param  list<array{id: int|null, name: string, rate_type: TaxRateType, rate: string, is_inclusive: bool, foreign_guests_only: bool, collect_at_check_in: bool, registration_number: string|null, enabled: bool, applies_to_rooms: bool}>  $rows
     */
    public function __invoke(Property $property, User $actor, array $rows, bool $noTax): void
    {
        DB::transaction(function () use ($property, $actor, $rows, $noTax): void {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $saved = $property->taxes()->get()->keyBy('id');

            if ($noTax) {
                foreach ($saved->where('enabled', true) as $tax) {
                    ($this->updateTax)($actor, $tax, TaxInputData::fromModel($tax, false));
                }

                ($this->skipStep)($property, OnboardingStep::Taxes, $actor);

                return;
            }

            ($this->unskipStep)($property, OnboardingStep::Taxes);

            $roomTaxIds = [];

            foreach ($rows as $row) {
                $existing = $row['id'] === null ? null : $saved->findOrFail($row['id']);
                $data = new TaxInputData(
                    name: $row['name'],
                    code: $existing instanceof Tax ? $existing->code : $this->derivedCode($property, $row['name']),
                    rateType: $row['rate_type'],
                    rate: (string) BigDecimal::of($row['rate'])->toScale(2),
                    isInclusive: $row['is_inclusive'],
                    foreignGuestsOnly: $row['foreign_guests_only'],
                    collectAtCheckIn: $row['collect_at_check_in'],
                    registrationNumber: $row['registration_number'],
                    enabled: $row['enabled'],
                );

                if ($existing instanceof Tax) {
                    ($this->updateTax)($actor, $existing, $data);
                    $tax = $existing;
                } else {
                    $tax = ($this->createTax)($property, $actor, $data);
                }

                if ($row['applies_to_rooms']) {
                    $roomTaxIds[] = $tax->id;
                }
            }

            ($this->updateRoomRevenueTaxes)($property, $actor, $roomTaxIds);
        });
    }

    /**
     * The short code of a new tax: the name in capitals with underscores (VAT stays VAT), and a number when the code or
     * its posting code TAX_{CODE} is taken. Settings can edit it later.
     */
    private function derivedCode(Property $property, string $name): string
    {
        $base = trim(substr(Str::of($name)->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString(), 0, 40), '_');
        $base = $base === '' ? 'TAX' : $base;

        for ($number = 1; ; $number++) {
            $candidate = $number === 1 ? $base : "{$base}_{$number}";

            $taken = $property->taxes()->whereRaw('lower(code) = ?', [mb_strtolower($candidate)])->exists()
                || $property->transactionCodes()->whereRaw('lower(code) = ?', [mb_strtolower('TAX_'.$candidate)])->exists();

            if (! $taken) {
                return $candidate;
            }
        }
    }
}
