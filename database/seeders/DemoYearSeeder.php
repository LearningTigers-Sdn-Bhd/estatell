<?php

namespace Database\Seeders;

use App\Actions\Rates\SaveRates;
use App\Actions\Reservations\CreateBooking;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\BookingSource;
use App\Models\DailyStatistic;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Role;
use App\Models\RoomType;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A year of demo business for the demo properties of the Demo group (Boreno Hotel, Kopi Lane Hostel and the three lodges of `DemoGroupLodgesSeeder`), so the
 * group dashboard, forecast and reports have something real to show.
 *
 * - **The next 12 months**: bookings made through `CreateBooking`, as staff would, with rooms assigned by
 *   `auto_assign_rooms`. Occupancy follows Sabah's seasons (December, the school holidays and Chinese New Year are
 *   busiest; March to May and October are quiet) with busier weekends, and thins out further ahead, as bookings on hand
 *   do: about 80% of the final figure a month out, 30% at six months, 12% a year out.
 * - **The last 12 months**: night audit statistics (`daily_statistics`) on the same seasons, for the dates before the
 *   property's first closed date, so the forecast has a last year to compare against. No folios are written for them.
 *
 * Run it on a local or staging database only: `php artisan db:seed --class=DemoYearSeeder`. It touches only the
 * properties named here, refuses anything else, and the same run gives the same data (a fixed random seed).
 */
class DemoYearSeeder extends Seeder
{
    /** @var list<string> */
    private const array PROPERTIES = ['Boreno Hotel', 'Kopi Lane Hostel', 'Seri Pagi Hotel', 'Kinabalu Mist Homestay', 'Likas Bay Suites'];

    /** Base occupancy by month (1 = January), Kota Kinabalu leisure demand. */
    private const array SEASON = [1 => 0.72, 2 => 0.74, 3 => 0.60, 4 => 0.58, 5 => 0.62, 6 => 0.78, 7 => 0.82, 8 => 0.86, 9 => 0.66, 10 => 0.60, 11 => 0.64, 12 => 0.90];

    /** @var list<array{0: string, 1: string, 2: string}> First name, last name, nationality. */
    private const array PEOPLE = [
        ['Aisyah', 'Rahman', 'MY'], ['Wei Ling', 'Tan', 'MY'], ['Daniel', 'Lim', 'MY'], ['Nurul', 'Hassan', 'MY'], ['Kumar', 'Raj', 'MY'],
        ['Jason', 'Wong', 'MY'], ['Siti', 'Aminah', 'MY'], ['Farid', 'Ismail', 'MY'], ['Grace', 'Chong', 'MY'], ['Hafiz', 'Yusof', 'MY'],
        ['Mei', 'Chen', 'SG'], ['Ryan', 'Ng', 'SG'], ['Hiroshi', 'Sato', 'JP'], ['Yuki', 'Tanaka', 'JP'], ['Min-jun', 'Kim', 'KR'],
        ['Ji-woo', 'Park', 'KR'], ['Li', 'Wei', 'CN'], ['Zhang', 'Min', 'CN'], ['Emma', 'Brown', 'GB'], ['Oliver', 'Smith', 'GB'],
        ['Lukas', 'Müller', 'DE'], ['Sophie', 'Martin', 'FR'], ['Jack', 'Wilson', 'AU'], ['Chloe', 'Taylor', 'AU'], ['Liam', 'Johnson', 'US'],
        ['Ava', 'Davis', 'US'], ['Maria', 'Santos', 'PH'], ['Budi', 'Santoso', 'ID'], ['Anh', 'Nguyen', 'VN'], ['Somchai', 'Suksawat', 'TH'],
    ];

    /** Booking sources by weight; Walk-in only for the coming week. */
    private const array SOURCES = ['Booking Engine' => 30, 'WhatsApp' => 22, 'Email' => 16, 'Phone' => 14, 'Company / Travel agent' => 12];

    private int $made = 0;

    /** @var array<string, int> */
    private array $refused = [];

    /** @var list<string> Only these properties, when set; otherwise every property listed here. */
    private array $only = [];

