<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\RoutingRuleSource;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Folio\MoveChargesPreview;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Throwable;

class MoveCharges
{
    public function __construct(
        private TransferLines $transferLines,
        private CreateRoutingRule $createRoutingRule,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
        private CurrentBusinessDate $currentBusinessDate,
        private MoveChargesPreview $preview,
    ) {}

    /**
     * Move posted charges from several folios of one reservation to the target, and choose where each room's Scheduled
     * charges go, in one transaction. `$schedules` holds one entry per room: `room` saves a Manual rule for this room and
     * Room, `all` also one for this room and all charges. A Manual rule with the same selectors is replaced; a BillingMode
     * rule is never touched. Every check runs before the first write, so a refusal writes and logs nothing, and the posted
     * move (`TransferLines`) and the rules roll back together. The one activity entry is `folio.charges_moved`; the inner
     * calls write none. The last step closes each source that is an Open Unit folio of a CheckedOut unit at 0. A repeat
     * of the same request finds the lines reversed and is refused (`line_not_transferable`).
     * See docs/13-folio/07-move-charges.md.
     *
     * @param  list<FolioLine>  $lines
     * @param  list<array{unit: ReservationUnit, scope: string}>  $schedules
     */
    public function __invoke(User $actor, Folio $target, array $lines, array $schedules): MoveChargesResult
    {
        DB::beginTransaction();

        try {
            $result = $this->move($actor, $target, $lines, $schedules);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $result->isRefused() ? DB::rollBack() : DB::commit();

        return $result;
    }

    /**
     * @param  list<FolioLine>  $lines
     * @param  list<array{unit: ReservationUnit, scope: string}>  $schedules
     */
    private function move(User $actor, Folio $target, array $lines, array $schedules): MoveChargesResult
    {
        $schedules = array_values(collect($schedules)->keyBy(fn (array $schedule): int => $schedule['unit']->id)->all());
        $ids = array_values(array_unique(array_map(fn (FolioLine $line): int => $line->id, $lines)));

        if ($ids === [] && $schedules === []) {
            return MoveChargesResult::refused(MoveChargesResult::REFUSAL_NOTHING_TO_MOVE);
        }

        if (count($ids) > MoveChargesPreview::MAX_CHARGES) {
            return MoveChargesResult::refused(MoveChargesResult::REFUSAL_TOO_MANY_CHARGES);
        }

        $found = FolioLine::query()->whereIn('id', $ids)->get();

        if ($found->count() !== count($ids)) {
            return MoveChargesResult::refused(PostingResult::REFUSAL_TRANSFER_INVALID);
        }

        $folios = Folio::query()->whereIn('id', [...$found->pluck('folio_id')->unique()->all(), $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $targetFolio = $folios[$target->id];
        $sources = $folios->except($target->id)->values();

        if ($folios->contains(fn (Folio $folio): bool => $folio->reservation_id !== $targetFolio->reservation_id)
            || collect($schedules)->contains(fn (array $schedule): bool => $schedule['unit']->reservation_id !== $targetFolio->reservation_id)) {
            return MoveChargesResult::refused(PostingResult::REFUSAL_DIFFERENT_RESERVATION);
        }

        if ($targetFolio->status !== FolioStatus::Open) {
            return MoveChargesResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
        }

        $reservation = $targetFolio->reservation;
        $businessDate = $this->currentBusinessDate->date($targetFolio->property);

        if ($businessDate === null) {
            return MoveChargesResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
        }

        $withNights = $this->preview->unitsWithNightsAfter(array_map(fn (array $schedule): int => $schedule['unit']->id, $schedules), $businessDate);
        $idle = array_values(array_filter($schedules, fn (array $schedule): bool => ! $schedule['unit']->status->holdsInventory() || ! in_array($schedule['unit']->id, $withNights, true)));

        if ($idle !== []) {
            $labels = $this->preview->unitLabels($reservation);

            return MoveChargesResult::refused(MoveChargesResult::REFUSAL_NOTHING_SCHEDULED, array_map(fn (array $schedule): string => $labels[$schedule['unit']->id] ?? '—', $idle));
        }

        $copies = [];

        if ($ids !== []) {
            $transfer = ($this->transferLines)($actor, $lines, $targetFolio, false);

            if ($transfer->isRefused()) {
                return MoveChargesResult::refused((string) $transfer->refusal);
            }

            $copies = $transfer->lines;
        }

        $parents = array_values(array_filter($copies, fn (FolioLine $copy): bool => $copy->parent_line_id === null));
        $amount = array_reduce($copies, fn (BigDecimal $sum, FolioLine $copy): BigDecimal => $sum->plus($copy->amount), BigDecimal::zero());
        $changes = $this->saveRules($reservation, $targetFolio, $schedules);

        if ($changes['refusal'] !== null) {
            return MoveChargesResult::refused($changes['refusal']);
        }

        $this->log($actor, $targetFolio, $sources->all(), count($parents), (string) $amount->toScale(2), $schedules, $changes, $copies[0]->transfer_group_id ?? null, $ids);

        foreach ($sources as $source) {
            ($this->closeSettledUnitFolio)($actor, $source);
        }

        return MoveChargesResult::moved(count($parents), (string) $amount->toScale(2), count($schedules), $sources->all());
    }

    /**
     * Save the Manual rules of the choices. A Manual rule with the same selectors is deleted first (one that already
     * points at the target is kept, and counts as nothing new). A BillingMode rule is never touched.
     *
     * @param  list<array{unit: ReservationUnit, scope: string}>  $schedules
     * @return array{refusal: string|null, created: list<array{reservation_unit_id: int, category: string|null, target_folio_id: int}>, replaced: list<array{reservation_unit_id: int, category: string|null, target_folio_id: int}>}
     */
    private function saveRules(Reservation $reservation, Folio $target, array $schedules): array
    {
        $created = [];
        $replaced = [];

        foreach ($this->preview->proposedRules($reservation, $target, $schedules) as $proposed) {
            $existing = FolioRoutingRule::query()
                ->where('reservation_id', $reservation->id)
                ->where('reservation_unit_id', $proposed->reservation_unit_id)
                ->where('category', $proposed->category?->value)
                ->whereNull('transaction_code_id')
                ->where('source', RoutingRuleSource::Manual)
                ->first();

            if ($existing?->target_folio_id === $target->id) {
                continue;
            }

            if ($existing !== null) {
                $replaced[] = $this->describe($existing);
                $existing->delete();
            }

            $result = ($this->createRoutingRule)($reservation, $target, RoutingRuleSource::Manual, $proposed->reservation_unit_id, $proposed->category);

            if ($result->isRefused() && $result->refusal !== RoutingRuleResult::REFUSAL_RULE_EXISTS) {
                return ['refusal' => (string) $result->refusal, 'created' => [], 'replaced' => []];
            }

            $created[] = $this->describe($proposed);
        }

        return ['refusal' => null, 'created' => $created, 'replaced' => $replaced];
    }

    /**
     * @return array{reservation_unit_id: int, category: string|null, target_folio_id: int}
     */
    private function describe(FolioRoutingRule $rule): array
    {
        return ['reservation_unit_id' => (int) $rule->reservation_unit_id, 'category' => $rule->category?->value, 'target_folio_id' => $rule->target_folio_id];
    }

    /**
     * @param  list<Folio>  $sources
     * @param  list<array{unit: ReservationUnit, scope: string}>  $schedules
     * @param  array{refusal: string|null, created: list<array<string, mixed>>, replaced: list<array<string, mixed>>}  $changes
     * @param  list<int>  $lineIds
     */
    private function log(User $actor, Folio $target, array $sources, int $count, string $amount, array $schedules, array $changes, ?string $group, array $lineIds): void
    {
        $unitLabels = $this->preview->unitLabels($target->reservation);
        $label = fn (Folio $folio): string => $this->preview->folioLabel($folio, $unitLabels);
        $to = $label($target);
        $currency = (string) $target->property->settings?->currency->getCurrencyCode();
        $parts = [];

        if ($count > 0) {
            $parts[] = __('Moved :count :charges (:amount) to :to from :from.', [
                'count' => $count,
                'charges' => $count === 1 ? __('charge') : __('charges'),
                'amount' => trim($currency.' '.number_format((float) $amount, 2)),
                'to' => $to,
                'from' => count($sources) === 1 ? $label($sources[0]) : __(':count folios', ['count' => count($sources)]),
            ]);
        }

        if ($schedules !== []) {
            $rooms = count($schedules) === 1 ? __('1 room') : __(':count rooms', ['count' => count($schedules)]);
            $onlyNights = collect($schedules)->every(fn (array $schedule): bool => $schedule['scope'] === MoveChargesPreview::SCOPE_ROOM);
            $parts[] = $onlyNights
                ? __('Scheduled room nights of :rooms now go to :to.', ['rooms' => $rooms, 'to' => $to])
                : __('Scheduled charges of :rooms now go to :to.', ['rooms' => $rooms, 'to' => $to]);
        }

        activity()
            ->performedOn($target->reservation)
            ->causedBy($actor)
            ->event('folio.charges_moved')
            ->withProperties([
                'property_id' => $target->property_id,
                'to_folio_id' => $target->id,
                'from_folio_ids' => array_map(fn (Folio $folio): int => $folio->id, $sources),
                'transfer_group_id' => $group,
                'folio_line_ids' => $lineIds,
                'charge_count' => $count,
                'amount' => $amount,
                'schedules' => array_map(fn (array $schedule): array => ['reservation_unit_id' => $schedule['unit']->id, 'scope' => $schedule['scope']], $schedules),
                'rules_created' => $changes['created'],
                'rules_replaced' => $changes['replaced'],
            ])
            ->log(implode(' ', $parts));
    }
}
