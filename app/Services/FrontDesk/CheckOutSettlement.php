<?php

namespace App\Services\FrontDesk;

use App\Data\Reservations\CheckOutRoomShareData;
use App\Data\Reservations\UnitCheckOutPreviewData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\ReservationUnit;
use App\Services\Folio\FolioBalance;
use App\Services\Folio\IncomingScheduledCharges;
use App\Services\Folio\LineSourceUnits;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * What stands between a unit and check-out: the balance of its own folio, the security deposit it holds, and another
 * room that still charges its scheduled nights to that folio. It reads only. A held deposit is a credit, so the folio
 * reaches 0 only after the deposit is refunded or used against charges. When the folio also holds another room's
 * charges, the balance is split by room. See docs/18-front-desk/01-check-in-and-check-out.md#check-out.
 */
class CheckOutSettlement
{
    public function __construct(
        private FolioBalance $folioBalance,
        private LineSourceUnits $lineSourceUnits,
        private IncomingScheduledCharges $incomingScheduledCharges,
    ) {}

    public function for(ReservationUnit $unit, bool $canRefund, bool $canCollect = false, bool $canMoveCharges = false): UnitCheckOutPreviewData
    {
        $folios = $this->folios($unit);
        $balance = $folios->reduce(fn (BigDecimal $sum, Folio $folio): BigDecimal => $sum->plus($this->folioBalance->forFolio($folio)), BigDecimal::zero());
        $deposits = $this->heldDeposits($folios);
        $held = $deposits->reduce(fn (BigDecimal $sum, Payment $payment): BigDecimal => $sum->plus($this->refundable($payment)), BigDecimal::zero());
        $credit = $balance->isNegative() ? $balance->negated() : BigDecimal::zero();
        $incoming = $this->incomingScheduled($unit, $folios);
        $split = $this->roomShares($unit, $folios);
        $others = array_diff_key($split['shares'], [$unit->id => true]);
        $names = $others === [] ? [] : $this->incomingScheduledCharges->unitNames(array_keys($split['shares']));

        return new UnitCheckOutPreviewData(
            balance: (string) $balance->toScale(2),
            owing: (string) ($balance->isPositive() ? $balance : BigDecimal::zero())->toScale(2),
            depositHeld: (string) $held->toScale(2),
            refundDefault: (string) ($credit->isLessThan($held) ? $credit : $held)->toScale(2),
            depositMethod: $deposits->first()?->paymentMethod->name,
            canRefund: $canRefund,
            folioKey: $folios->first() === null ? null : (string) $folios->first()->id,
            error: $incoming === [] ? null : IncomingScheduledCharges::message($incoming),
            canCollect: $canCollect,
            rooms: $others === [] ? [] : array_map(
                fn (int $unitId): CheckOutRoomShareData => new CheckOutRoomShareData($names[$unitId] ?? '—', (string) $split['shares'][$unitId]->toScale(2), $unitId === $unit->id),
                array_keys($split['shares']),
            ),
            otherLineIds: $split['otherLineIds'],
            receivesScheduled: $incoming !== [],
            canMoveCharges: $canMoveCharges,
        );
    }