    /**
     * Run for these properties only (`DemoGroupLodgesSeeder` uses it so Boreno and Kopi Lane are left alone).
     *
     * @param  list<string>  $names
     */
    public function only(array $names): static
    {
        $this->only = $names;

        return $this;
    }

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoYearSeeder writes demo bookings and never runs in production.');
        }

        foreach (self::PROPERTIES as $name) {
            if ($this->only !== [] && ! in_array($name, $this->only, true)) {
                continue;
            }

            $property = Property::query()->where('name', $name)->with('settings')->first();

            if ($property === null) {
                $this->command?->warn("{$name} is not on this database; skipped.");

                continue;
            }

            mt_srand(crc32($name));
            $this->made = 0;
            $this->refused = [];
            $today = app(CurrentBusinessDate::class)->date($property) ?? CarbonImmutable::today();

            $history = $this->seedHistory($property, $today);
            $this->extendRates($property, $today);
            $this->seedBookings($property, $today);

            $refusals = $this->refused === [] ? 'none' : collect($this->refused)->map(fn (int $count, string $reason): string => "{$reason} {$count}")->implode(', ');
            $this->command?->info("{$name}: {$this->made} bookings for the next 12 months, {$history} days of last year's statistics. Refused: {$refusals}.");
        }
    }

    private function seedBookings(Property $property, CarbonImmutable $today): void
    {
        $owner = $this->owner($property);
        $roomTypes = RoomType::query()->where('property_id', $property->id)->with('rooms')->orderBy('position')->orderBy('id')->get();
        $sources = BookingSource::query()->where('property_id', $property->id)->get()->keyBy('name');

        foreach ($roomTypes as $roomType) {
            $isBed = $roomType->sell_unit === SellUnit::Bed;
            $capacity = $isBed
                ? (int) DB::table('beds')->whereIn('room_id', $roomType->rooms->modelKeys())->whereNull('archived_at')->count()
                : $roomType->rooms->count();
            $plan = RatePlan::query()
                ->where('property_id', $property->id)
                ->whereHas('roomTypes', fn ($query) => $query->whereKey($roomType->id))
                ->orderBy('id')
                ->first();

            if ($capacity === 0 || $plan === null) {
                continue;
            }

            /** @var array<string, int> $booked Units already sold on each night, so a second run only fills the gaps. */
            $booked = DB::table('unit_nights')
                ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
                ->where('reservation_units.property_id', $property->id)
                ->where('reservation_units.room_type_id', $roomType->id)
                ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
                ->where('unit_nights.date', '>', $today->toDateString())
                ->groupBy('unit_nights.date')
                ->selectRaw('unit_nights.date::text as night, count(*) as units')
                ->pluck('units', 'night')
                ->map(fn ($units): int => (int) $units)
                ->all();

            for ($lead = 1; $lead <= 365; $lead++) {
                $date = $today->addDays($lead);
                $target = (int) round($capacity * $this->occupancy($date) * $this->onHand($lead));
                $tries = 0;

                while (($booked[$date->toDateString()] ?? 0) < $target && $tries < 12) {
                    $tries++;
                    $nights = $isBed ? mt_rand(1, 3) : $this->weighted([1 => 25, 2 => 35, 3 => 25, 4 => 10, 5 => 5]);
                    $units = $isBed ? $this->weighted([1 => 55, 2 => 30, 3 => 10, 4 => 5]) : $this->weighted([1 => 88, 2 => 12]);
                    $units = min($units, max(1, $target - ($booked[$date->toDateString()] ?? 0)));

                    if (! $this->book($owner, $property, $roomType, $plan, $sources, $date, $nights, $units, $isBed, $lead)) {
                        break;
                    }

                    for ($night = 0; $night < $nights; $night++) {
                        $key = $date->addDays($night)->toDateString();
                        $booked[$key] = ($booked[$key] ?? 0) + $units;
                    }
                }
            }
        }
    }

    /**
     * @param  Collection<string, BookingSource>  $sources
     */
    private function book(User $owner, Property $property, RoomType $roomType, RatePlan $plan, $sources, CarbonImmutable $arrival, int $nights, int $units, bool $isBed, int $lead): bool
    {
        $person = self::PEOPLE[mt_rand(0, count(self::PEOPLE) - 1)];
        $fullName = "{$person[0]} {$person[1]}";
        $sourceName = $lead <= 3 && mt_rand(1, 10) <= 3 ? 'Walk-in' : $this->weighted(self::SOURCES);
        $source = $sources->get($sourceName) ?? $sources->first();
        $adults = $isBed ? 1 : min($roomType->max_adults, $this->weighted([1 => 25, 2 => 65, 3 => 10]));

        $result = app(CreateBooking::class)($owner, $property, new NewBookingData(
            bookingSource: $source,
            units: [new NewUnitData(
                roomTypeId: $roomType->id,
                ratePlanId: $plan->id,
                arrivalDate: $arrival,
                departureDate: $arrival->addDays($nights),
                adults: $adults,
                beds: $isBed ? $units : 1,
            ), ...($isBed ? [] : array_fill(0, $units - 1, new NewUnitData(
                roomTypeId: $roomType->id,
                ratePlanId: $plan->id,
                arrivalDate: $arrival,
                departureDate: $arrival->addDays($nights),
                adults: $adults,
                beds: 1,
            )))],
            guests: [new NewBookingGuestData(profile: [
                'full_name' => $fullName,
                'email' => mb_strtolower(str_replace(' ', '.', $fullName)).mt_rand(10, 999).'@example.com',
                'nationality_code' => $person[2],
            ], unitIndex: 0)],
        ));

        if ($result->isRefused()) {
            $reason = (string) $result->refusal;
            $this->refused[$reason] = ($this->refused[$reason] ?? 0) + 1;

            return false;
        }

        $this->made++;

        return true;
    }

    /**
     * Carry each plan's prices on to 400 days ahead, by weekday, from the last week of prices it has. The demo
     * seeders load rates for 200 days only. Package and derived plans take their prices from another plan and are left.
     */
    private function extendRates(Property $property, CarbonImmutable $today): void
    {
        $owner = $this->owner($property);
        $until = $today->addDays(400);

        foreach (RatePlan::query()->where('property_id', $property->id)->with('roomTypes')->get() as $plan) {
            foreach ($plan->roomTypes as $roomType) {
                $last = Rate::query()->where('rate_plan_id', $plan->id)->where('room_type_id', $roomType->id)->whereNotNull('amount')->orderByDesc('date')->limit(7)->get();

                if ($last->isEmpty() || $last->first()->date->gte($until)) {
                    continue;
                }

                $days = [];

                foreach ($last as $rate) {
                    $days[$rate->date->dayOfWeekIso] = ['amount' => $rate->amount, 'occupancy' => []];
                }

                app(SaveRates::class)($owner, $plan, $last->first()->date->addDay()->toDateString(), $until->toDateString(), [['room_type_id' => $roomType->id, 'days' => $days]]);
            }
        }
    }

    /**
     * Statistics for the 365 days before the property's first closed date (or today), where none exist yet. Nothing
     * when last year is already covered.
     */
    private function seedHistory(Property $property, CarbonImmutable $today): int
    {
        $first = DailyStatistic::query()->where('property_id', $property->id)->min('business_date');

        if ($first !== null && CarbonImmutable::parse($first)->lte($today->subDays(365))) {
            return 0;
        }

        $end = $first === null ? $today->subDay() : CarbonImmutable::parse($first)->subDay();
        $rooms = (int) DB::table('rooms')->join('room_types', 'room_types.id', '=', 'rooms.room_type_id')
            ->where('rooms.property_id', $property->id)->where('room_types.sell_unit', SellUnit::Room->value)->count();
        $beds = (int) DB::table('beds')->join('rooms', 'rooms.id', '=', 'beds.room_id')
            ->where('rooms.property_id', $property->id)->whereNull('beds.archived_at')->count();
        $adr = $beds > $rooms ? 55.0 : 260.0;
        $rows = [];

        for ($date = $end->subDays(364); $date->lte($end); $date = $date->addDay()) {
            $occupancy = min(0.98, max(0.15, $this->occupancy($date) + (mt_rand(-8, 8) / 100)));
            $roomsSold = (int) round($rooms * $occupancy);
            $bedsSold = (int) round($beds * $occupancy);
            $sold = $roomsSold + $bedsSold;
            $available = $rooms + $beds;
            $rate = round($adr * (0.85 + $this->occupancy($date) * 0.3), 2);

            $rows[] = [
                'property_id' => $property->id,
                'business_date' => $date->toDateString(),
                'rooms_available' => $rooms,
                'rooms_sold' => $roomsSold,
                'beds_available' => $beds,
                'beds_sold' => $bedsSold,
                'occupancy' => number_format($available === 0 ? 0 : $sold / $available * 100, 2, '.', ''),
                'adr' => number_format($rate, 2, '.', ''),
                'revpar' => number_format($available === 0 ? 0 : $rate * $sold / $available, 2, '.', ''),
                'arrivals' => (int) round($sold / 2.4),
                'departures' => (int) round($sold / 2.4),
                'no_shows' => mt_rand(0, 10) === 0 ? 1 : 0,
                'in_house_guests' => (int) round($roomsSold * 1.8 + $bedsSold),
                'day_use_sold' => 0,
                'day_use_revenue' => '0.00',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('daily_statistics')->insertOrIgnore($rows);

        return count($rows);
    }

    /** The season's occupancy for the night, with weekends busier and the Christmas to New Year week fullest. */
    private function occupancy(CarbonImmutable $date): float
    {
        $base = self::SEASON[$date->month];
        $weekend = in_array($date->dayOfWeekIso, [5, 6], true) ? 0.08 : 0.0;
        $peak = ($date->month === 12 && $date->day >= 20) || ($date->month === 1 && $date->day <= 2) ? 0.06 : 0.0;

        return min(0.97, $base + $weekend + $peak);
    }

    /** The share of a night's final occupancy already on the books `$lead` days ahead. */
    private function onHand(int $lead): float
    {
        return max(0.12, exp(-$lead / 150));
    }

    /**
     * @template TKey of int|string
     *
     * @param  array<TKey, int>  $weights
     * @return TKey
     */
    private function weighted(array $weights): int|string
    {
        $pick = mt_rand(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            $pick -= $weight;

            if ($pick <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }

    private function owner(Property $property): User
    {
        setPermissionsTeamId($property->id);
        $role = Role::query()->where('property_id', $property->id)->where('name', 'Owner')->firstOrFail();
        $owner = User::query()
            ->whereHas('roles', fn ($query) => $query->whereKey($role->id))
            ->first();

        if ($owner === null) {
            throw new RuntimeException("{$property->name} has no owner to book as.");
        }

        return $owner;
    }
}
