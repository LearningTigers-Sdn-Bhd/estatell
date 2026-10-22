<?php

namespace App\Actions\Onboarding\Wastays;

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostAdjustment;
use App\Actions\Folio\ReverseLine;
use App\Actions\FrontDesk\CancelUnit;
use App\Actions\FrontDesk\ChangeUnitDates;
use App\Actions\FrontDesk\CheckOutAndRefund;
use App\Actions\FrontDesk\PostUnitPolicyFee;
use App\Actions\FrontDesk\UndoCheckIn;
use App\Actions\FrontDesk\UndoCheckOut;
use App\Actions\Payments\PaymentResult;
use App\Actions\Payments\RefundFolioCredit;
use App\Actions\Payments\RefundPayment;
use App\Actions\Payments\VoidPayment;
use App\Actions\Reservations\ChangeNightPrice;
use App\Enums\DocumentType;
use App\Enums\FolioType;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Enums\WastaysEventType;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\UnitNight;
use App\Services\Onboarding\Wastays\ImportLinks;
use App\Services\Onboarding\Wastays\PlannedNumberRunner;
use App\Services\Onboarding\Wastays\WastaysEvent;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Replays the corrections of a wastays day through the estatell Actions: price repairs, reversals and voids of payments,
 * adjustments, refunds, stay date changes, cancels and voids of bookings, undone check-outs and early check-outs. Each
 * row written gets an `import_links` row. `ReplayWastaysDay` calls it for those event types. A refusal stops the day
 * with `ReplayStopped`. See docs/migration-plan/grand-eastern-hotel.md "Day loop" and "Mapping".
 */
class ReplayWastaysCorrections
{
    public function __construct(
        private ChangeNightPrice $changeNightPrice,
        private ReverseLine $reverseLine,
        private VoidPayment $voidPayment,
        private RefundPayment $refundPayment,
        private RefundFolioCredit $refundFolioCredit,
        private PostAdjustment $postAdjustment,
        private CreateFolio $createFolio,
        private CancelUnit $cancelUnit,
        private UndoCheckIn $undoCheckIn,
        private UndoCheckOut $undoCheckOut,
        private ChangeUnitDates $changeUnitDates,
        private PostUnitPolicyFee $postUnitPolicyFee,
        private CheckOutAndRefund $checkOutAndRefund,
        private RecordImportLink $recordImportLink,
        private ImportLinks $links,
        private PlannedNumberRunner $numbers,
    ) {}

    /**
     * @throws ReplayStopped
     */
    public function __invoke(ReplayContext $context, WastaysEvent $event): void
    {
        match ($event->type) {
            WastaysEventType::Repair => $this->repair($context, $event),
            WastaysEventType::Reversal => $this->reversal($context, $event),
            WastaysEventType::Adjustment => $this->adjustment($context, $event),
            WastaysEventType::Refund => $this->refund($context, $event),
            WastaysEventType::StayChange => $this->stayChange($context, $event),
            WastaysEventType::EarlyDeparture => $this->earlyDeparture($context, $event),
            default => throw new ReplayStopped("{$event->sourceTable} {$event->sourceId}: {$event->type->value} is not a correction."),
        };
    }

    private function repair(ReplayContext $context, WastaysEvent $event): void
    {
        $unit = $this->unitOf($context, $event);
        $date = substr((string) $event->row['posting_date'], 0, 10);
        $night = $unit->nights()->whereDate('date', $date)->first();

        if ($night === null) {
            throw new ReplayStopped("folio_transactions {$event->sourceId}: the unit has no night on {$date}.");
        }

        $result = ($this->changeNightPrice)($context->actor, $unit, [$night], $this->amount($event->row['amount']), 'wastays replay: price repair');

        if ($result->isRefused()) {
            throw new ReplayStopped("folio_transactions {$event->sourceId}: the price repair was refused ({$result->refusal}).");
        }

        $this->link($context, $event, $unit);
        $context->summary->count('price repairs');
    }

    private function reversal(ReplayContext $context, WastaysEvent $event): void
    {
        $row = $event->row;

        if ((json_decode((string) $row['metadata'], true)['posting_source'] ?? null) === 'historical_nightly_charge_repair') {
            $context->summary->count('reversals done by the price repair');

            return;
        }

        $original = $this->links->target($context->property, 'folio_transactions', (string) $row['reversal_of_transaction_id']);

        if ($original instanceof Payment) {
            $this->reversePayment($context, $event, $original);

            return;
        }

        if (! $original instanceof FolioLine) {
            throw new ReplayStopped("folio_transactions {$event->sourceId}: the reversed line {$row['reversal_of_transaction_id']} is not in estatell.");
        }

        $result = ($this->reverseLine)($context->actor, $original);

        if ($result->isRefused()) {
            throw new ReplayStopped("folio_transactions {$event->sourceId}: the reversal was refused ({$result->refusal}).");
        }

        $this->link($context, $event, $result->line());
        $context->summary->count('lines reversed');
    }

