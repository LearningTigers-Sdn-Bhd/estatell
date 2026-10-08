<?php

namespace App\Services\FrontDesk;

use App\Data\Reservations\CheckInCollectionData;
use App\Enums\FolioType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Services\Folio\FolioRouter;
use App\Services\Folio\ScheduledFolioRows;
use Brick\Math\BigDecimal;

/**
 * What Collect now asks the guest to pay at check-in, for one unit: the taxes flagged **Collect at check-in** and the
 * security deposit. It reads only. The tax amounts are the Scheduled tax rows of the unit's nights (what the night
 * audit will post), so the guest pays the same sum that lands on the folio. The check-in Action quotes it again on
 * submit and the form never sends an amount. See docs/18-front-desk/01-check-in-and-check-out.md#collect-now.
 */
class CheckInCollection
{
    public function __construct(
        private ScheduledFolioRows $scheduledRows,
        private FolioRouter $folioRouter,
    ) {}

    public function for(ReservationUnit $unit): CheckInCollectionData
    {
        $reservation = $unit->reservation()->with('booker')->firstOrFail();
        $property = $reservation->property()->with('settings')->firstOrFail();
        $flagged = Tax::query()->where('property_id', $property->id)->where('enabled', true)->where('collect_at_check_in', true)->orderBy('id')->get();
        $hidden = $this->folioRouter->roomChargesGoToMaster($unit);
        $taxes = [];
        $total = BigDecimal::zero();

        if ($flagged->isNotEmpty() && ! $hidden) {
            $sums = $this->taxSums($unit, $property, $reservation, $flagged->pluck('id')->all());

            foreach ($flagged as $tax) {
                $amount = $sums[$tax->id] ?? BigDecimal::zero();

                if ($amount->isZero() && ! $tax->foreign_guests_only) {
                    continue;
                }

                $total = $total->plus($amount);
                $taxes[] = ['name' => $tax->name, 'amount' => (string) $amount->toScale(2), 'exempt' => $amount->isZero()];
            }
        }

        return new CheckInCollectionData($taxes, (string) $total->toScale(2), $hidden, $this->deposit($unit, (string) $property->settings?->security_deposit_amount));
    }

    /**
     * The sum of the Scheduled tax rows of the unit's nights, by tax id.
     *
     * @param  list<int>  $taxIds
     * @return array<int, BigDecimal>
     */
    private function taxSums(ReservationUnit $unit, Property $property, Reservation $reservation, array $taxIds): array
    {
        $sums = [];

        foreach ($this->scheduledRows->forReservation($property, $reservation, []) as $block) {
            if ($block['unit'] !== $unit->id) {
                continue;
            }

            foreach ($block['rows'] as $row) {
                if (preg_match('/^night:\d+:tax:(\d+)$/', $row->key, $matches) !== 1 || ! in_array((int) $matches[1], $taxIds, true)) {
                    continue;
                }

                $sums[(int) $matches[1]] = ($sums[(int) $matches[1]] ?? BigDecimal::zero())->plus($row->amount);
            }
        }

        return $sums;
    }

    /**
     * The default deposit, unless the unit already holds one (a deposit taken before check-in is not asked twice).
     */
    private function deposit(ReservationUnit $unit, string $amount): ?string
    {
        if ($amount === '' || BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            return null;
        }

        $held = Payment::query()
            ->whereHas('folio', fn ($folios) => $folios->where('reservation_unit_id', $unit->id)->where('type', FolioType::Unit))
            ->whereNull('refund_of_id')
            ->where('purpose', PaymentPurpose::SecurityDeposit)
            ->where('status', PaymentStatus::Completed)
            ->exists();

        return $held ? null : $amount;
    }
}
