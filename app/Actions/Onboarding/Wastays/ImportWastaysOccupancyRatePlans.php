<?php

namespace App\Actions\Onboarding\Wastays;

use App\Actions\Rates\ChangeRatePlanBasis;
use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\SaveRatePlanChildPrices;
use App\Actions\Rates\SaveRatePlanStayDiscounts;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\SetRatePlanCompanyAccess;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Enums\AgeBandPricing;
use App\Enums\CompanyAccess;
use App\Enums\CompanyRateMarket;
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
 * Imports the per-person rate plans of a wastays hotel such as Sand Bay: plans with a price per number of adults for
 * each room type. The copies of one plan (wastays repeats a plan for every room type) become one per-guest plan that
 * sells all their room types, and wastays "Base Rate" joins "Standard Rate". The Standard Rate plan is the seeded
 * Basic Rate, switched to per guest. Companies are not in the bundle, so a plan limited to companies is left with an
 * empty list. See docs/migration-plan/sand-bay-resort-semporna.md "Rate plans".
 */
class ImportWastaysOccupancyRatePlans
{
    private const string RATES_UNTIL = '2027-12-31';

    public function __construct(
        private CreateRatePlan $createRatePlan,
        private ChangeRatePlanBasis $changeRatePlanBasis,
        private SyncRatePlanRoomTypes $syncRatePlanRoomTypes,
        private SaveRates $saveRates,
        private SaveRatePlanChildPrices $saveChildPrices,
        private SaveRatePlanStayDiscounts $saveStayDiscounts,
        private SetRatePlanCompanyAccess $setCompanyAccess,
        private RecordImportLink $recordImportLink,
        private ImportLinks $links,
    ) {}

    /**
     * Whether the bundle has an active plan the Standard, Walk-in and Corporate import cannot place.
     */
    public static function applies(WastaysBundle $bundle, WastaysCodeMap $map): bool
    {
        return $bundle->rows('rate_plans')->contains(fn (array $row): bool => blank($row['archived_at'] ?? null) && $map->ratePlanKey((string) $row['name']) === null);
    }

    /**
     * @param  array<string, RoomType>  $roomTypes  by wastays room type id
     */
    public function __invoke(WastaysBundle $bundle, Property $property, User $actor, array $roomTypes, WastaysSetupSummary $summary): void
    {
        $plans = $bundle->rows('rate_plans')->all();
        $active = array_values(array_filter($plans, fn (array $row): bool => blank($row['archived_at'] ?? null)));
        for ($archived = count($active); $archived < count($plans); $archived++) {
            $summary->count('Rate plans', 'skipped');
        }

        $links = $bundle->rows('room_type_rate_plans')->collect()->groupBy('rate_plan_id');
        $occupancy = $bundle->rows('room_type_rate_plan_occupancy_prices')->collect()->groupBy('room_type_rate_plan_id');
        $bands = $bundle->rows('rate_plan_age_bands')->collect()->groupBy('rate_plan_id');
        $discounts = $bundle->rows('rate_plan_stay_discounts')->collect()->groupBy('rate_plan_id');
        $agency = $bundle->rows('rate_plan_agency_rules')->collect()->groupBy('rate_plan_id');

        $groups = collect($active)->groupBy(fn (array $row): string => $this->groupName((string) $row['name']));

        foreach ($groups as $name => $copies) {
            $plan = $this->plan($property, $actor, $name, $copies, $links, $roomTypes, $summary);

            $this->prices($bundle, $property, $actor, $plan, $copies, $links, $occupancy, $roomTypes, $summary);
            $this->childPrices($actor, $plan, $copies, $bands, $summary);
            $this->stayDiscounts($actor, $plan, $copies, $discounts);
            $this->companyAccess($actor, $plan, $copies, $agency, $summary);
        }
    }

    /**
     * The rows of the first copy that has any.
     *
     * @param  Collection<int, array<string, string|null>>  $copies
     * @param  Collection<string, Collection<int, array<string, string|null>>>  $byPlan
     * @return Collection<int, array<string, string|null>>|null
     */
    private function firstRows(Collection $copies, Collection $byPlan): ?Collection
    {
        foreach ($copies as $copy) {
            $rows = $byPlan->get($copy['id']);

            if ($rows !== null && $rows->isNotEmpty()) {
                return $rows;
            }
        }

        return null;
    }

    private function groupName(string $name): string
    {
        $name = trim($name);

        return mb_strtolower($name) === 'base rate' ? 'Standard Rate' : $name;
    }

