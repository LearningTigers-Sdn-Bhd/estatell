<?php

namespace App\Actions\Onboarding\Wastays;

use App\Actions\Catalogues\UpdateTax;
use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\ReopenFolio;
use App\Actions\FrontDesk\AutoAssignRooms;
use App\Actions\FrontDesk\CheckInUnit;
use App\Actions\FrontDesk\CheckOutUnit;
use App\Actions\FrontDesk\UndoCheckIn;
use App\Actions\NightAudit\RunNightAudit;
use App\Actions\Payments\RecordPayment;
use App\Actions\Reservations\AssignRoom;
use App\Actions\Reservations\ChangeNightPrice;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Reservations\CreateBookingSource;
use App\Actions\Reservations\EnsureOtaBookingSource;
use App\Actions\Reservations\ReservationResult;
use App\Data\Catalogues\TaxInputData;
use App\Data\Folio\NewChargeData;
use App\Data\Payments\NewPaymentData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Enums\AuditTrigger;
use App\Enums\BillingMode;
use App\Enums\BookingSourceType;
use App\Enums\CompanyAccess;
use App\Enums\DocumentType;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Enums\WastaysEventType;
use App\Models\BookingSource;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\ImportLink;
use App\Models\OtaChannel;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\Onboarding\Wastays\ImportLinks;
use App\Services\Onboarding\Wastays\PlannedNumberRunner;
use App\Services\Onboarding\Wastays\WastaysBookingIndex;
use App\Services\Onboarding\Wastays\WastaysDay;
use App\Services\Onboarding\Wastays\WastaysDocumentNumbers;
use App\Services\Onboarding\Wastays\WastaysEvent;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Replays one wastays business date through the estatell Actions, in one transaction: the events of the day in time order
 * (`WastaysDay::timeline()`), each written once and linked in `import_links`, so a second run writes nothing. The clock is
 * frozen at 12:00 property time on the date, the queue is `null` and mail is `array`, so no message goes out. Wastays
 * document numbers (reservation, receipt, invoice) are queued on `PlannedDocumentNumbers` just before the Action that
 * issues them. This Action replays bookings, check-ins, charges, payments, deposits and check-outs, hands the corrections to
 * `ReplayWastaysCorrections`, and runs the night audit last, which opens the next date. A day whose wastays night audit
 * is already linked is skipped, so a second run writes nothing. Any refusal stops the day with `ReplayStopped` and writes nothing.
 * See docs/migration-plan/grand-eastern-hotel.md "Day loop".
 */
class ReplayWastaysDay
{
    private const string WAITING_CHARGES = 'waiting_charges';

    private const string DEPOSIT_RECEIPTS = 'deposit_receipts';

    public function __construct(
        private ReopenFolio $reopenFolio,
        private CloseFolio $closeFolio,
        private FolioBalance $folioBalance,
        private UpdateTax $updateTax,
        private CreateBooking $createBooking,
        private AssignRoom $assignRoom,
        private AutoAssignRooms $autoAssignRooms,
        private ChangeNightPrice $changeNightPrice,
        private CheckInUnit $checkInUnit,
        private UndoCheckIn $undoCheckIn,
        private CheckOutUnit $checkOutUnit,
        private PostCharge $postCharge,
        private RecordPayment $recordPayment,
        private EnsureOtaBookingSource $ensureOtaBookingSource,
        private CreateBookingSource $createBookingSource,
        private RecordImportLink $recordImportLink,
        private ImportLinks $links,
        private PlannedNumberRunner $numbers,
        private ReplayWastaysCorrections $corrections,
        private RunNightAudit $runNightAudit,
    ) {}

    /**
     * @throws ReplayStopped when the day cannot be replayed; nothing of the day is kept
     */
    public function __invoke(WastaysImportTarget $target, WastaysDay $day, string $date): WastaysReplaySummary
    {
        $audit = $day->nightAudit($date);

        if ($audit !== null && $this->links->has($target->property, 'night_audits', (string) $audit['id'])) {
            $summary = new WastaysReplaySummary;
            $summary->count('day already replayed');

            return $summary;
        }

        $refusals = $day->refusals($target->property, $date);

        if ($refusals !== []) {
            throw new ReplayStopped(implode(' ', $refusals));
        }

        return $this->quietly($target->property, $date, function () use ($target, $day, $date): WastaysReplaySummary {
            return DB::transaction(fn (): WastaysReplaySummary => $this->replay(new ReplayContext(
                $target->property,
                $target->actor,
                $date,
                new WastaysBookingIndex($target->bundle),
                new WastaysDocumentNumbers($target->bundle),
                new WastaysReplaySummary,
            ), $day));
        });
    }

