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
use Carbon\CarbonImmutable;
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
     * `UnitNationality` reads. Collect now runs after the check-in: the taxes flagged **Collect at check-in** (only the ticked ones, `taxIds`) as an
     * Advance payment, then the security deposit, both on the unit folio with the same method and reference. With
     * `roomCharges` the unit's full bill is recorded as one Advance instead of the taxes (none when the reservation has a
     * company and any billing mode but Each unit pays), on the Master folio when the unit's room charges go there,
     * so it never meets `advance_on_master`. The amounts are worked out here by `CheckInCollection`, never read from
     * the form. A tax ticked in `masterTaxIds` on a Master no company pays (`payableOnMaster`) is recorded as an Advance
     * on that Master, keyed `{uuid}:master_taxes`. With `chargeCodeIds` the ticked unit-folio charges (read before the check-in, as the
     * bill is) are recorded as one Advance on the unit folio (`{uuid}:charges`) and, for a Room on a Master no company
     * pays, one on that Master (`{uuid}:master_charges`), each never more than its folio still owes. A ticked Room that
     * holds the collected taxes skips the tax payments. The full bill already holds every charge, so with it the
     * ticked charges are ignored and nothing is taken twice. Every step is one transaction: a refused check-in or a refused payment saves nothing, so staff fix the field and submit the same form again.
     * `$asOf` is passed to `CheckInUnit`: a walk-in day use that started earlier today checks in at its start. So is `$stayNight`, see `CheckInEndedStay`.
     * Permissions are checked by the route and the Form Request.
     * See docs/18-front-desk/01-check-in-and-check-out.md#collect-now.
     */
    public function __invoke(User $actor, ReservationUnit $unit, ?string $nationality = null, bool $withoutCard = false, ?string $creditOverrideReason = null, ?CollectNowData $collect = null, ?CarbonImmutable $asOf = null, ?CarbonImmutable $stayNight = null): CheckInAndCollectResult
    {
        try {
            return DB::transaction(function () use ($actor, $unit, $nationality, $withoutCard, $creditOverrideReason, $collect, $asOf, $stayNight): CheckInAndCollectResult {
                if ($nationality !== null && ! $this->saveNationality($actor, $unit, $nationality)) {
                    throw new UnitStayRefused(UnitStayResult::refused(UnitStayResult::REFUSAL_NATIONALITY_REQUIRED));
                }

                $billOffer = $collect !== null && ($collect->roomCharges || $collect->chargeCodeIds !== []) ? $this->checkInCollection->for($unit) : null;
                $stay = ($this->checkInUnit)($actor, $unit, $withoutCard, $creditOverrideReason, $asOf, $stayNight);

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
     * bill read afterwards would leave them out. It is null unless `roomCharges` or `chargeCodeIds` is set.
     */
    private function collect(User $actor, UnitStayResult $stay, CollectNowData $collect, ?CheckInCollectionData $billOffer): CheckInAndCollectResult
    {
        $unit = $stay->unit;
        $offer = $this->checkInCollection->for($unit);
        $bill = $collect->roomCharges && $billOffer !== null && ! $billOffer->billHidden;
        $ticked = $bill || $billOffer === null ? [] : $collect->chargeCodeIds;
        $charges = $ticked !== [] ? $billOffer->chargesFor($ticked) : null;
        $masterCharges = $ticked !== [] ? $billOffer->masterChargesFor($ticked) : null;
        // Ticked room nights already hold the collected taxes, so they are not taken twice.
        $holdsStay = $ticked !== [] && $billOffer->includesStay($ticked);
        $payments = [];
        $amounts = [
            'bill' => $bill && BigDecimal::of($billOffer->billTotal)->isPositive() ? $billOffer->billTotal : null,
            'charges' => $charges !== null && BigDecimal::of($charges)->isPositive() ? $charges : null,
            'master_charges' => $masterCharges !== null && BigDecimal::of($masterCharges)->isPositive() ? $masterCharges : null,
            'taxes' => ! $bill && ! $holdsStay && $collect->taxes && BigDecimal::of($offer->taxesFor($collect->taxIds))->isPositive() ? $offer->taxesFor($collect->taxIds) : null,
            'master_taxes' => ! $bill && ! $holdsStay && $collect->taxes && BigDecimal::of($offer->masterTaxesFor($collect->masterTaxIds))->isPositive() ? $offer->masterTaxesFor($collect->masterTaxIds) : null,
            'deposit' => $collect->deposit ? $offer->deposit : null,
        ];

        foreach ($amounts as $part => $amount) {
            if ($amount === null) {
                continue;
            }

            $onMaster = in_array($part, ['bill', 'master_taxes', 'master_charges'], true) ? $this->folioRouter->roomChargesMasterFolio($unit) : null;
            $result = ($this->recordPayment)($actor, $unit->reservation, $onMaster, $onMaster === null ? $unit : null, new NewPaymentData(
                $collect->paymentMethodId,
                $part === 'deposit' ? PaymentPurpose::SecurityDeposit : PaymentPurpose::Advance,
                $amount,
                $collect->reference,
                $collect->idempotencyKey.':'.$part,
                guestPaysTaxes: $part === 'taxes',
                paysUnitFolioCharges: $part === 'charges',
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