    /**
     * @param  Collection<int, array<string, string|null>>  $copies
     * @param  Collection<string, Collection<int, array<string, string|null>>>  $links
     * @param  array<string, RoomType>  $roomTypes
     */
    private function plan(Property $property, User $actor, string $name, Collection $copies, Collection $links, array $roomTypes, WastaysSetupSummary $summary): RatePlan
    {
        $roomTypeIds = $copies
            ->flatMap(fn (array $copy) => $links->get($copy['id'], collect()))
            ->map(fn (array $link): int => $roomTypes[(string) $link['room_type_id']]->id)
            ->unique()
            ->values()
            ->all();
        $roomTypeIds = array_values(array_map(intval(...), $roomTypeIds));

        $isStandard = $name === 'Standard Rate';
        $existing = $isStandard
            ? ($property->ratePlans()->active()->whereIn('name', ['Basic Rate', 'Standard Rate'])->first())
            : $property->ratePlans()->active()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($isStandard) {
            $existing ?? throw new RuntimeException('The property has no Basic Rate plan.');
            if (! ($this->changeRatePlanBasis)($actor, $existing, PricingBasis::PerGuest)) {
                throw new RuntimeException('Basic Rate could not be switched to per guest.');
            }
            $roomTypeIds = array_values(array_unique(array_map(intval(...), [...$existing->roomTypes()->active()->pluck('room_types.id')->all(), ...$roomTypeIds])));
            if (! ($this->syncRatePlanRoomTypes)($actor, $existing, $roomTypeIds)) {
                throw new RuntimeException('Basic Rate could not be set to sell the imported room types.');
            }
        }

        $plan = $existing ?? ($this->createRatePlan)($property, $actor, ['name' => $name, 'pricing_basis' => PricingBasis::PerGuest->value], $roomTypeIds)
            ?? throw new RuntimeException("The rate plan {$name} could not be created.");

        foreach ($copies as $copy) {
            if ($this->links->has($property, 'rate_plans', (string) $copy['id'])) {
                $summary->count('Rate plans', 'linked');

                continue;
            }

            ($this->recordImportLink)($property, 'rate_plans', (string) $copy['id'], $plan);
            $summary->count('Rate plans', 'created');
        }

        return $plan->refresh();
    }

    /**
     * Daily rates from the first audit date, one call per plan. The 1-adult price is the amount, the rest are occupancy prices.
     *
     * @param  Collection<int, array<string, string|null>>  $copies
     * @param  Collection<string, Collection<int, array<string, string|null>>>  $links
     * @param  Collection<string, Collection<int, array<string, string|null>>>  $occupancy
     * @param  array<string, RoomType>  $roomTypes
     */
    private function prices(WastaysBundle $bundle, Property $property, User $actor, RatePlan $plan, Collection $copies, Collection $links, Collection $occupancy, array $roomTypes, WastaysSetupSummary $summary): void
    {
        $priced = [];
        $pending = [];

        foreach ($copies as $copy) {
            foreach ($links->get($copy['id'], collect()) as $link) {
                if ($this->links->has($property, 'room_type_rate_plans', (string) $link['id'])) {
                    $summary->count('Rates', 'linked');

                    continue;
                }

                $byAdults = $occupancy->get($link['id'], collect())->sortBy(fn (array $row): int => (int) $row['adults'])->values();

                if ($byAdults->isEmpty()) {
                    $summary->skip('Rates', "{$plan->name}: room type {$link['room_type_id']} has no occupancy prices");

                    continue;
                }

                $day = [
                    'amount' => number_format((float) $byAdults->first()['price'], 2, '.', ''),
                    'occupancy' => array_values($byAdults->map(fn (array $row): array => ['adults' => (int) $row['adults'], 'amount' => number_format((float) $row['price'], 2, '.', '')])->all()),
                ];

                $priced[] = ['room_type_id' => $roomTypes[(string) $link['room_type_id']]->id, 'days' => array_fill_keys(range(1, 7), $day)];
                $pending[] = (string) $link['id'];
            }
        }

        if ($priced === []) {
            return;
        }

        if (($this->saveRates)($actor, $plan, $bundle->manifest->firstAuditDate, self::RATES_UNTIL, $priced) === false) {
            throw new RuntimeException("The rates could not be saved on {$plan->name}.");
        }

        foreach ($pending as $id) {
            ($this->recordImportLink)($property, 'room_type_rate_plans', $id, $plan);
            $summary->count('Rates', 'created');
        }
    }