    private function replay(ReplayContext $context, WastaysDay $day): WastaysReplaySummary
    {
        $this->turnOnTourismTax($context, $day);
        $this->carryOver($context, $day);

        foreach ($day->timeline($context->date) as $event) {
            $written = in_array($event->sourceTable, ['folio_transactions', 'deposits'], true) || $event->type === WastaysEventType::StayChange;

            if ($written && $this->links->has($context->property, $event->sourceTable, $event->sourceId)) {
                $context->summary->count('already written');

                continue;
            }

            match ($event->type) {
                WastaysEventType::Create => $this->create($context, $event, $day),
                WastaysEventType::CheckIn => $this->checkIn($context, $event),
                WastaysEventType::Charge => $this->charge($context, $event),
                WastaysEventType::Payment => $this->payment($context, $event),
                WastaysEventType::Deposit => $this->deposit($context, $event),
                WastaysEventType::CheckOut => $this->checkOut($context, $event),
                default => ($this->corrections)($context, $event),
            };

            if ($event->type === WastaysEventType::StayChange) {
                $this->checkInMovedStay($context, $event);
            }
        }

        $this->closeDay($context, $day);

        return $context->summary;
    }

    /**
     * Run the night audit of the day at 00:05 on the next date, so the date has ended. It opens the next business date.
     */
    private function closeDay(ReplayContext $context, WastaysDay $day): void
    {
        $audit = $day->nightAudit($context->date);

        if ($audit === null) {
            $context->summary->note("{$context->date} has no wastays night audit. The day stays open.");

            return;
        }

        $this->closeReopenedFolios($context);

        $zone = $context->property->settings->time_zone ?? config('app.timezone');
        $at = CarbonImmutable::parse($context->date.' 00:05', $zone)->addDay();
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);

        $result = ($this->runNightAudit)($context->property, AuditTrigger::Manual, $context->actor);

        if ($result->isRefused() || ! $result->isCompleted()) {
            throw new ReplayStopped("night_audits {$audit['id']}: the night audit of {$context->date} did not complete (".($result->refusal ?? 'failed').').');
        }

