<?php

namespace App\Actions\Channels;

use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\ChannelAlertEvent;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\UnitStatus;
use App\Models\ChannelBookingRevision;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Channels\ChannelRevisionMapper;
use App\Services\Folio\FolioRouter;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

class PostOtaCollectedPayment
{
    public function __construct(
        private ChannelRevisionMapper $mapper,
        private FolioRouter $folioRouter,
        private RecordPayment $recordPayment,
        private RecordChannelAlert $recordChannelAlert,
    ) {}

    /**
     * Post the money the guest paid the OTA (`payment_collect` = `ota`) as OTA collected payments, so the folio shows
     * the booking paid. Only what is still due is posted: the booking amount minus the OTA collected money already on
     * the reservation, net of refunds. So a payment staff recorded by hand is not posted twice, and a Modified booking
     * posts only a higher amount. With nothing collected yet, each room's amount goes to its unit, in order, when the
     * revision has one amount per live unit, and the rest goes to the first unit. Otherwise the first unit takes it
     * all. A unit's money goes to the folio that takes its Room charges: the Master folio, or the unit's own folio.
     * Payments to the same folio are posted as one, with the purpose Advance and the key
     * `ota-collected:{revision_id}:{n}`. A refused payment does not refuse the booking: it opens an
     * `OtaPaymentNotPosted` alert, and staff record the money by hand. See docs/21-channels/05-booking-ingestion.md.
     */
    public function __invoke(User $actor, Reservation $reservation, ChannelBookingRevision $revision): void
    {
        $otaPayment = $this->mapper->otaPayment($revision->payload);

        if ($otaPayment === null) {
            return;
        }

        $collected = $this->collected($reservation);
        $due = BigDecimal::of($otaPayment['amount'])->minus($collected);
        $units = $reservation->units()->whereIn('status', [UnitStatus::Confirmed, UnitStatus::CheckedIn])->orderBy('id')->get()->values();

        if (! $due->isPositive() || $units->isEmpty()) {
            return;
        }

        $method = PaymentMethod::query()
            ->where('property_id', $reservation->property_id)
            ->where('type', PaymentMethodType::OtaCollected)
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if ($method === null) {
            $this->alert($revision, 'method_not_available', (string) $due->toScale(2));

            return;
        }

        $shares = $collected->isZero() ? $this->shares($units, $otaPayment['roomAmounts'], $due) : [[$units->first(), $due]];

        foreach (array_values($this->byFolio($shares)) as $index => [$unit, $folio, $amount]) {
            $key = "ota-collected:{$revision->revision_id}:{$index}";
            $result = ($this->recordPayment)($actor, $reservation, $folio, $folio === null ? $unit : null, new NewPaymentData(
                paymentMethodId: $method->id,
                purpose: PaymentPurpose::Advance,
                amount: (string) $amount->toScale(2),
                reference: $otaPayment['reference'],
                idempotencyKey: $key,
                lineKey: $key,
                allowOtaCollected: true,
            ));

            if ($result->isRefused()) {
                $this->alert($revision, (string) $result->refusal, (string) $amount->toScale(2));
            }
        }
    }

    /**
     * The OTA collected money on the reservation: its Completed payments minus their refunds.
     */
    private function collected(Reservation $reservation): BigDecimal
    {
        $payments = Payment::query()
            ->whereHas('folio', fn ($folios) => $folios->where('reservation_id', $reservation->id))
            ->whereHas('paymentMethod', fn ($methods) => $methods->where('type', PaymentMethodType::OtaCollected))
            ->whereNull('refund_of_id')
            ->where('status', PaymentStatus::Completed);
        $paid = (string) (clone $payments)->sum('amount');
        $refunded = (string) Payment::query()
            ->whereIn('refund_of_id', (clone $payments)->select('id'))
            ->where('status', PaymentStatus::Completed)
            ->sum('amount');

        return BigDecimal::of($paid)->minus($refunded);
    }

    /**
     * The amount each unit takes: its room's amount when there is one amount per unit and they fit in what is due,
     * with the rest on the first unit; otherwise all of it on the first unit.
     *
     * @param  Collection<int, ReservationUnit>  $units
     * @param  list<string|null>  $roomAmounts
     * @return list<array{0: ReservationUnit, 1: BigDecimal}>
     */
    private function shares(Collection $units, array $roomAmounts, BigDecimal $due): array
    {
        $fits = count($roomAmounts) === $units->count()
            && ! in_array(null, $roomAmounts, true)
            && BigDecimal::sum(...array_map(fn (string $amount): BigDecimal => BigDecimal::of($amount), $roomAmounts))->isLessThanOrEqualTo($due);

        if (! $fits) {
            return [[$units->first(), $due]];
        }

        $shares = [];

        foreach ($units as $index => $unit) {
            $shares[] = [$unit, BigDecimal::of((string) $roomAmounts[$index])];
        }

        $rest = $due->minus(BigDecimal::sum(...array_map(fn (array $share): BigDecimal => $share[1], $shares)));
        $shares[0][1] = $shares[0][1]->plus($rest);

        return $shares;
    }

    /**
     * The shares put together by the folio that takes each unit's Room charges: the Master folio, or null for the
     * unit's own folio (which `RecordPayment` creates when the unit has none yet). Shares of 0 are dropped.
     *
     * @param  list<array{0: ReservationUnit, 1: BigDecimal}>  $shares
     * @return array<string, array{0: ReservationUnit, 1: Folio|null, 2: BigDecimal}>
     */
    private function byFolio(array $shares): array
    {
        $payments = [];

        foreach ($shares as [$unit, $amount]) {
            if (! $amount->isPositive()) {
                continue;
            }

            $master = $this->folioRouter->roomChargesMasterFolio($unit);
            $target = $master === null ? 'unit:'.$unit->id : 'folio:'.$master->id;
            $payments[$target] = isset($payments[$target])
                ? [$payments[$target][0], $master, $payments[$target][2]->plus($amount)]
                : [$unit, $master, $amount];
        }

        return $payments;
    }

    private function alert(ChannelBookingRevision $revision, string $refusal, string $amount): void
    {
        ($this->recordChannelAlert)($revision->channelProperty, ChannelAlertEvent::OtaPaymentNotPosted, [
            'booking_id' => $revision->external_booking_id,
            'message' => __(':amount was not posted (:code). Record it with Record payment.', ['amount' => $amount, 'code' => $refusal]),
        ]);
    }
}