    /**
     * The first copy that has age bands sets the plan's child prices. The youngest band goes to the youngest age group.
     *
     * @param  Collection<int, array<string, string|null>>  $copies
     * @param  Collection<string, Collection<int, array<string, string|null>>>  $bands
     */
    private function childPrices(User $actor, RatePlan $plan, Collection $copies, Collection $bands, WastaysSetupSummary $summary): void
    {
        $source = $this->firstRows($copies, $bands);

        if ($source === null) {
            return;
        }

        $groups = $plan->property->ageGroups()->ordered()->get();
        $sorted = $source->sortBy(fn (array $band): int => (int) $band['min_age'])->values();
        $prices = [];

        foreach ($groups as $index => $group) {
            $band = $sorted->get($index);
            $value = (float) ($band['price_value'] ?? 0);
            $prices[] = match (true) {
                $band === null => ['property_age_group_id' => $group->id, 'pricing' => AgeBandPricing::Percent->value, 'value' => '50.00'],
                $value <= 0 => ['property_age_group_id' => $group->id, 'pricing' => AgeBandPricing::Free->value, 'value' => null],
                ($band['pricing_mode'] ?? null) === 'multiplier' => ['property_age_group_id' => $group->id, 'pricing' => AgeBandPricing::Percent->value, 'value' => (string) $value],
                default => ['property_age_group_id' => $group->id, 'pricing' => AgeBandPricing::Fixed->value, 'value' => (string) $value],
            };
        }

        if (! ($this->saveChildPrices)($actor, $plan, $prices)) {
            throw new RuntimeException("The child prices could not be saved on {$plan->name}.");
        }

        $summary->count('Child prices', 'created');
    }

    /**
     * @param  Collection<int, array<string, string|null>>  $copies
     * @param  Collection<string, Collection<int, array<string, string|null>>>  $discounts
     */
    private function stayDiscounts(User $actor, RatePlan $plan, Collection $copies, Collection $discounts): void
    {
        $rows = $this->firstRows($copies, $discounts);

        if ($rows === null) {
            return;
        }

        $list = array_values($rows->map(fn (array $row): array => [
            'min_nights' => (int) $row['min_nights'],
            'discount_type' => $row['discount_type'] === 'percent' ? 'percent' : 'amount',
            'value' => (string) ($row['value'] ?? 0),
            'from_night' => (int) ($row['from_night'] ?: 1),
        ])->all());

        if (! ($this->saveStayDiscounts)($actor, $plan, $list)) {
            throw new RuntimeException("The stay discounts could not be saved on {$plan->name}.");
        }
    }

    /**
     * hidden is Everyone; all is All companies; only is Only these companies; except with a named company means only that
     * company, and except with nobody means All companies. The listed companies come with the companies import.
     *
     * @param  Collection<int, array<string, string|null>>  $copies
     * @param  Collection<string, Collection<int, array<string, string|null>>>  $agency
     */
    private function companyAccess(User $actor, RatePlan $plan, Collection $copies, Collection $agency, WastaysSetupSummary $summary): void
    {
        $access = $copies->map(function (array $copy) use ($agency): CompanyAccess {
            $named = $agency->get($copy['id'], collect())->isNotEmpty();

            return match ($copy['ta_access'] ?? 'hidden') {
                'all' => CompanyAccess::All,
                'only' => CompanyAccess::Selected,
                'except' => $named ? CompanyAccess::Selected : CompanyAccess::All,
                default => CompanyAccess::Everyone,
            };
        })->unique()->values();

        $choice = $access->contains(CompanyAccess::Selected) ? CompanyAccess::Selected : ($access->contains(CompanyAccess::All) ? CompanyAccess::All : CompanyAccess::Everyone);
        $markets = $copies->map(fn (array $copy): string => (string) ($copy['ta_market'] ?? 'all'))->unique();
        $market = $markets->count() === 1 ? (CompanyRateMarket::tryFrom($markets->first()) ?? CompanyRateMarket::All) : CompanyRateMarket::All;

        if (($this->setCompanyAccess)($actor, $plan, $choice, true, true, $market) !== null) {
            throw new RuntimeException("The company access could not be set on {$plan->name}.");
        }

        if ($choice === CompanyAccess::Selected) {
            $ids = $copies->flatMap(fn (array $copy) => $agency->get($copy['id'], collect())->pluck('hotel_corporate_account_id'))->unique()->implode(', ');
            $summary->skip('Rate plans', "{$plan->name} is limited to wastays company ".($ids !== '' ? $ids : '(none named)').'; list it after the companies import');
        }
    }
}