    /**
     * The other live units that still charge unposted Scheduled rows to one of the unit's Open folios, as names and
     * dates (`folio_receives_scheduled_charges`). The unit itself is left out: check-out ends its stay.
     *
     * @param  Collection<int, Folio>  $folios
     * @return list<string>
     */
    public function incomingScheduled(ReservationUnit $unit, Collection $folios): array
    {
        return $folios
            ->where('status', FolioStatus::Open)
            ->flatMap(fn (Folio $folio): array => $this->incomingScheduledCharges->for($folio, $unit->id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * What the unit's folios hold for other rooms, for the `balance_not_settled` message: "MYR 540.00 for Room 102".
     * Only rooms with an amount above 0 are named.
     *
     * @param  Collection<int, Folio>  $folios
     * @return list<string>
     */
    public function heldForOtherRooms(ReservationUnit $unit, Collection $folios): array
    {
        $others = array_filter(
            array_diff_key($this->roomShares($unit, $folios)['shares'], [$unit->id => true]),
            fn (BigDecimal $amount): bool => $amount->isPositive(),
        );

        if ($others === []) {
            return [];
        }

        $currency = (string) $unit->property()->with('settings')->first()?->settings?->currency->getCurrencyCode();
        $names = $this->incomingScheduledCharges->unitNames(array_keys($others));

        return array_map(
            fn (int $unitId): string => __(':amount for :room', ['amount' => trim($currency.' '.number_format($others[$unitId]->toFloat(), 2)), 'room' => $names[$unitId] ?? '—']),
            array_keys($others),
        );
    }

    /**
     * Each room's part of the folios' balance, by unit id with the unit first, from the line source: a tax or discount
     * child takes its parent's room, and a line with no unit source (a payment) is the unit's own. Rooms at 0 other than
     * the unit are left out. `otherLineIds` are the other rooms' charges on Open folios that could move: top-level, not a
     * reversal and not reversed.
     *
     * @param  Collection<int, Folio>  $folios
     * @return array{shares: array<int, BigDecimal>, otherLineIds: list<int>}
     */
    public function roomShares(ReservationUnit $unit, Collection $folios): array
    {
        $lines = FolioLine::query()->whereIn('folio_id', $folios->modelKeys())->orderBy('id')->get();
        $sources = $this->lineSourceUnits->for($lines);
        $byId = $lines->keyBy('id');
        $reversed = $lines->pluck('reversal_of_id')->filter()->flip();
        $open = $folios->where('status', FolioStatus::Open)->pluck('id')->flip();
        $shares = [$unit->id => BigDecimal::zero()];
        $otherLineIds = [];

        foreach ($lines as $line) {
            $top = $line->parent_line_id === null ? $line : ($byId->get($line->parent_line_id) ?? $line);
            $unitId = $sources[$top->source_type.':'.$top->source_id] ?? $unit->id;
            $shares[$unitId] = ($shares[$unitId] ?? BigDecimal::zero())->plus((string) $line->amount);

            if ($unitId !== $unit->id && $line->parent_line_id === null && $line->reversal_of_id === null && ! $reversed->has($line->id) && $open->has($line->folio_id)) {
                $otherLineIds[] = $line->id;
            }
        }

        return [
            'shares' => array_filter($shares, fn (BigDecimal $amount, int $unitId): bool => $unitId === $unit->id || ! $amount->isZero(), ARRAY_FILTER_USE_BOTH),
            'otherLineIds' => $otherLineIds,
        ];
    }

    /**
     * The charges of the unit's Open folios that `TransferLines` accepts, oldest first: not a child, not a reversal,
     * not reversed, not owned by a payment and not a payment-kind line (a City ledger line). Payments never move.
     *
     * @param  Collection<int, Folio>  $folios
     * @return Collection<int, FolioLine>
     */
    public function movableLines(Collection $folios): Collection
    {
        $open = $folios->where('status', FolioStatus::Open);
        $lines = FolioLine::query()->whereIn('folio_id', $open->modelKeys())->orderBy('id')->get();
        $reversed = $lines->pluck('reversal_of_id')->filter()->flip();
        $paymentLines = Payment::query()->whereIn('folio_id', $open->modelKeys())->pluck('folio_line_id')->flip();

        return $lines
            ->filter(fn (FolioLine $line): bool => $line->parent_line_id === null
                && $line->reversal_of_id === null
                && ! $reversed->has($line->id)
                && ! $paymentLines->has($line->id)
                && $line->kind !== TransactionKind::Payment)
            ->values();
    }

    /**
     * What `movableLines()` would move: the sum of every line on the unit's Open folios that is not a payment or a
     * payment-kind line, so a tax or discount child counts with its charge and a reversed charge with its reversal.
     *
     * @param  Collection<int, Folio>  $folios
     */
    public function movableTotal(Collection $folios): BigDecimal
    {
        $open = $folios->where('status', FolioStatus::Open)->modelKeys();
        $paymentLines = Payment::query()->whereIn('folio_id', $open)->select('folio_line_id');
        $total = FolioLine::query()
            ->whereIn('folio_id', $open)
            ->where('kind', '!=', TransactionKind::Payment)
            ->whereNotIn('id', $paymentLines)
            ->sum('amount');

        return BigDecimal::of((string) ($total ?: '0'))->toScale(2);
    }

    /**
     * The completed payments of the unit's Open folios that still have money to give back, whatever their purpose,
     * oldest first. A move to another folio refunds these, because a payment never moves.
     *
     * @param  Collection<int, Folio>  $folios
     * @return Collection<int, Payment>
     */
    public function heldPayments(Collection $folios): Collection
    {
        return Payment::query()
            ->whereIn('folio_id', $folios->where('status', FolioStatus::Open)->modelKeys())
            ->whereNull('refund_of_id')
            ->where('status', PaymentStatus::Completed)
            ->withSum('refunds', 'amount')
            ->with('paymentMethod')
            ->orderBy('id')
            ->get()
            ->filter(fn (Payment $payment): bool => $this->refundable($payment)->isPositive())
            ->values();
    }

    /**
     * Whether an Open folio of the unit holds money that `RefundPayment` cannot give back: a live payment-kind line
     * with no payment row (a City ledger line) or a payment that is not Completed.
     *
     * @param  Collection<int, Folio>  $folios
     */
    public function holdsUnrefundable(Collection $folios): bool
    {
        $open = $folios->where('status', FolioStatus::Open)->modelKeys();
        $lines = FolioLine::query()->whereIn('folio_id', $open)->where('kind', TransactionKind::Payment)->whereNull('reversal_of_id')->get();
        $reversed = FolioLine::query()->whereIn('folio_id', $open)->whereNotNull('reversal_of_id')->pluck('reversal_of_id')->flip();
        $owned = Payment::query()->whereIn('folio_id', $open)->pluck('folio_line_id')->flip();

        return $lines->contains(fn (FolioLine $line): bool => ! $reversed->has($line->id) && ! $owned->has($line->id) && BigDecimal::of($line->amount)->isNegative())
            || Payment::query()->whereIn('folio_id', $open)->whereNull('refund_of_id')->where('status', '!=', PaymentStatus::Completed)->exists();
    }

    /**
     * The completed security deposit payments of the unit's folios that still have money to give back, oldest first.
     *
     * @return Collection<int, Payment>
     */
    public function heldDeposits(Collection $folios): Collection
    {
        return Payment::query()
            ->whereIn('folio_id', $folios->pluck('id'))
            ->whereNull('refund_of_id')
            ->where('purpose', PaymentPurpose::SecurityDeposit)
            ->where('status', PaymentStatus::Completed)
            ->withSum('refunds', 'amount')
            ->with('paymentMethod')
            ->orderBy('id')
            ->get()
            ->filter(fn (Payment $payment): bool => $this->refundable($payment)->isPositive())
            ->values();
    }

    /**
     * @return Collection<int, Folio>
     */
    public function folios(ReservationUnit $unit): Collection
    {
        return Folio::query()
            ->where('reservation_unit_id', $unit->id)
            ->where('type', FolioType::Unit)
            ->where('status', '!=', FolioStatus::Voided)
            ->orderBy('id')
            ->get();
    }

    public function refundable(Payment $payment): BigDecimal
    {
        return BigDecimal::of($payment->amount)->minus((string) ($payment->refunds_sum_amount ?? '0'));
    }
}