    private function reversePayment(ReplayContext $context, WastaysEvent $event, Payment $payment): void
    {
        $key = 'wastays:folio_transactions:'.$event->sourceId;
        $result = ($this->voidPayment)($context->actor, $payment, $key);

        if ($result->refusal === PaymentResult::REFUSAL_VOID_NOT_SAME_DAY) {
            $result = ($this->refundPayment)($context->actor, $payment, (string) $payment->amount, null, $key);
        }

        if ($result->isRefused()) {
            throw new ReplayStopped("folio_transactions {$event->sourceId}: the payment reversal was refused ({$result->refusal}).");
        }

        $this->link($context, $event, $result->payment);
        $context->summary->count('payments voided or refunded');
    }

    private function adjustment(ReplayContext $context, WastaysEvent $event): void
    {
        $unit = $this->unitOf($context, $event);
        $folio = $this->unitFolio($unit) ?? ($this->createFolio)($unit->reservation, $unit);
        $result = ($this->postAdjustment)($context->actor, $folio, (string) $event->row['amount'], (string) ($event->row['description'] ?? ''), 'wastays:folio_transactions:'.$event->sourceId);

        if ($result->isRefused()) {
            throw new ReplayStopped("folio_transactions {$event->sourceId}: the adjustment was refused ({$result->refusal}).");
        }

        $this->link($context, $event, $result->line());
        $context->summary->count('adjustments posted');
    }

    private function refund(ReplayContext $context, WastaysEvent $event): void
    {
        $unit = $this->unitOf($context, $event);
        $folio = $this->unitFolio($unit) ?? throw new ReplayStopped("folio_transactions {$event->sourceId}: the booking has no folio to refund from.");
        $result = ($this->refundFolioCredit)($context->actor, $folio, $this->amount($event->row['amount']), null, 'wastays replay: refund', 'wastays:folio_transactions:'.$event->sourceId);

        if ($result->isRefused()) {
            throw new ReplayStopped("folio_transactions {$event->sourceId}: the refund was refused ({$result->refusal}).");
        }

        $this->link($context, $event, $result->payment);
        $context->summary->count('refunds given');
    }

    private function stayChange(ReplayContext $context, WastaysEvent $event): void
    {
        $unit = $this->unitOf($context, $event);
        $action = (string) $event->row['action_type'];
        $reason = 'wastays replay: '.$action;

        if ($action === 'undo_check_out') {
            if ($unit->status === UnitStatus::CheckedOut) {
                $this->done($event, ($this->undoCheckOut)($context->actor, $unit, $reason), 'the check-out could not be undone');
                $context->summary->count('check-outs undone');
            }
        } elseif ($action === 'cancel' || $action === 'void') {
            $this->cancel($context, $event, $unit, $reason);
        } else {
            $this->changeDates($context, $event, $unit, $reason);
        }

        $this->link($context, $event, $unit);
    }

    private function cancel(ReplayContext $context, WastaysEvent $event, ReservationUnit $unit, string $reason): void
    {
        if ($unit->status === UnitStatus::CheckedIn) {
            $this->done($event, ($this->undoCheckIn)($context->actor, $unit, $reason), 'the check-in could not be undone');
        }

        if ($unit->refresh()->status !== UnitStatus::Confirmed) {
            return;
        }

        $this->done($event, ($this->cancelUnit)($context->actor, $unit, true, $reason), 'the booking could not be cancelled');
        $context->summary->count('bookings cancelled or voided');
    }

