<?php

namespace App\Actions\FrontDesk;

use App\Actions\Guests\UpdateGuest;
use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Data\Reservations\CheckInCollectionData;
use App\Data\Reservations\CollectNowData;
use App\Enums\PaymentPurpose;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Folio\FolioRouter;
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
        private FolioRouter $folioRouter,
    ) {}

    /**
     * The check-in form: save a missing nationality, check the unit in with `CheckInUnit`, then take the Collect now
     * payments. The nationality goes to the unit's primary guest, or to the booker when the unit has none, the person
     * `UnitNationality` reads. Collect now runs after the check-in: the taxes flagged **Collect at check-in** as an
     * Advance payment, then the security deposit, both on the unit folio with the same method and reference. With
     * `roomCharges` the unit's full bill is recorded as one Advance instead of the taxes (none when the reservation has a
     * company and any billing mode but Each unit pays), on the Master folio when the unit's room charges go there,
     * so it never meets `advance_on_master`. The amounts are worked out here by `CheckInCollection`, never read from
     * the form. Every step is one transaction: a refused check-in or a refused payment saves nothing, so staff fix the field and submit the same form again.
     * Permissions are checked by the route and the Form Request.
     * See docs/18-front-desk/01-check-in-and-check-out.md#collect-now.
     */
    public function __invoke(User $actor, ReservationUnit $unit, ?string $nationality = null, bool $withoutCard = false, ?string $creditOverrideReason = null, ?CollectNowData $collect = null): CheckInAndCollectResult
    {
        try {
            return DB::transaction(function () use ($actor, $unit, $nationality, $withoutCard, $creditOverrideReason, $collect): CheckInAndCollectResult {
                if ($nationality !== null && ! $this->saveNationality($actor, $unit, $nationality)) {
                    throw new UnitStayRefused(UnitStayResult::refused(UnitStayResult::REFUSAL_NATIONALITY_REQUIRED));
                }

                $billOffer = $collect?->roomCharges ? $this->checkInCollection->for($unit) : null;
                $stay = ($this->checkInUnit)($actor, $unit, $withoutCard, $creditOverrideReason);

                if ($stay->isRefused()) {
                    throw new UnitStayRefused($stay);
                }

                $result = $collect === null ? new CheckInAndCollectResult($stay) : $this->collect($actor, $stay, $collect, $billOffer);

                if ($result->paymentRefusal !== null) {
                    throw new CollectNowRefused($result);
                }

                return $result;
            });
        } catch (UnitStayRefused $refused) {
            return new CheckInAndCollectResult($refused->result);
        } catch (CollectNowRefused $refused) {
            return new CheckInAndCollectResult($refused->result->stay, [], $refused->result->paymentRefusal);
        }
    }

    /**
     * `$billOffer` is the offer read before the check-in, because the check-in posts the undated add-ons and a Scheduled
     * bill read afterwards would leave them out. It is null unless `roomCharges` is set.
     */
    private function collect(User $actor, UnitStayResult $stay, CollectNowData $collect, ?CheckInCollectionData $billOffer): CheckInAndCollectResult
    {
        $unit = $stay->unit;
        $offer = $this->checkInCollection->for($unit);
        $bill = $billOffer !== null && ! $billOffer->billHidden;
        $payments = [];
        $amounts = [
            'bill' => $bill && BigDecimal::of($billOffer->billTotal)->isPositive() ? $billOffer->billTotal : null,
            'taxes' => ! $bill && $collect->taxes && ! $offer->taxesHidden && BigDecimal::of($offer->taxesTotal)->isPositive() ? $offer->taxesTotal : null,
            'deposit' => $collect->deposit ? $offer->deposit : null,
        ];

        foreach ($amounts as $part => $amount) {
            if ($amount === null) {
                continue;
            }

            $onMaster = $part === 'bill' ? $this->folioRouter->roomChargesMasterFolio($unit) : null;
            $result = ($this->recordPayment)($actor, $unit->reservation, $onMaster, $onMaster === null ? $unit : null, new NewPaymentData(
                $collect->paymentMethodId,
                $part === 'deposit' ? PaymentPurpose::SecurityDeposit : PaymentPurpose::Advance,
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
            && ($this->updateGuest)($actor, $guest, ['nationality_code' => $nationality], array_values($guest->tags()->pluck('name')->all()));
    }
}
