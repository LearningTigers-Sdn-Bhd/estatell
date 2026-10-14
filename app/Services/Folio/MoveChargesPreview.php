<?php

namespace App\Services\Folio;

use App\Actions\Folio\MoveChargesResult;
use App\Data\Folio\FolioRowData;
use App\Data\Folio\MoveBalanceData;
use App\Data\Folio\MoveChargeData;
use App\Data\Folio\MoveChargesPageData;
use App\Data\Folio\MoveChildData;
use App\Data\Folio\MoveChoiceData;
use App\Data\Folio\MoveFolioData;
use App\Data\Folio\MoveIncomingScheduleData;
use App\Data\Folio\MovePreviewData;
use App\Data\Folio\MoveScheduleData;
use App\Data\Property\CurrencyData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\RoutingRuleSource;
use App\Enums\TransactionCategory;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Reservations\ReservationRoomBox;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads the Move charges page of a reservation and writes nothing: the Open folios with the posted charges that can
 * move, the scheduled choices per room, and the balances before and after for a choice of charges and rooms. The
 * scheduled rows come from `ScheduledFolioRows` and are routed again with the rules the choice would create
 * (`FolioRouter` with proposed rules), so the preview and the saved result never differ. Every folio name comes from
 * `FolioStatement::optionLabel()`. See docs/13-folio/07-move-charges.md.
 */
class MoveChargesPreview
{
    public const int MAX_CHARGES = 200;

    public const string SCOPE_ROOM = 'room';

    public const string SCOPE_ALL = 'all';

    public function __construct(
        private FolioStatement $statement,
        private ScheduledFolioRows $scheduledRows,
        private FolioRouter $router,
        private LineSourceUnits $lineSourceUnits,
        private ReservationRoomBox $roomBox,
        private CurrentBusinessDate $currentBusinessDate,
        private FolioRefusals $refusals,
    ) {}

    /**
     * The room number (or room type) of each unit of the reservation, by unit id: the label `optionLabel()` expects.
     *
     * @return array<int, string>
     */
    public function unitLabels(Reservation $reservation): array
    {
        $units = $reservation->units()->with('roomType:id,name')->orderBy('id')->get();
        $assigned = $this->roomBox->assignmentsForUnits($units->modelKeys());

        return $units->mapWithKeys(fn (ReservationUnit $unit): array => [$unit->id => $assigned[$unit->id] ?? $unit->roomType->name])->all();
    }

    /**
     * A folio's name with its payer, from `FolioStatement::optionLabel()`: the only way the page, the toast and the
     * activity log name a folio.
     *
     * @param  array<int, string>|null  $unitLabels  Read from the reservation when null.
     */
    public function folioLabel(Folio $folio, ?array $unitLabels = null): string
    {
        $folio->loadMissing('payerGuest', 'payerCompany:id,name');

        return $this->statement->optionLabel($folio, $unitLabels ?? $this->unitLabels($folio->reservation));
    }

    /**
     * Whether each given unit has a night after the business date, as a list of the unit ids that do. A room with no
     * such night has nothing left to charge (`nothing_scheduled` in `MoveCharges`).
     *
     * @param  list<int>  $unitIds
     * @return list<int>
     */
    public function unitsWithNightsAfter(array $unitIds, CarbonImmutable $businessDate): array
    {
        return UnitNight::query()
            ->whereIn('reservation_unit_id', $unitIds)
            ->whereDate('date', '>', $businessDate->toDateString())
            ->distinct()
            ->pluck('reservation_unit_id')
            ->all();
    }

    /**
     * The Manual rules a scheduled choice would create, unsaved: this unit and Room to the target, and with `all` also
     * this unit and all charges. `MoveCharges` saves the same list.
     *
     * @param  list<array{unit: ReservationUnit, scope: string}>  $schedules
     * @return list<FolioRoutingRule>
     */
    public function proposedRules(Reservation $reservation, Folio $target, array $schedules): array
    {
        $rules = [];

        foreach ($schedules as $schedule) {
            foreach ([TransactionCategory::Room, ...($schedule['scope'] === self::SCOPE_ALL ? [null] : [])] as $category) {
                $rules[] = new FolioRoutingRule([
                    'reservation_id' => $reservation->id,
                    'reservation_unit_id' => $schedule['unit']->id,
                    'category' => $category,
                    'transaction_code_id' => null,
                    'target_folio_id' => $target->id,
                    'source' => RoutingRuleSource::Manual,
                ]);
            }
        }

        return $rules;
    }