        ($this->recordImportLink)($context->property, 'night_audits', (string) $audit['id'], $result->audit);
        $context->summary->count('night audits run');
    }

    /**
     * Check-ins and check-outs that wastays did before their day: a guest who checked in before the arrival date is
     * checked in on the arrival day, and a guest who left on the arrival day is checked out the next day.
     */
    /**
     * Turn the Tourism tax on at the start of the business date in which wastays first charged it by itself. wastays
     * turned its tourism tax on that day; before it, the tax stays off as the setup import left it.
     */
    private function turnOnTourismTax(ReplayContext $context, WastaysDay $day): void
    {
        $first = $context->index->firstTourismTaxAt();
        $closed = $day->window($context->date)['closed'] ?? null;

        if ($first === null || ($closed !== null && $first->gte($closed))) {
            return;
        }

        $tax = Tax::query()->where('property_id', $context->property->id)->whereHas('transactionCode', fn ($codes) => $codes->where('system_key', SystemTransactionCode::TourismTax->value))->first();

        if ($tax === null || $tax->enabled) {
            return;
        }

        ($this->updateTax)($context->actor, $tax, TaxInputData::fromModel($tax, enabled: true));
        $context->summary->count('tourism tax turned on');
    }

    private function carryOver(ReplayContext $context, WastaysDay $day): void
    {
        $opened = $day->window($context->date)['opened'] ?? null;

        if ($opened === null) {
            return;
        }

        $links = ImportLink::query()
            ->where('property_id', $context->property->id)
            ->where('source_table', 'bookings')
            ->where('target_type', (new ReservationUnit)->getMorphClass())
            ->pluck('source_id', 'target_id');
        $units = ReservationUnit::query()
            ->whereIn('id', $links->keys())
            ->where(fn ($query) => $query
                ->where(fn ($in) => $in->where('status', UnitStatus::Confirmed)->whereDate('arrival_date', $context->date))
                ->orWhere(fn ($out) => $out->where('status', UnitStatus::CheckedIn)->whereDate('departure_date', $context->date)))
            ->orderBy('id')
            ->get();

        foreach ($units as $unit) {
            $bookingId = (string) $links[$unit->id];

            if ($unit->status === UnitStatus::Confirmed) {
                $at = $context->index->firstCheckInAt($bookingId);

                if ($at !== null && $at->lt($opened)) {
                    $this->doCheckIn($context, $unit, 'check-in moved to the arrival day');
                }

                continue;
            }

            $at = $context->index->checkOutAt($bookingId);

            if ($at !== null && $at->lt($opened) && (! $context->index->hasEarlyDeparture($bookingId) || $context->index->sameDayLeaver($bookingId))) {
                $this->doCheckOut($context, $unit, $bookingId, 'check-out moved to the departure day');
            }
        }
    }

    private function create(ReplayContext $context, WastaysEvent $event, WastaysDay $day): void
    {
        $bookingId = $event->sourceId;

        if ($this->links->has($context->property, 'bookings', $bookingId)) {
            $context->summary->count('already written');

            return;
        }

        $property = $context->property;
        $booking = $context->index->booking($bookingId);
        $room = $context->index->room($bookingId);
        [$arrival, $departure] = $context->index->firstDates($bookingId, $context->date, $day);
        $nights = $this->nightsIn($context->index->nights($bookingId), $arrival, $departure);
        $uniform = count($nights) === $this->dayCount($arrival, $departure) && count(array_unique($nights)) === 1;
        $all = count($nights) === $this->dayCount($arrival, $departure);
        $roomType = $this->linked($property, 'room_types', (string) $room['room_type_id']);
        $plan = $this->linked($property, 'rate_plans', (string) $room['rate_plan_id']);

        $guestIds = array_map(fn (string $id): int => $this->linked($property, 'guests', $id)->getKey(), $context->index->guestIds($bookingId));

        if ($guestIds === []) {
            throw new ReplayStopped("bookings {$bookingId}: the booking has no guest.");
        }

        $line = new NewUnitData(
            roomTypeId: $roomType->getKey(),
            ratePlanId: $plan->getKey(),
            arrivalDate: CarbonImmutable::parse($arrival),
            departureDate: CarbonImmutable::parse($departure),
            adults: max(1, (int) $booking['adults']),
            childAges: array_fill(0, (int) ($booking['children'] ?? 0), 5),
            overrideTotal: $all ? number_format(array_sum(array_map('floatval', $nights)), 2, '.', '') : null,
            overrideReason: $all ? 'wastays replay' : null,
        );
        $data = new NewBookingData(
            bookingSource: $this->bookingSource($property, $context->actor, (string) $booking['source']),
            units: [$line],
            guests: array_map(fn (int $guestId): NewBookingGuestData => new NewBookingGuestData(guestId: $guestId, unitIndex: 0), $guestIds),
            externalReference: $booking['external_reference'] ?? $booking['channel_manager_reference'] ?? $booking['agent_reference'] ?? null,
            groupName: $context->index->groupName($bookingId),
            companyId: $companyId = $this->companyOf($property, $bookingId, $context->index->companyAccountId($bookingId)),
            billingMode: $companyId !== null ? BillingMode::MasterPaysAll : BillingMode::MasterPays,
        );
        $backdated = $arrival < $context->date;
        $number = (int) $booking['reservation_number'];
        $fromChannel = $companyId === null && $plan instanceof RatePlan && $plan->company_access !== CompanyAccess::Everyone;
        $book = fn (bool $skipChecks) => $this->numbers->run(DocumentType::Reservation, $number, fn () => ($this->createBooking)($context->actor, $property, $data, $skipChecks, $backdated), true, $bookingId);
        $result = $book($fromChannel);

        if ($result->isRefused() && $result->refusal === ReservationResult::REFUSAL_NOT_AVAILABLE) {
            // wastays let staff book more rooms of a type than it has. The replay keeps the booking, as an over-capacity stay.
            $result = $book(true);
            $context->summary->count('bookings over the room type capacity');
        }

        if ($result->isRefused()) {
            throw new ReplayStopped("bookings {$bookingId}: the booking was refused ({$result->refusal}) for {$arrival} to {$departure}.");
        }

        $unit = $result->reservation->units()->orderBy('id')->firstOrFail();
        ($this->recordImportLink)($property, 'bookings', $bookingId, $unit);
        $context->summary->count('bookings created');

        if (! $uniform) {
            $this->priceNights($context, $unit, $nights, $bookingId);
        }

        $this->assignRoom($context, $unit, (string) ($room['room_id'] ?? ''));

        if ($backdated && $context->index->hasCheckIn($bookingId)) {
            $this->doCheckIn($context, $unit, 'backdated booking checked in as of its arrival', CarbonImmutable::parse($arrival.' '.($property->settings->check_in_time ?? '15:00')));
        }
    }

    /**
     * The estatell company of a booking made through a wastays corporate account, from the companies import.
     */
    private function companyOf(Property $property, string $bookingId, ?string $accountId): ?int
    {
        if (blank($accountId)) {
            return null;
        }

        return $this->links->targetId($property, 'hotel_corporate_accounts', (string) $accountId)
            ?? throw new ReplayStopped("bookings {$bookingId}: the company {$accountId} is not in estatell. Import the companies first.");
    }

    /**
     * Set each group of nights with the same wastays price, through `ChangeNightPrice`.
     *
     * @param  array<string, string>  $nights  net prices by date
     */
    private function priceNights(ReplayContext $context, ReservationUnit $unit, array $nights, string $bookingId): void
    {
        $byPrice = [];

        foreach ($nights as $date => $price) {
            $byPrice[number_format((float) $price, 2, '.', '')][] = $date;
        }

        foreach ($byPrice as $price => $dates) {
            $ids = UnitNight::query()->where('reservation_unit_id', $unit->id)->whereIn('date', $dates)->pluck('id')->all();
            $result = ($this->changeNightPrice)($context->actor, $unit, array_values(UnitNight::query()->whereIn('id', $ids)->get()->all()), (string) $price, 'wastays replay');

            if ($result->isRefused()) {
                throw new ReplayStopped("bookings {$bookingId}: the night price was refused ({$result->refusal}).");
            }
        }
    }

    private function assignRoom(ReplayContext $context, ReservationUnit $unit, string $wastaysRoomId): void
    {
        $room = $wastaysRoomId === '' ? null : $this->links->target($context->property, 'rooms', $wastaysRoomId);

        if ($room instanceof Room && ! ($this->assignRoom)($context->actor, $unit, $room)->isRefused()) {
            return;
        }

        $context->summary->count('rooms to assign at check-in');
    }

    private function checkIn(ReplayContext $context, WastaysEvent $event): void
    {
        $unit = $this->unitOf($context, $event);
        $action = (string) $event->row['action_type'];

        if ($action === 'edit_check_in') {
            $context->summary->count('room edits at check-in left out');

            return;
        }

        if ($action === 'undo_check_in') {
            if ($unit->status !== UnitStatus::CheckedIn) {
                return;
            }

            $result = ($this->undoCheckIn)($context->actor, $unit, 'wastays replay: check-in undone');

            if ($result->isRefused()) {
                throw new ReplayStopped("booking_audit_logs {$event->sourceId}: the check-in could not be undone ({$result->refusal}).");
            }

            $context->summary->count('check-ins undone');
            ($this->recordImportLink)($context->property, 'booking_audit_logs', $event->sourceId, $unit);

            return;
        }

        if ($unit->status !== UnitStatus::Confirmed) {
            return;
        }

        if ($unit->arrival_date->toDateString() > $context->date) {
            $context->summary->count('check-ins before the arrival date moved');

            return;
        }

        $this->doCheckIn($context, $unit, 'check-ins');
        ($this->recordImportLink)($context->property, 'booking_audit_logs', $event->sourceId, $unit);
    }

    /**
     * wastays checked the guest in before the stay was moved to an earlier arrival, so the check-in was left out while
     * the arrival was still ahead. Check in now that the stay has arrived, as of the arrival when it is a past date.
     */
    private function checkInMovedStay(ReplayContext $context, WastaysEvent $event): void
    {
        $unit = $this->links->target($context->property, 'bookings', (string) $event->bookingId);
        $at = $context->index->firstCheckInAt((string) $event->bookingId);

        if (! $unit instanceof ReservationUnit || $at === null || $at->gt($event->at)) {
            return;
        }

        $unit->refresh();
        $arrival = $unit->arrival_date->toDateString();

        if ($unit->status !== UnitStatus::Confirmed || $arrival > $context->date) {
            return;
        }

        $asOf = $arrival < $context->date ? CarbonImmutable::parse($arrival.' '.($context->property->settings->check_in_time ?? '15:00')) : null;
        $this->doCheckIn($context, $unit->load('reservation'), 'check-ins after the stay moved earlier', $asOf);
    }

    private function doCheckIn(ReplayContext $context, ReservationUnit $unit, string $label, ?CarbonImmutable $asOf = null): void
    {
        $unit->refresh();
        $day = ($asOf ?? CarbonImmutable::parse($context->date))->toDateString();
        $tonight = $unit->nights()->whereDate('date', $day)->first();

        if ($tonight !== null && $tonight->room_id === null && $tonight->bed_id === null) {
            ($this->autoAssignRooms)($context->actor, $unit);
        }

        $result = ($this->checkInUnit)($context->actor, $unit, true, null, $asOf);

        if ($result->isRefused()) {
            throw new ReplayStopped("unit {$unit->id}: the check-in was refused ({$result->refusal}).");
        }

        $context->summary->count($label);
        $this->postWaitingCharges($context, $unit);
    }

    /**
     * wastays let staff post to a folio after the check-out and closed it later; estatell closed it at the check-out.
     * Reopen the unit folio for the line. The invoice number stays, and the folio closes again before the night audit.
     */
    private function reopenAfterCheckOut(ReplayContext $context, ReservationUnit $unit, WastaysEvent $event): void
    {
        if ($unit->status !== UnitStatus::CheckedOut) {
            return;
        }

        $folio = Folio::query()->where('reservation_unit_id', $unit->id)->where('type', FolioType::Unit)->where('status', FolioStatus::Closed)->orderBy('id')->first();

        if ($folio === null) {
            return;
        }

        $result = ($this->reopenFolio)($context->actor, $folio);

        if ($result->isRefused()) {
            throw new ReplayStopped("folio_transactions {$event->sourceId}: the folio could not be reopened for a line after the check-out ({$result->refusal}).");
        }

        $context->summary->count('folios reopened for lines after the check-out');
    }

    /**
     * Close again the unit folios of checked-out units that a line after the check-out reopened. Each has its invoice
     * number already, so no number is issued.
     */
    private function closeReopenedFolios(ReplayContext $context): void
    {
        $folios = Folio::query()
            ->where('property_id', $context->property->id)
            ->where('type', FolioType::Unit)
            ->where('status', FolioStatus::Open)
            ->whereNotNull('invoice_number')
            ->whereHas('reservationUnit', fn ($units) => $units->where('status', UnitStatus::CheckedOut))
            ->orderBy('id')
            ->get();

        foreach ($folios as $folio) {
            if (! BigDecimal::of($this->folioBalance->forFolio($folio))->isZero()) {
                throw new ReplayStopped("The folio {$folio->invoice_number} reopened after the check-out does not balance at the end of {$context->date}.");
            }

            $result = ($this->closeFolio)($context->actor, $folio);

            if ($result->isRefused()) {
                throw new ReplayStopped("The folio {$folio->invoice_number} reopened after the check-out could not close ({$result->refusal}).");
            }
        }
    }

    private function charge(ReplayContext $context, WastaysEvent $event): void
    {
        $row = $event->row;

        if ($row['category'] === 'accommodation') {
            throw new ReplayStopped("folio_transactions {$event->sourceId}: a room line the audit did not post.");
        }

        $unit = $this->unitOf($context, $event);

        if (! in_array($unit->status, [UnitStatus::CheckedIn, UnitStatus::CheckedOut], true)) {
            ($this->recordImportLink)($context->property, self::WAITING_CHARGES, $event->sourceId, $unit);
            $context->summary->count('charges waiting for check-in');

            return;
        }

        $this->reopenAfterCheckOut($context, $unit, $event);
        $this->postChargeLine($context, $unit, $row, $event->sourceId);
        $context->summary->count('charges posted');
    }

    /**
     * estatell posts a charge only on a unit that is checked in. A charge wastays took before the check-in waits for it
     * and is posted when the unit checks in, on the check-in day.
     */
    private function postWaitingCharges(ReplayContext $context, ReservationUnit $unit): void
    {
        $waiting = ImportLink::query()
            ->where('property_id', $context->property->id)
            ->where('source_table', self::WAITING_CHARGES)
            ->where('target_type', $unit->getMorphClass())
            ->where('target_id', $unit->id)
            ->orderBy('id')
            ->get();

        foreach ($waiting as $link) {
            $unit->refresh();
            $this->postChargeLine($context, $unit->load('reservation'), $context->index->folioRow($link->source_id), $link->source_id);
            $link->delete();
            $context->summary->count('charges moved to the check-in day');
        }
    }

    /**
     * @param  array<string, string|null>  $row  the wastays `folio_transactions` row
     */
    private function postChargeLine(ReplayContext $context, ReservationUnit $unit, array $row, string $sourceId): void
    {
        $code = $this->links->target($context->property, 'transaction_codes', (string) $row['transaction_code_id']);

        if (! $code instanceof ChargeItem && ! $code instanceof TransactionCode) {
            throw new ReplayStopped("folio_transactions {$sourceId}: the transaction code has no charge item or code in estatell.");
        }

        $result = ($this->postCharge)($context->actor, $unit, new NewChargeData(
            chargeItemId: $code instanceof ChargeItem ? $code->id : null,
            transactionCodeId: $code instanceof TransactionCode ? $code->id : null,
            description: $row['description'],
            quantity: 1,
            unitAmount: $this->amount($row['amount']),
            idempotencyKey: 'wastays:folio_transactions:'.$sourceId,
            ownFolio: ! $context->index->onCompanyFolio($row),
            taxIds: [],
        ));

        if ($result->isRefused()) {
            throw new ReplayStopped("folio_transactions {$sourceId}: the charge was refused ({$result->refusal}).");
        }

        ($this->recordImportLink)($context->property, 'folio_transactions', $sourceId, $result->line());
    }

    private function payment(ReplayContext $context, WastaysEvent $event): void
    {
        $row = $event->row;
        $unit = $this->unitOf($context, $event);
        $this->reopenAfterCheckOut($context, $unit, $event);
        $method = $this->links->target($context->property, 'transaction_codes', (string) $row['transaction_code_id']);

        if (! $method instanceof PaymentMethod) {
            throw new ReplayStopped("folio_transactions {$event->sourceId}: the transaction code has no payment method in estatell.");
        }

        $metadata = json_decode((string) $row['metadata'], true) ?: [];
        $depositId = isset($metadata['deposit_id']) ? (string) $metadata['deposit_id'] : null;
        $useDepositReceipt = $depositId !== null && ! $this->links->has($context->property, self::DEPOSIT_RECEIPTS, $depositId);
        $reference = $metadata['reference'] ?? null;
        $reference = filled($reference) ? (string) $reference : ($method->requires_reference
            ? ($context->numbers->receiptReference('folio_transaction', $event->sourceId)
                ?? ($depositId !== null ? $context->numbers->receiptReference('deposit', $depositId) : null)
                ?? "wastays folio_transactions {$event->sourceId}")
            : null);
        // wastays issued the receipt of a prepayment when the deposit came in, and applied it to a folio later. The first
        // payment taken from the deposit carries that receipt number.
        $number = $context->numbers->receipt('folio_transaction', $event->sourceId)
            ?? ($useDepositReceipt ? $context->numbers->receipt('deposit', $depositId) : null);
        $folio = $context->index->onCompanyFolio($row) ? $this->masterFolio($unit, $event) : null;
        $payment = $this->pay($context, $unit, $method, $unit->status === UnitStatus::Confirmed ? PaymentPurpose::Advance : PaymentPurpose::Settlement, $this->amount($row['amount']), $reference, 'wastays:folio_transactions:'.$event->sourceId, $number, "folio_transactions {$event->sourceId}", $folio);

        ($this->recordImportLink)($context->property, 'folio_transactions', $event->sourceId, $payment);

        if ($useDepositReceipt && $number !== null && $number === $context->numbers->receipt('deposit', $depositId)) {
            ($this->recordImportLink)($context->property, self::DEPOSIT_RECEIPTS, $depositId, $payment);
        }

        $context->summary->count('payments recorded');
    }

    private function deposit(ReplayContext $context, WastaysEvent $event): void
    {
        $row = $event->row;
        $unit = $this->unitOf($context, $event);
        $method = PaymentMethod::query()->where('property_id', $context->property->id)->where('type', PaymentMethodType::Cash)->active()->first();

        if ($method === null) {
            throw new ReplayStopped("deposits {$event->sourceId}: the property has no active Cash method.");
        }

        $payment = $this->pay($context, $unit, $method, PaymentPurpose::SecurityDeposit, $this->amount($row['amount']), $row['external_reference'], 'wastays:deposits:'.$event->sourceId, $context->numbers->receipt('deposit', $event->sourceId), "deposits {$event->sourceId}");

        ($this->recordImportLink)($context->property, 'deposits', $event->sourceId, $payment);
        $context->summary->count('deposits recorded');
    }

    /**
     * The Master folio of the unit's reservation, where a company's money and charges go.
     */
    private function masterFolio(ReservationUnit $unit, WastaysEvent $event): Folio
    {
        return Folio::query()->where('reservation_id', $unit->reservation_id)->where('type', FolioType::Master)->orderBy('id')->first()
            ?? throw new ReplayStopped("folio_transactions {$event->sourceId}: the company booking has no Master folio.");
    }

    private function pay(ReplayContext $context, ReservationUnit $unit, PaymentMethod $method, PaymentPurpose $purpose, string $amount, ?string $reference, string $key, ?int $receipt, string $label, ?Folio $folio = null): Model
    {
        $result = $this->numbers->run(DocumentType::Receipt, $receipt, fn () => ($this->recordPayment)($context->actor, $unit->reservation, $folio, $folio === null ? $unit : null, new NewPaymentData(
            paymentMethodId: $method->id,
            purpose: $purpose,
            amount: $amount,
            reference: $reference,
            idempotencyKey: $key,
            lineKey: $key,
            otaCollected: $method->type === PaymentMethodType::OtaCollected,
        )), $receipt !== null, $label);

        if ($result->isRefused()) {
            throw new ReplayStopped("{$label}: the payment was refused ({$result->refusal}).");
        }

        return $result->payment;
    }

    private function checkOut(ReplayContext $context, WastaysEvent $event): void
    {
        $unit = $this->unitOf($context, $event);
        $bookingId = (string) $event->bookingId;

        if ($unit->status !== UnitStatus::CheckedIn) {
            return;
        }

        if ($unit->departure_date->toDateString() > $context->date && $context->index->sameDayLeaver($bookingId) && $unit->arrival_date->toDateString() === $context->date) {
            $this->doCheckOut($context, $unit, $bookingId, 'same-day leavers checked out on the arrival day', sameDayLeaver: true);
            ($this->recordImportLink)($context->property, 'booking_audit_logs', $event->sourceId, $unit);

            return;
        }

        if ($unit->departure_date->toDateString() > $context->date) {
            $context->summary->count('check-outs before the departure date moved');

            return;
        }

        $this->doCheckOut($context, $unit, $bookingId, 'check-outs');
        ($this->recordImportLink)($context->property, 'booking_audit_logs', $event->sourceId, $unit);
    }

    private function doCheckOut(ReplayContext $context, ReservationUnit $unit, string $bookingId, string $label, bool $sameDayLeaver = false): void
    {
        // A folio reopened by an undone check-out keeps its invoice number, so closing it again issues none.
        $invoiced = Folio::query()->where('reservation_unit_id', $unit->id)->where('type', FolioType::Unit)->whereNotNull('invoice_number')->exists();
        $invoice = $invoiced ? null : $context->numbers->invoice($bookingId);
        $result = $this->numbers->run(DocumentType::Invoice, $invoice, fn () => ($this->checkOutUnit)($context->actor, $unit->refresh(), sameDayLeaver: $sameDayLeaver), $invoice !== null, "bookings {$bookingId}");

        if ($result->isRefused()) {
            throw new ReplayStopped("bookings {$bookingId}: the check-out was refused ({$result->refusal}).");
        }

        $context->summary->count($label);
    }

    private function unitOf(ReplayContext $context, WastaysEvent $event): ReservationUnit
    {
        $unit = $this->links->target($context->property, 'bookings', (string) $event->bookingId);

        if (! $unit instanceof ReservationUnit) {
            throw new ReplayStopped("{$event->sourceTable} {$event->sourceId}: booking {$event->bookingId} is not in estatell.");
        }

        return $unit->load('reservation');
    }

    /** The Direct source with this name, added when the property has none. */
    private function directSource(Property $property, User $actor, string $name): ?BookingSource
    {
        return BookingSource::query()->where('property_id', $property->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? ($this->createBookingSource)($property, $actor, ['name' => $name, 'type' => BookingSourceType::Direct]);
    }

    private function bookingSource(Property $property, User $actor, string $source): BookingSource
    {
        $found = match ($source) {
            'walk_in' => BookingSource::query()->where('property_id', $property->id)->where('type', BookingSourceType::WalkIn)->first(),
            'phone' => BookingSource::query()->where('property_id', $property->id)->whereRaw('lower(name) = ?', ['phone'])->first(),
            'email' => BookingSource::query()->where('property_id', $property->id)->whereRaw('lower(name) = ?', ['email'])->first(),
            'travel_agent' => BookingSource::query()->where('property_id', $property->id)->where('type', BookingSourceType::Company)->first(),
            'internal' => $this->directSource($property, $actor, 'Internal'),
            'ota' => ($this->ensureOtaBookingSource)($property, OtaChannel::query()->where('code', 'other')->firstOrFail(), 'OTA'),
            'booking_com', 'agoda' => ($this->ensureOtaBookingSource)($property, OtaChannel::query()->where('code', $source)->firstOrFail()),
            'traveloka' => ($this->ensureOtaBookingSource)($property, OtaChannel::query()->where('code', 'other')->firstOrFail(), 'Traveloka'),
            default => null,
        };

        return $found ?? throw new ReplayStopped("The booking source {$source} has no estatell source.");
    }

    /**
     * @param  array<string, string>  $nights
     * @return array<string, string>
     */
    private function nightsIn(array $nights, string $arrival, string $departure): array
    {
        return array_filter($nights, fn (string $price, string $date): bool => $date >= $arrival && $date < $departure, ARRAY_FILTER_USE_BOTH);
    }

    private function dayCount(string $arrival, string $departure): int
    {
        return (int) CarbonImmutable::parse($arrival)->diffInDays(CarbonImmutable::parse($departure));
    }

    private function linked(Property $property, string $table, string $id): Model
    {
        return $this->links->target($property, $table, $id) ?? throw new ReplayStopped("{$table} {$id} is not in estatell. Run the setup and guest imports first.");
    }

    private function amount(?string $value): string
    {
        return number_format(abs((float) $value), 2, '.', '');
    }

    /**
     * Run with the clock frozen at 12:00 property time on the date, no queue and mail kept in memory.
     *
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    private function quietly(Property $property, string $date, Closure $run)
    {
        $zone = $property->settings->time_zone ?? config('app.timezone');
        $at = CarbonImmutable::parse($date.' 12:00', $zone);
        $before = [Carbon::getTestNow(), CarbonImmutable::getTestNow()];
        $config = [config('queue.default'), config('mail.default')];

        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
        config(['queue.connections.null' => ['driver' => 'null'], 'queue.default' => 'null', 'mail.default' => 'array']);
        Mail::purge();

        try {
            return $run();
        } finally {
            Carbon::setTestNow($before[0]);
            CarbonImmutable::setTestNow($before[1]);
            config(['queue.default' => $config[0], 'mail.default' => $config[1]]);
            Mail::purge();
        }
    }
}