    private function changeDates(ReplayContext $context, WastaysEvent $event, ReservationUnit $unit, string $reason): void
    {
        $new = json_decode((string) $event->row['new_value'], true) ?: [];
        $old = json_decode((string) $event->row['old_value'], true) ?: [];
        $arrival = substr((string) ($new['check_in'] ?? $unit->arrival_date->toDateString()), 0, 10);
        $departure = substr((string) ($new['check_out'] ?? $unit->departure_date->toDateString()), 0, 10);

        if (($old['check_in'] ?? null) === ($new['check_in'] ?? null) && ($old['check_out'] ?? null) === ($new['check_out'] ?? null)) {
            $context->summary->count('room type changes left out');

            return;
        }

        if ($context->index->hasEarlyDeparture((string) $event->bookingId) && $departure < $unit->departure_date->toDateString()) {
            $context->summary->count('early departure dates left to the early check-out');

            return;
        }

        if ($unit->status === UnitStatus::CheckedIn) {
            $arrival = $unit->arrival_date->toDateString();
        }

        $before = $unit->nights()->pluck('date')->map(fn ($date) => $date->toDateString())->all();
        $result = ($this->changeUnitDates)($context->actor, $unit, CarbonImmutable::parse($arrival), CarbonImmutable::parse($departure), true, $reason);
        $this->done($event, $result, 'the stay dates could not be changed');

        $prices = $context->index->nights((string) $event->bookingId);
        $added = $unit->refresh()->nights()->get()->filter(fn (UnitNight $night): bool => ! in_array($night->date->toDateString(), $before, true) && isset($prices[$night->date->toDateString()]));

        foreach ($added->groupBy(fn (UnitNight $night): string => number_format((float) $prices[$night->date->toDateString()], 2, '.', '')) as $price => $nights) {
            $priced = ($this->changeNightPrice)($context->actor, $unit, $nights->all(), (string) $price, $reason);
            $this->done($event, $priced, 'the price of the added nights could not be set');
        }

        $context->summary->count('stay dates changed');
    }

    private function earlyDeparture(ReplayContext $context, WastaysEvent $event): void
    {
        $bookingId = (string) $event->bookingId;
        $unit = $this->unitOf($context, $event);

        if ($context->index->sameDayLeaver($bookingId)) {
            $context->summary->count('same-day leavers kept as one night');

            return;
        }

        if ($unit->status !== UnitStatus::CheckedIn) {
            throw new ReplayStopped("folio_transactions {$event->sourceId}: the early departure is for a unit that is not checked in.");
        }

        $policy = RoomRevenuePolicy::query()->where('property_id', $context->property->id)->where('code', SystemTransactionCode::EarlyDepartureRevenue)->first()
            ?? throw new ReplayStopped("folio_transactions {$event->sourceId}: the property has no early departure policy.");
        $today = CarbonImmutable::parse($context->date);
        $lines = $context->index->earlyDepartureLines($bookingId);

        if ($unit->departure_date->gt($today)) {
            $this->done($event, ($this->changeUnitDates)($context->actor, $unit, $unit->arrival_date, $today, true, 'wastays replay: early departure'), 'the departure could not be moved');
        }

        $fee = $this->amount($event->row['amount']);
        $currency = $context->property->settings->currency->getCurrencyCode();
        $posted = ($this->postUnitPolicyFee)($context->actor, $unit->refresh(), $policy, Money::of($fee, $currency), __('Early departure fee'), 'early_departure', $context->date);

        if ($posted->isRefused()) {
            throw new ReplayStopped("folio_transactions {$event->sourceId}: the early departure fee was refused ({$posted->refusal}).");
        }

        // wastays posts one fee line for each unused night; the guest leaves after the last of them.
        if ((string) $event->sourceId !== end($lines)) {
            $this->link($context, $event, $posted->line());

            return;
        }

        $invoice = $context->numbers->invoice($bookingId);
        $checkedOut = $this->numbers->run(DocumentType::Invoice, $invoice, fn () => ($this->checkOutAndRefund)($context->actor, $unit->refresh()), $invoice !== null, "bookings {$bookingId}");
        $this->done($event, $checkedOut, 'the early check-out was refused');

        $this->link($context, $event, $posted->line());
        $context->summary->count('early check-outs');
    }

    /**
     * @param  object|null  $result  an Action result with `isRefused()` and `refusal`
     *
     * @throws ReplayStopped
     */
    private function done(WastaysEvent $event, ?object $result, string $what): void
    {
        if ($result !== null && $result->isRefused()) {
            throw new ReplayStopped("{$event->sourceTable} {$event->sourceId}: {$what} ({$result->refusal}).");
        }
    }

    private function link(ReplayContext $context, WastaysEvent $event, Model $target): void
    {
        ($this->recordImportLink)($context->property, $event->sourceTable, $event->sourceId, $target);
    }

    private function unitOf(ReplayContext $context, WastaysEvent $event): ReservationUnit
    {
        $unit = $this->links->target($context->property, 'bookings', (string) $event->bookingId);

        if (! $unit instanceof ReservationUnit) {
            throw new ReplayStopped("{$event->sourceTable} {$event->sourceId}: booking {$event->bookingId} is not in estatell.");
        }

        return $unit->load('reservation');
    }

    private function unitFolio(ReservationUnit $unit): ?Folio
    {
        return Folio::query()->where('reservation_unit_id', $unit->id)->where('type', FolioType::Unit)->oldest('id')->first();
    }

    private function amount(?string $value): string
    {
        return number_format(abs((float) $value), 2, '.', '');
    }
}