    /**
     * The folio the To column starts on. `$to` is used when it is an Open folio of the reservation. Otherwise the Master
     * folio, or the first Open folio, other than the folio of the line to tick (so Move… on a Master charge starts on
     * the first other Open folio). Null when the reservation has no Open folio to choose.
     */
    public function startTarget(Reservation $reservation, ?Folio $to, ?FolioLine $line): ?Folio
    {
        if ($to !== null && $to->status === FolioStatus::Open) {
            return $to;
        }

        $open = $this->folios($reservation, array_keys($this->unitLabels($reservation)))
            ->filter(fn (Folio $folio): bool => $folio->status === FolioStatus::Open && $folio->id !== $line?->folio_id);

        return $open->first(fn (Folio $folio): bool => $folio->type === FolioType::Master) ?? $open->first();
    }

    /**
     * The page props for a start: the Open folios with their movable charges, and the first preview.
     *
     * @param  list<int>  $lineIds
     */
    public function page(Property $property, Reservation $reservation, Folio $target, array $lineIds, string $cancelUrl): MoveChargesPageData
    {
        $state = $this->state($property, $reservation);
        $preview = $this->compute($property, $reservation, $target, $lineIds, [], $state);

        return new MoveChargesPageData(
            reservationId: $reservation->id,
            reference: $reservation->reference,
            title: __('Move charges · :reference · :name', ['reference' => $reservation->reference, 'name' => $reservation->group_name ?? $reservation->booker->full_name]),
            currency: CurrencyData::forProperty($property),
            folios: array_values($state['openFolios']->map(fn (Folio $folio): MoveFolioData => new MoveFolioData(
                $folio->id,
                $state['labels'][$folio->id],
                $folio->type->value,
                $state['balances'][$folio->id] ?? '0.00',
                $state['charges']->where('folioId', $folio->id)->pluck('data')->values()->all(),
            ))->all()),
            targetId: $target->id,
            lineIds: array_values(array_filter($lineIds, fn (int $id): bool => $state['charges']->contains(fn (array $charge): bool => $charge['lineId'] === $id && $charge['folioId'] !== $target->id))),
            maxCharges: self::MAX_CHARGES,
            preview: $preview,
            cancelUrl: $cancelUrl,
        );
    }

    /**
     * The figures and notes for a choice. A line that cannot move (a child, a reversed line, a payment, a line on the
     * target) adds nothing here; the Action refuses it when saving.
     *
     * @param  list<int>  $lineIds
     * @param  list<array{unit: ReservationUnit, scope: string}>  $schedules
     */
    public function preview(Property $property, Reservation $reservation, Folio $target, array $lineIds, array $schedules): MovePreviewData
    {
        return $this->compute($property, $reservation, $target, $lineIds, $schedules, $this->state($property, $reservation));
    }

    /**
     * Everything read once per request: the folios and their names, the balances, the movable charges and the
     * Scheduled blocks.
     *
     * @return array{labels: array<int, string>, openFolios: Collection<int, Folio>, balances: array<int, string>, charges: Collection<int, array{lineId: int, folioId: int, unitId: int|null, isRoom: bool, total: string, data: MoveChargeData}>, blocks: list<array{target: int|null, unit: int, date: string, code: TransactionCode, rows: list<FolioRowData>}>, unitLabels: array<int, string>, units: Collection<int, ReservationUnit>}
     */
    private function state(Property $property, Reservation $reservation): array
    {
        $property->loadMissing('settings');
        $reservation->loadMissing('booker');
        $unitLabels = $this->unitLabels($reservation);
        $folios = $this->folios($reservation, array_keys($unitLabels));
        $openFolios = $folios->filter(fn (Folio $folio): bool => $folio->status === FolioStatus::Open)->values();
        $balances = FolioLine::query()
            ->whereIn('folio_id', $openFolios->modelKeys())
            ->groupBy('folio_id')
            ->selectRaw('folio_id, SUM(amount)::numeric(14, 2) AS balance')
            ->pluck('balance', 'folio_id')
            ->map(fn (mixed $balance): string => (string) $balance)
            ->all();

        return [
            'labels' => $folios->mapWithKeys(fn (Folio $folio): array => [$folio->id => $this->statement->optionLabel($folio, $unitLabels)])->all(),
            'openFolios' => $openFolios,
            'balances' => $balances,
            'charges' => $this->charges($openFolios, $unitLabels),
            'blocks' => $this->scheduledRows->forReservation($property, $reservation, $unitLabels),
            'unitLabels' => $unitLabels,
            'units' => $reservation->units()->orderBy('id')->get()->keyBy('id'),
        ];
    }

