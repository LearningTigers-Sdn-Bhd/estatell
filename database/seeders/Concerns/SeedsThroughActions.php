<?php

namespace Database\Seeders\Concerns;

use App\Actions\Folio\PostCharge;
use App\Actions\FrontDesk\CheckInAllUnits;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Payments\RecordPayment;
use App\Actions\Reservations\SyncReservationStatus;
use App\Data\Folio\NewChargeData;
use App\Data\Payments\NewPaymentData;
use App\Enums\PaymentPurpose;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Front desk and folio steps shared by the seeders that build a property through the production Actions. Every step
 * throws on a refusal, so the seeder's transaction leaves nothing behind.
 */
trait SeedsThroughActions
{
    /**
     * Check in every unit of a reservation through `CheckInAllUnits`. A refused unit stops the run.
     *
     * @return EloquentCollection<int, ReservationUnit>
     */
    private function checkIn(User $owner, Reservation $reservation): Collection
    {
        $units = $reservation->units()->orderBy('id')->get();
        $refusals = array_filter(app(CheckInAllUnits::class)($owner, $reservation, array_values(array_map(intval(...), $units->modelKeys()))));

        if ($refusals !== []) {
            throw new RuntimeException('Check-in was refused: '.implode(', ', $refusals).'.');
        }

        return $reservation->units()->orderBy('id')->get();
    }

    /**
     * Run a front desk Action on each unit of a reservation. A refusal stops the run.
     *
     * @param  callable(ReservationUnit): UnitStayResult  $action
     */
    private function forEachUnit(Reservation $reservation, callable $action, string $label): void
    {
        foreach ($reservation->units()->orderBy('id')->get() as $unit) {
            $result = $action($unit);

            if ($result->isRefused()) {
                throw new RuntimeException("{$label} was refused: {$result->refusal}.");
            }
        }
    }

    private function unitFolio(ReservationUnit $unit): Folio
    {
        return Folio::query()->where('reservation_unit_id', $unit->id)->firstOrFail();
    }

    /**
     * Write the check-out itself, because `CheckOutUnit` refuses a stay before its departure date.
     */
    private function checkOut(ReservationUnit $unit): void
    {
        $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
        app(SyncReservationStatus::class)($unit->reservation);
    }

    private function charge(User $owner, ReservationUnit $unit, ChargeItem $item, int $quantity = 1): FolioLine
    {
        $result = app(PostCharge::class)($owner, $unit, new NewChargeData(
            chargeItemId: $item->id,
            transactionCodeId: null,
            description: null,
            quantity: $quantity,
            unitAmount: (string) $item->default_amount,
            idempotencyKey: 'manual:'.Str::uuid(),
        ));

        if ($result->isRefused()) {
            throw new RuntimeException("Posting {$item->name} was refused: {$result->refusal}.");
        }

        return $result->lines[0];
    }

    private function advance(User $owner, Reservation $reservation, ReservationUnit $unit, PaymentMethod $method, string $amount): void
    {
        $result = app(RecordPayment::class)($owner, $reservation, null, $unit, new NewPaymentData($method->id, PaymentPurpose::Advance, $amount, 'ADV-'.Str::upper(Str::random(6)), (string) Str::uuid()));

        if ($result->isRefused()) {
            throw new RuntimeException("The advance payment was refused: {$result->refusal}.");
        }
    }

    private function pay(User $owner, Reservation $reservation, Folio $folio, PaymentMethod $method, PaymentPurpose $purpose, string $amount): Payment
    {
        $result = app(RecordPayment::class)($owner, $reservation, $folio, null, new NewPaymentData($method->id, $purpose, $amount, $method->requires_reference ? 'REF-'.Str::upper(Str::random(6)) : null, (string) Str::uuid()));

        if ($result->isRefused()) {
            throw new RuntimeException("The payment was refused: {$result->refusal}.");
        }

        return $result->payment;
    }
}
