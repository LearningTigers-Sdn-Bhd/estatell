<?php

namespace App\Actions\Onboarding\Wastays;

use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\SaveRates;
use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Onboarding\Wastays\ImportLinks;
use App\Services\Onboarding\Wastays\WastaysBundle;
use App\Services\Onboarding\Wastays\WastaysCodeMap;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Imports the other per-room plans of a wastays hotel such as Hotel Asia City: one plan per name (wastays repeats a plan
 * for every room type) with a fixed price for each room type, and a Day use plan when wastays sets hours. Standard,
 * Walk-in and Corporate are not here: the setup import keeps them as Basic Rate and its derived plans. A price that is
 * not fixed is skipped with a note. Company access is not imported: every plan is open to everyone. See
 * docs/migration-plan/hotel-asia-city.md "Rate plans".
 */
class ImportWastaysFixedRatePlans
{
    private const string RATES_UNTIL = '2027-12-31';

    public function __construct(
        private CreateRatePlan $createRatePlan,
        private SaveRates $saveRates,
        private RecordImportLink $recordImportLink,
        private ImportLinks $links,
    ) {}

    /**
     * Whether the bundle has an active per-room plan the Standard, Walk-in and Corporate import cannot place.
     */
    public static function applies(WastaysBundle $bundle, WastaysCodeMap $map): bool
    {
        return $bundle->rows('rate_plans')->contains(fn (array $row): bool => blank($row['archived_at'] ?? null)
            && ($row['sell_mode'] ?? 'per_room') === 'per_room'
            && $map->ratePlanKey((string) $row['name']) === null);
    }

    /**
     * @param  array<string, RoomType>  $roomTypes  by wastays room type id
     * @param  bool  $grossPrices  the wastays prices include SST, so they are divided by it
     */
    public function __invoke(WastaysBundle $bundle, Property $property, User $actor, array $roomTypes, bool $grossPrices, WastaysCodeMap $map, WastaysSetupSummary $summary): void
    {
        $links = $bundle->rows('room_type_rate_plans')->collect()->groupBy('rate_plan_id');
        $basePrices = $bundle->rows('room_types')->collect()->pluck('base_price', 'id');

        $groups = $bundle->rows('rate_plans')->collect()
            ->filter(fn (array $row): bool => blank($row['archived_at'] ?? null) && $map->ratePlanKey((string) $row['name']) === null)
            ->groupBy(fn (array $row): string => trim((string) $row['name']));

        foreach ($groups as $name => $copies) {
            $this->importPlan($property, $actor, (string) $name, $copies, $links, $basePrices, $roomTypes, $grossPrices, $bundle->manifest->firstAuditDate, $map, $summary);
        }
    }

    /**
     * @param  Collection<int, array<string, string|null>>  $copies
     * @param  Collection<string, Collection<int, array<string, string|null>>>  $links
     * @param  Collection<string, string|null>  $basePrices
     * @param  array<string, RoomType>  $roomTypes
     */
    private function importPlan(Property $property, User $actor, string $name, Collection $copies, Collection $links, Collection $basePrices, array $roomTypes, bool $grossPrices, string $from, WastaysCodeMap $map, WastaysSetupSummary $summary): void
    {
        $hours = (int) $copies->pluck('day_use_hours')->filter()->first();
        $priced = [];
        $pending = [];

        foreach ($copies as $copy) {
            foreach ($links->get($copy['id'], collect()) as $link) {
                $roomType = $roomTypes[(string) $link['room_type_id']] ?? null;
                $value = ($link['pricing_mode'] ?? null) === 'fixed' ? ($link['pricing_value'] ?: $basePrices->get($link['room_type_id'])) : null;

                if ($this->links->has($property, 'room_type_rate_plans', (string) $link['id'])) {
                    $summary->count('Rates', 'linked');
                } elseif ($roomType === null || blank($value)) {
                    $summary->skip('Rates', "{$name}: room type {$link['room_type_id']} has no fixed price");
                } else {
                    $amount = $grossPrices ? $map->netPrice((string) $value) : number_format((float) $value, 2, '.', '');
                    $day = $hours > 0 ? ['amount' => null, 'hours' => [['hours' => $hours, 'amount' => $amount]], 'occupancy' => []] : ['amount' => $amount, 'occupancy' => []];
                    $priced[] = ['room_type_id' => $roomType->id, 'days' => array_fill_keys(range(1, 7), $day)];
                    $pending[] = (string) $link['id'];
                }
            }
        }

        $roomTypeIds = array_values(array_unique(array_column($priced, 'room_type_id')));
        $plan = $property->ratePlans()->active()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($plan === null && $roomTypeIds === []) {
            $summary->skip('Rate plans', "{$name} has no room type with a fixed price");

            return;
        }

        $plan ??= $this->create($property, $actor, $name, $hours, $roomTypeIds);

        foreach ($copies as $copy) {
            if ($this->links->has($property, 'rate_plans', (string) $copy['id'])) {
                $summary->count('Rate plans', 'linked');
            } else {
                ($this->recordImportLink)($property, 'rate_plans', (string) $copy['id'], $plan);
                $summary->count('Rate plans', 'created');
            }
        }

        if ($priced === []) {
            return;
        }

        if (($this->saveRates)($actor, $plan, $from, self::RATES_UNTIL, $priced) === false) {
            throw new RuntimeException("The rates could not be saved on {$plan->name}.");
        }

        foreach ($pending as $id) {
            ($this->recordImportLink)($property, 'room_type_rate_plans', $id, $plan);
            $summary->count('Rates', 'created');
        }

        foreach ($copies as $copy) {
            if (($copy['ta_access'] ?? 'hidden') !== 'hidden') {
                $summary->skip('Rate plans', "{$name} is limited to companies in wastays; it is open to everyone here");

                break;
            }
        }
    }

    /**
     * @param  list<int>  $roomTypeIds
     */
    private function create(Property $property, User $actor, string $name, int $hours, array $roomTypeIds): RatePlan
    {
        $attributes = $hours > 0
            ? ['name' => $name, 'pricing_basis' => PricingBasis::DayUse->value, 'day_use_min_hours' => $hours, 'day_use_max_hours' => $hours]
            : ['name' => $name, 'pricing_basis' => PricingBasis::PerRoom->value];

        return ($this->createRatePlan)($property, $actor, $attributes, $roomTypeIds)
            ?? throw new RuntimeException("The rate plan {$name} could not be created.");
    }
}