    /**
     * Master first, then Unit folios in unit order, then third-party folios. Voided folios are left out.
     *
     * @param  list<int>  $unitOrder
     * @return Collection<int, Folio>
     */
    private function folios(Reservation $reservation, array $unitOrder): Collection
    {
        return Folio::query()
            ->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)
            ->where('status', '!=', FolioStatus::Voided)
            ->with('payerGuest', 'payerCompany:id,name')
            ->get()
            ->sortBy(fn (Folio $folio): array => [
                match ($folio->type) {
                    FolioType::Master => 0,
                    FolioType::Unit => 1,
                    FolioType::ThirdParty => 2,
                },
                $folio->type === FolioType::Unit ? (int) array_search($folio->reservation_unit_id, $unitOrder, true) : 0,
                $folio->id,
            ])
            ->values();
    }

    /**
     * The charges `TransferLines` accepts on the given folios, with their live children: not a child, not a reversal, not
     * reversed and not owned by a payment.
     *
     * @param  Collection<int, Folio>  $folios
     * @param  array<int, string>  $unitLabels
     * @return Collection<int, array{lineId: int, folioId: int, unitId: int|null, isRoom: bool, total: string, data: MoveChargeData}>
     */
    private function charges(Collection $folios, array $unitLabels): Collection
    {
        $lines = FolioLine::query()
            ->whereIn('folio_id', $folios->modelKeys())
            ->with('transactionCode:id,category')
            ->orderBy('posting_date')
            ->orderBy('id')
            ->get();
        $reversed = $lines->pluck('reversal_of_id')->filter()->flip();
        $paymentLines = Payment::query()->whereIn('folio_id', $folios->modelKeys())->pluck('folio_line_id')->flip();
        $children = $lines->whereNotNull('parent_line_id')->groupBy('parent_line_id');
        $sourceUnits = $this->lineSourceUnits->for($lines);

        $live = fn (FolioLine $line): bool => $line->reversal_of_id === null && ! $reversed->has($line->id);
        $collect = function (FolioLine $line) use (&$collect, $children, $live): array {
            $found = [];

            foreach ($children->get($line->id, []) as $child) {
                $found = [...$found, ...($live($child) ? [$child] : []), ...$collect($child)];
            }

            return $found;
        };

        return $lines
            ->filter(fn (FolioLine $line): bool => $line->parent_line_id === null && $live($line) && ! $paymentLines->has($line->id))
            ->map(function (FolioLine $line) use ($collect, $sourceUnits, $unitLabels): array {
                $unitId = $line->source_id === null ? null : ($sourceUnits[$line->source_type.':'.$line->source_id] ?? null);
                $descendants = $collect($line);
                $total = array_reduce($descendants, fn (BigDecimal $sum, FolioLine $child): BigDecimal => $sum->plus($child->amount), BigDecimal::of($line->amount));
                $isRoom = $line->transactionCode->category === TransactionCategory::Room;

                return [
                    'lineId' => $line->id,
                    'folioId' => $line->folio_id,
                    'unitId' => $unitId,
                    'isRoom' => $isRoom,
                    'total' => (string) $total->toScale(2),
                    'data' => new MoveChargeData(
                        $line->id,
                        $line->posting_date->toDateString(),
                        $line->description,
                        $unitId === null ? null : ($unitLabels[$unitId] ?? null),
                        (string) $line->amount,
                        (string) $total->toScale(2),
                        $isRoom,
                        array_values(array_map(fn (FolioLine $child): MoveChildData => new MoveChildData($child->description, (string) $child->amount), $descendants)),
                    ),
                ];
            })
            ->values();
    }

    /**
     * @param  list<int>  $lineIds
     * @param  list<array{unit: ReservationUnit, scope: string}>  $schedules
     * @param  array<string, mixed>  $state
     */
    private function compute(Property $property, Reservation $reservation, Folio $target, array $lineIds, array $schedules, array $state): MovePreviewData
    {
        /** @var array<int, string> $labels */
        $labels = $state['labels'];
        /** @var array<int, string> $unitLabels */
        $unitLabels = $state['unitLabels'];
        /** @var Collection<int, array{lineId: int, folioId: int, unitId: int|null, isRoom: bool, total: string, data: MoveChargeData}> $charges */
        $charges = $state['charges'];
        $currency = (string) $property->settings?->currency->getCurrencyCode();
        $money = fn (string $amount): string => trim($currency.' '.number_format((float) $amount, 2));
        $toLabel = $labels[$target->id];

        $ticked = $charges->filter(fn (array $charge): bool => in_array($charge['lineId'], $lineIds, true) && $charge['folioId'] !== $target->id)->values();
        $moved = $ticked->groupBy('folioId')->map(fn (Collection $group): BigDecimal => $group->reduce(fn (BigDecimal $sum, array $charge): BigDecimal => $sum->plus($charge['total']), BigDecimal::zero()));
        $movedTotal = $moved->reduce(fn (BigDecimal $sum, BigDecimal $amount): BigDecimal => $sum->plus($amount), BigDecimal::zero());

        $balances = [];

        foreach ($state['openFolios'] as $folio) {
            $before = BigDecimal::of($state['balances'][$folio->id] ?? '0');
            $after = match (true) {
                $folio->id === $target->id => $before->plus($movedTotal),
                default => $before->minus($moved->get($folio->id, BigDecimal::zero())),
            };
            $balances[] = new MoveBalanceData($folio->id, (string) $before->toScale(2), (string) $after->toScale(2));
        }

        [$choices, $incoming, $notes, $projectedAfter] = $this->scheduled($reservation, $target, $schedules, $ticked, $state, $labels, $unitLabels, $toLabel, $money);

        $targetBefore = BigDecimal::of($state['balances'][$target->id] ?? '0');
        $projectedBefore = $this->rowsTotal(array_filter($state['blocks'], fn (array $block): bool => $block['target'] === $target->id))->plus($targetBefore);
        $refusal = count($lineIds) > self::MAX_CHARGES ? $this->refusals->message(MoveChargesResult::REFUSAL_TOO_MANY_CHARGES) : null;

        return new MovePreviewData(
            targetId: $target->id,
            balances: $balances,
            projectedBefore: (string) $projectedBefore->toScale(2),
            projectedAfter: (string) $targetBefore->plus($movedTotal)->plus($projectedAfter)->toScale(2),
            schedules: $choices,
            incoming: $incoming,
            notes: $notes,
            chargeCount: $ticked->count(),
            amount: (string) $movedTotal->toScale(2),
            scheduleCount: count($schedules),
            refusal: $refusal,
        );
    }

    /**
     * The scheduled side of a preview: the choices per room, the Scheduled charges that land on the target, the notes,
     * and the sum of the Scheduled rows the target would hold after the move.
     *
     * @param  list<array{unit: ReservationUnit, scope: string}>  $schedules
     * @param  Collection<int, array{lineId: int, folioId: int, unitId: int|null, isRoom: bool, total: string, data: MoveChargeData}>  $ticked
     * @param  array<string, mixed>  $state
     * @param  array<int, string>  $labels
     * @param  array<int, string>  $unitLabels
     * @return array{0: list<MoveScheduleData>, 1: list<MoveIncomingScheduleData>, 2: list<string>, 3: BigDecimal}
     */
    private function scheduled(Reservation $reservation, Folio $target, array $schedules, Collection $ticked, array $state, array $labels, array $unitLabels, string $toLabel, callable $money): array
    {
        /** @var list<array{target: int|null, unit: int, date: string, code: TransactionCode, rows: list<FolioRowData>}> $blocks */
        $blocks = $state['blocks'];
        /** @var Collection<int, ReservationUnit> $units */
        $units = $state['units'];
        $businessDate = $this->currentBusinessDate->date($reservation->property);
        $offTarget = array_values(array_filter($blocks, fn (array $block): bool => $block['target'] !== $target->id));
        $withNights = $businessDate === null ? [] : $this->unitsWithNightsAfter(array_values(array_unique(array_column($offTarget, 'unit'))), $businessDate);
        $candidates = $units->filter(fn (ReservationUnit $unit): bool => $unit->status->holdsInventory() && in_array($unit->id, $withNights, true));

        $asSchedules = fn (string $scope): array => array_values($candidates->map(fn (ReservationUnit $unit): array => ['unit' => $unit, 'scope' => $scope])->all());
        $resolveRoom = $this->router->resolverFor($reservation, $this->proposedRules($reservation, $target, $asSchedules(self::SCOPE_ROOM)));
        $resolveAll = $this->router->resolverFor($reservation, $this->proposedRules($reservation, $target, $asSchedules(self::SCOPE_ALL)));

        $choices = [];

        foreach ($candidates as $unit) {
            $own = array_filter($offTarget, fn (array $block): bool => $block['unit'] === $unit->id);
            $roomMoved = array_filter($own, fn (array $block): bool => $resolveRoom($unit, $block['code']) === $target->id);
            $allMoved = array_filter($own, fn (array $block): bool => $resolveAll($unit, $block['code']) === $target->id);

            if ($allMoved === []) {
                continue;
            }

            $entries = [];

            if ($roomMoved !== []) {
                $entries[] = new MoveChoiceData(self::SCOPE_ROOM, __('Charge room nights to :folio', ['folio' => $toLabel]), $this->nightsText($roomMoved, $money), (string) $this->rowsTotal($roomMoved)->toScale(2));
            }

            $entries[] = new MoveChoiceData(self::SCOPE_ALL, __('Charge everything to :folio', ['folio' => $toLabel]), $this->everythingText($allMoved, $money), (string) $this->rowsTotal($allMoved)->toScale(2));
            $first = array_values($own)[0];
            $choices[] = new MoveScheduleData($unit->id, $unitLabels[$unit->id] ?? '—', $first['target'], $entries);
        }

        $proposed = $this->proposedRules($reservation, $target, $schedules);
        $resolve = $this->router->resolverFor($reservation, $proposed);
        $pick = $this->router->pickerFor($reservation, $proposed);
        $scope = collect($schedules)->mapWithKeys(fn (array $schedule): array => [$schedule['unit']->id => $schedule['scope']]);
        $incoming = [];
        $notes = [];
        $afterRows = [];

        foreach ($blocks as $block) {
            $unit = $units->get($block['unit']);

            if ($unit !== null && $resolve($unit, $block['code']) === $target->id) {
                $afterRows[] = $block;
            }
        }

        foreach ($ticked->where('isRoom', true)->pluck('unitId')->filter()->unique() as $unitId) {
            $unit = $units->get($unitId);

            if ($unit === null || $scope->has($unitId)) {
                continue;
            }

            $left = collect($blocks)
                ->filter(fn (array $block): bool => $block['unit'] === $unitId && $block['code']->category === TransactionCategory::Room && $resolve($unit, $block['code']) !== $target->id)
                ->groupBy(fn (array $block): int => (int) $resolve($unit, $block['code']));

            foreach ($left as $folioId => $group) {
                if ($folioId === 0) {
                    continue;
                }

                $notes[] = $group->count() === 1
                    ? __('1 scheduled night of Room :room will still be charged to :folio.', ['room' => $unitLabels[$unitId] ?? '—', 'folio' => $labels[$folioId] ?? '—'])
                    : __(':count scheduled nights of Room :room will still be charged to :folio.', ['count' => $group->count(), 'room' => $unitLabels[$unitId] ?? '—', 'folio' => $labels[$folioId] ?? '—']);
            }
        }

        foreach ($schedules as $schedule) {
            $unit = $schedule['unit'];
            $mine = array_filter($blocks, fn (array $block): bool => $block['unit'] === $unit->id);
            $landed = array_filter($mine, fn (array $block): bool => $block['target'] !== $target->id && $resolve($unit, $block['code']) === $target->id);

            if ($landed !== []) {
                $room = array_filter($landed, fn (array $block): bool => $block['code']->category === TransactionCategory::Room);
                $other = array_diff_key($landed, $room);

                if ($room !== []) {
                    $incoming[] = new MoveIncomingScheduleData($unit->id, $unitLabels[$unit->id] ?? '—', __('Room nights :nights', ['nights' => $this->nightsText($room, $money, false)]), (string) $this->rowsTotal($room)->toScale(2));
                }

                foreach (collect($other)->groupBy(fn (array $block): string => $block['rows'][0]->description) as $description => $group) {
                    $incoming[] = new MoveIncomingScheduleData($unit->id, $unitLabels[$unit->id] ?? '—', $this->chargeText((string) $description, $group->all()), (string) $this->rowsTotal($group->all())->toScale(2));
                }
            }

            foreach ($mine as $block) {
                $inScope = $schedule['scope'] === self::SCOPE_ALL || $block['code']->category === TransactionCategory::Room;
                $stays = $inScope && $resolve($unit, $block['code']) !== $target->id;
                $rule = $stays ? $pick($unit, $block['code']) : null;

                if ($rule !== null) {
                    $notes[] = __(':charge stays on :folio: a rule sends :what charges there.', [
                        'charge' => $this->chargeText($block['rows'][0]->description, [$block]),
                        'folio' => $labels[$rule->target_folio_id] ?? '—',
                        'what' => $rule->transaction_code_id !== null ? $block['code']->name : ($rule->category?->label() ?? __('all')),
                    ]);
                }
            }
        }

        return [$choices, $incoming, array_values(array_unique($notes)), $this->rowsTotal($afterRows)];
    }

    /**
     * "15–16 Oct · 2 nights · MYR 540.00". With `$withAmount` false it stops after the nights.
     *
     * @param  array<int, array{target: int|null, unit: int, date: string, code: TransactionCode, rows: list<FolioRowData>}>  $blocks
     */
    private function nightsText(array $blocks, callable $money, bool $withAmount = true): string
    {
        $dates = collect($blocks)->pluck('date')->sort()->values();
        $from = CarbonImmutable::parse($dates->first());
        $to = CarbonImmutable::parse($dates->last());
        $range = $from->equalTo($to) ? $from->format('j M') : DateRange::short($from, $to);
        $nights = count($blocks) === 1 ? __('1 night') : __(':count nights', ['count' => count($blocks)]);
        $text = $range.' · '.$nights;

        return $withAmount ? $text.' · '.$money((string) $this->rowsTotal($blocks)->toScale(2)) : $text;
    }

    /**
     * "Room nights, Island trip 16 Oct and any later charge · MYR 900.00".
     *
     * @param  array<int, array{target: int|null, unit: int, date: string, code: TransactionCode, rows: list<FolioRowData>}>  $blocks
     */
    private function everythingText(array $blocks, callable $money): string
    {
        $room = array_filter($blocks, fn (array $block): bool => $block['code']->category === TransactionCategory::Room);
        $parts = $room === [] ? [] : [__('Room nights')];

        foreach (collect(array_diff_key($blocks, $room))->groupBy(fn (array $block): string => $block['rows'][0]->description) as $description => $group) {
            $parts[] = $this->chargeText((string) $description, $group->all());
        }

        return __(':charges and any later charge · :amount', ['charges' => implode(', ', $parts), 'amount' => $money((string) $this->rowsTotal($blocks)->toScale(2))]);
    }

    /**
     * "Island trip 16 Oct" for one block, or "Breakfast on 3 days" for a charge that repeats.
     *
     * @param  array<int, array{date: string}>  $blocks
     */
    private function chargeText(string $description, array $blocks): string
    {
        if (count($blocks) > 1) {
            return __(':charge on :count days', ['charge' => $description, 'count' => count($blocks)]);
        }

        return $description.' '.CarbonImmutable::parse(array_values($blocks)[0]['date'])->format('j M');
    }

    /**
     * The sum of every row of the blocks: the charge, its discounts and its tax.
     *
     * @param  array<int, array{rows: list<FolioRowData>}>  $blocks
     */
    private function rowsTotal(array $blocks): BigDecimal
    {
        return array_reduce($blocks, fn (BigDecimal $sum, array $block): BigDecimal => array_reduce($block['rows'], fn (BigDecimal $inner, $row): BigDecimal => $inner->plus($row->amount), $sum), BigDecimal::zero());
    }
}
