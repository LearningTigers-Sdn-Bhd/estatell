<?php

namespace App\Actions\FrontDesk;

use App\Actions\Guests\UpdateGuest;
use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Data\Reservations\CollectNowData;
use App\Enums\PaymentPurpose;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\CheckInCollection;
use App\Services\Reservations\UnitNationality;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class CheckInAndCollect
{
    public function __construct(
        private CheckInUnit $checkInUnit,
        private RecordPayment $recordPayment,
        private UpdateGuest $updateGuest,
        private UnitNationality $unitNationality,
        private CheckInCollection $checkInCollection,
    ) {}

    /**
     * The check-in form: save a missing nationality, check the unit in with `CheckInUnit`, then take the Collect now
     * payments. The nationality and the check-in are one transaction, so a refusal of the check-in saves nothing.
     * The nationality goes to the unit's primary guest, or to the booker when the unit has none, the person
     * `UnitNationality` reads. Collect now runs after the check-in: the taxes flagged **Collect at check-in** as an
     * Advance payment, then the security deposit, both on the unit folio with the same method and reference. The
     * amounts are worked out here by `CheckInCollection`, never read from the form. A payment that is refused leaves
     * the unit checked in and the payment that already succeeded stays, so the result names the refusal. Permissions
     * are checked by the route and the Form Request. See docs/18-front-desk/01-check-in-and-check-out.md#collect-now.
     */
    public function __invoke(User $actor, ReservationUnit $unit, ?string $nationality = null, bool $withoutCard = false, ?string $creditOverrideReason = null, ?CollectNowData $collect = null): CheckInAndCollectResult
    {
        try {
            $stay = DB::transaction(function () use ($actor, $unit, $nationality, $withoutCard, $creditOverrideReason): UnitStayResult {
                if ($nationality !== null && ! $this->saveNationality($actor, $unit, $nationality)) {
                    throw new UnitStayRefused(UnitStayResult::refused(UnitStayResult::REFUSAL_NATIONALITY_REQUIRED));
                }

                $result = ($this->checkInUnit)($actor, $unit, $withoutCard, $creditOverrideReason);

                if ($result->isRefused()) {
                    throw new UnitStayRefused($result);
                }

                return $result;
            });
        } catch (UnitStayRefused $refused) {
            return new CheckInAndCollectResult($refused->result);
        }

        return $collect === null ? new CheckInAndCollectResult($stay) : $this->collect($actor, $stay, $collect);
    }

    private function collect(User $actor, UnitStayResult $stay, CollectNowData $collect): CheckInAndCollectResult
    {
        $unit = $stay->unit;
        $offer = $this->checkInCollection->for($unit);
        $payments = [];
        $amounts = [
            'taxes' => $collect->taxes && ! $offer->taxesHidden && BigDecimal::of($offer->taxesTotal)->isPositive() ? $offer->taxesTotal : null,
            'deposit' => $collect->deposit ? $offer->deposit : null,
        ];

        foreach ($amounts as $part => $amount) {
            if ($amount === null) {
                continue;
            }

            $result = ($this->recordPayment)($actor, $unit->reservation, null, $unit, new NewPaymentData(
                $collect->paymentMethodId,
                $part === 'taxes' ? PaymentPurpose::Advance : PaymentPurpose::SecurityDeposit,
                $amount,
                $collect->reference,
                $collect->idempotencyKey.':'.$part,
            ));

            if ($result->isRefused()) {
                return new CheckInAndCollectResult($stay, $payments, $result->refusal);
            }

            $payments[] = $result->payment;
        }

        return new CheckInAndCollectResult($stay, array_values(array_filter($payments, fn (?Payment $payment): bool => $payment !== null)));
    }

    private function saveNationality(User $actor, ReservationUnit $unit, string $nationality): bool
    {
        if ($this->unitNationality->for($unit) !== null) {
            return true;
        }

        $primary = $unit->guests()->where('is_primary', true)->with('guest')->first();
        $guest = $primary === null ? $unit->reservation->booker : $primary->guest;

        return $guest instanceof Guest
            && ($this->updateGuest)($actor, $guest, ['nationality_code' => $nationality], $guest->tags()->pluck('name')->all());
    }
}
