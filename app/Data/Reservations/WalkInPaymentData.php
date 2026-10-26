<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Payment section of the walk-in form shows, worked out by a trial booking that is rolled back
 * (`WalkInPayment::preview()`). `roomCharges` is the full bill of every unit. `roomChargesHidden` is true when the
 * company is billed (company and a Master folio mode), so the form hides the Room charges and tax box. `taxes` are the
 * **Collect at check-in** taxes added up across the units, one row per tax (`exempt` when no unit pays it), shown when
 * Room charges and tax is unticked. `deposit` is the amount for each unit, the units that pay it and the total, or
 * null when the property sets no deposit. The two totals are what the rail shows for each state of the Room charges
 * box. All amounts are read only text; the save works them out again and compares with `expected_total`. When the
 * booking would be refused, `errors` holds the refusal keyed by form field, as a save would answer, and the rest is empty.
 */
class WalkInPaymentData extends Data
{
    /**
     * @param  list<array{name: string, amount: string, exempt: bool}>  $taxes
     * @param  array{amount: string, units: int, total: string}|null  $deposit
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public string $roomCharges,
        public bool $roomChargesHidden,
        public array $taxes,
        public string $taxesTotal,
        public ?array $deposit,
        public string $totalWithRoomCharges,
        public string $totalWithoutRoomCharges,
        public array $errors = [],
    ) {}

    /**
     * @param  array<string, string>  $errors
     */
    public static function refused(array $errors): self
    {
        return new self('0.00', false, [], '0.00', null, '0.00', '0.00', $errors);
    }
}
