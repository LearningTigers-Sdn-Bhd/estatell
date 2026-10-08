<?php

namespace App\Services\Folio;

use App\Data\Folio\FolioOptionData;
use App\Data\Folio\RoutingRuleRowData;
use App\Data\Folio\RoutingRulesData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\RoutingRuleSource;
use App\Enums\TransactionCategory;
use App\Models\Folio;
use App\Models\FolioRoutingRule;
use App\Models\Reservation;

/**
 * Reads the Routing rules Sheet of one reservation in two queries: the rules with their code and target folio, and
 * the Open folios that a new rule can target, in rail order. It writes nothing. See docs/13-folio/05-folio-page.md.
 */
class RoutingRuleList
{
    public function __construct(private FolioStatement $statement) {}

    /**
     * Rules are listed by unit (all units first), then by how specific they are, the order `FolioRouter` tries them in.
     *
     * @param  list<ReservationUnitOptionData>  $units
     */
    public function build(Reservation $reservation, array $units): RoutingRulesData
    {
        $labels = collect($units)->mapWithKeys(fn (ReservationUnitOptionData $unit): array => [$unit->id => $unit->assigned ?? $unit->roomType])->all();

        $rules = FolioRoutingRule::query()
            ->where('reservation_id', $reservation->id)
            ->with(['transactionCode', 'targetFolio.payerGuest', 'targetFolio.payerCompany:id,name'])
            ->get()
            ->sortBy(fn (FolioRoutingRule $rule): array => [
                $rule->reservation_unit_id === null ? 0 : 1,
                $rule->reservation_unit_id === null ? 0 : (int) array_search($rule->reservation_unit_id, array_keys($labels), true),
                $rule->transaction_code_id !== null ? 0 : ($rule->category !== null ? 1 : 2),
                $rule->id,
            ])
            ->map(fn (FolioRoutingRule $rule): RoutingRuleRowData => new RoutingRuleRowData(
                id: $rule->id,
                unit: $rule->reservation_unit_id === null ? __('All units') : __('Unit :unit', ['unit' => $labels[$rule->reservation_unit_id] ?? $rule->reservation_unit_id]),
                charges: match (true) {
                    $rule->transactionCode !== null => $rule->transactionCode->code.' '.$rule->transactionCode->name,
                    $rule->category !== null => $rule->category->label(),
                    default => __('All charges'),
                },
                target: $this->statement->optionLabel($rule->targetFolio, $labels),
                sourceLabel: $rule->source->label(),
                canRemove: $rule->source === RoutingRuleSource::Manual,
            ))
            ->values()
            ->all();

        $targets = Folio::query()
            ->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)
            ->where('status', FolioStatus::Open)
            ->with('payerGuest', 'payerCompany:id,name')
            ->get()
            ->sortBy(fn (Folio $folio): array => [
                match ($folio->type) {
                    FolioType::Master => 0,
                    FolioType::Unit => 1,
                    FolioType::ThirdParty => 2,
                },
                $folio->type === FolioType::Unit ? (int) array_search($folio->reservation_unit_id, array_keys($labels), true) : 0,
                $folio->id,
            ])
            ->map(fn (Folio $folio): FolioOptionData => new FolioOptionData($folio->id, $this->statement->optionLabel($folio, $labels)))
            ->values()
            ->all();

        return new RoutingRulesData(
            rules: $rules,
            categories: array_map(
                fn (TransactionCategory $category): array => ['value' => $category->value, 'label' => $category->label()],
                TransactionCategory::routableCases(),
            ),
            targets: $targets,
        );
    }
}
