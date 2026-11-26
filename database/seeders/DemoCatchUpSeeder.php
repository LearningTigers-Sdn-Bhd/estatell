<?php

namespace Database\Seeders;

use App\Actions\FrontDesk\AutoAssignBookedUnit;
use App\Actions\FrontDesk\CheckInUnit;
use App\Actions\FrontDesk\CheckOutAndRefund;
use App\Actions\FrontDesk\CheckOutUnit;
use App\Actions\FrontDesk\MarkNoShow;
use App\Actions\Guests\CreateGuest;
use App\Actions\Guests\UpdateGuest;
use App\Actions\NightAudit\RunNightAudit;
use App\Actions\Reservations\AttachGuest;
use App\Actions\Transfers\SaveTransferRoute;
use App\Actions\Transfers\SetUnitTransfer;
use App\Data\Reservations\CheckOutCollectData;
use App\Data\Transfers\UnitTransferInputData;
use App\Enums\AuditTrigger;
use App\Enums\PaymentMethodType;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Guest;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransferRoute;
use App\Models\UnitTransfer;
use App\Models\User;
use App\Services\FrontDesk\CheckOutSettlement;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Makes the two demo lodges (Boreno Hotel and Kopi Lane Hostel) look like hotels that ran every day, on top of the
 * other demo seeders. Every stay change goes through the production Actions:
 *
 * - **Stray routes**: Kopi Lane's test route "Airport to hostel" (priced with the Mount Kinabalu shuttle) hands its open
 *   transfers to "Airport to Kopi Lane" through `SetUnitTransfer`, which swaps the charge, and is turned off.
 * - **Booking dates**: bookings the seeders made in one go get a booking date a realistic lead time before arrival
 *   (`reservations.created_at` and the `reservation.created` activity), so "New bookings" and the pick-up reports read
 *   like real days. There is no Action for this: it is demo history only.
 * - **Travelling parties**: about 60% of the rooms of two or more people in the window name their other guests, with a
 *   nationality and a date of birth (`CreateGuest`, `AttachGuest`), and bookers get a phone (`UpdateGuest`).
 * - **Catch-up**: each day from the business date to yesterday, arrivals that were never checked in become no-shows,
 *   the day's arrivals check in (`CheckInUnit`), the day's departures pay their balance in cash and check out
 *   (`CheckOutAndRefund`), and the night audit closes the day (`RunNightAudit`).
 * - **Today**: about 60% of today's departures have left and 40% of today's arrivals are in, as at midday.
 *
 * Run it after the other demo seeders, and again whenever the demo's day moves on: `php artisan db:seed
 * --class=DemoCatchUpSeeder`. It refuses production. A second run on the same day only touches what is left: bookings
 * still dated in the last four days, and rooms whose party is not yet named.
 */
class DemoCatchUpSeeder extends Seeder
{
    /** @var list<string> */
    private const array PROPERTIES = ['Boreno Hotel', 'Kopi Lane Hostel'];

    /** Test routes left on a lodge, and the seeded route that takes over their transfers. */
    private const array STRAY_ROUTES = ['Kopi Lane Hostel' => ['Airport to hostel' => 'Airport to Kopi Lane']];

    /** How many days after the business date get named travelling parties. */
    private const int PARTY_DAYS = 14;

    /** @var list<array{0: string, 1: string, 2: string}> First name, last name, nationality. */
    private const array COMPANIONS = [
        ['Sarah', 'Müller', 'DE'], ['Tom', 'Becker', 'DE'], ['Emily', 'Clarke', 'GB'], ['James', 'Hughes', 'GB'],
        ['Chloe', 'Martin', 'FR'], ['Lucas', 'Bernard', 'FR'], ['Mia', 'Jansen', 'NL'], ['Noah', 'de Vries', 'NL'],
        ['Olivia', 'Smith', 'AU'], ['Jack', 'Brown', 'AU'], ['Haruka', 'Ito', 'JP'], ['Ren', 'Kobayashi', 'JP'],
        ['Seo-yeon', 'Lee', 'KR'], ['Ji-ho', 'Choi', 'KR'], ['Wei', 'Zhang', 'CN'], ['Xin', 'Liu', 'CN'],
        ['Nur', 'Aisyah', 'MY'], ['Amir', 'Hakim', 'MY'], ['Mei Ling', 'Ong', 'SG'], ['Ethan', 'Tan', 'SG'],
        ['Ava', 'Miller', 'US'], ['Liam', 'Garcia', 'US'], ['Sofia', 'Rossi', 'IT'], ['Mateo', 'Fernández', 'ES'],
    ];

    /** @var array<string, int> */
    private array $counts = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoCatchUpSeeder writes demo history and never runs in production.');
        }

        foreach (self::PROPERTIES as $name) {
            $property = Property::query()->where('name', $name)->with('settings')->first();

            if ($property === null) {
                $this->command?->warn("{$name} is not on this database; skipped.");

                continue;
            }

            mt_srand(crc32($name.'catch-up'));
            setPermissionsTeamId($property->id);
            $this->counts = [];
            $owner = $this->owner($property);
            $today = app(PropertyToday::class)->date($property);

            $this->foldStrayRoutes($property, $owner);
            $this->spreadBookingDates($property, $today);
            $this->nameParties($property, $owner, $today);
            $this->catchUp($property, $owner, $today);
            $this->midday($property, $owner, $today);

            $done = collect($this->counts)->map(fn (int $count, string $what): string => "{$what} {$count}")->implode(', ');
            $date = app(CurrentBusinessDate::class)->date($property)?->toDateString();
            $this->command?->info("{$name} (business date {$date}): ".($done === '' ? 'nothing to do' : $done).'.');
        }
    }

    private function foldStrayRoutes(Property $property, User $owner): void
    {
        foreach (self::STRAY_ROUTES[$property->name] ?? [] as $strayName => $keepName) {
            $stray = TransferRoute::query()->where('property_id', $property->id)->where('name', $strayName)->first();
            $keep = TransferRoute::query()->where('property_id', $property->id)->where('name', $keepName)->where('active', true)->first();

            if ($stray === null || $keep === null || ! $stray->active) {
                continue;
            }

            $transfers = UnitTransfer::query()
                ->where('transfer_route_id', $stray->id)
                ->whereHas('reservationUnit', fn ($units) => $units->whereIn('status', [UnitStatus::Confirmed->value, UnitStatus::CheckedIn->value]))
                ->with('reservationUnit')
                ->get();

            foreach ($transfers as $transfer) {
                $result = app(SetUnitTransfer::class)($owner, $transfer->reservationUnit, new UnitTransferInputData(
                    direction: $transfer->direction,
                    mode: $transfer->mode,
                    routeId: $keep->id,
                    pickupTime: substr($transfer->pickup_time, 0, 5),
                    flightNumber: $transfer->flight_number,
                    note: $transfer->note,
                    bags: $transfer->bags,
                ));
                $this->count($result->isRefused() ? "route move refused ({$result->refusal})" : 'transfers moved to '.$keepName);
            }

            app(SaveTransferRoute::class)($owner, $property, [
                'direction' => $stray->direction->value,
                'name' => $stray->name,
                'place' => $stray->place,
                'minutes' => $stray->minutes,
                'shared_charge_item_id' => $stray->shared_charge_item_id,
                'private_charge_item_id' => $stray->private_charge_item_id,
                'active' => false,
            ], $stray);
            $this->count('stray routes turned off');
        }
    }

    /**
     * A booking the seeders made today for a later stay gets a booking date before it: OTA and online bookings a few
     * days to six weeks ahead, direct ones up to two months, agents up to three. Never before 1 Jan 2026.
     */
    private function spreadBookingDates(Property $property, CarbonImmutable $today): void
    {
        $bookings = DB::table('reservations')
            ->join('booking_sources', 'booking_sources.id', '=', 'reservations.booking_source_id')
            ->where('reservations.property_id', $property->id)
            ->where('reservations.created_at', '>=', $today->subDays(4)->startOfDay())
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('reservation_units')
                ->whereColumn('reservation_units.reservation_id', 'reservations.id')
                ->whereRaw('reservation_units.arrival_date > reservations.created_at::date + 1'))
            ->selectRaw('reservations.id, booking_sources.type as source_type, (select min(arrival_date) from reservation_units where reservation_id = reservations.id) as arrival')
            ->get();

        foreach ($bookings as $booking) {
            $arrival = CarbonImmutable::parse($booking->arrival);
            $longest = match ($booking->source_type) {
                'company' => 90,
                'ota' => 45,
                default => 60,
            };
            $made = $arrival->subDays(mt_rand(1, $longest));

            // A stay further out than the booking window was booked some time in the last five months; a few today.
            if ($made->toDateString() >= $today->toDateString()) {
                $made = mt_rand(1, 100) <= 2 ? $today : $today->subDays(mt_rand(1, 150));
            }

            $made = $made->setTime(mt_rand(8, 21), mt_rand(0, 59))->max(CarbonImmutable::parse('2026-01-01 09:00'));

            DB::table('reservations')->where('id', $booking->id)->update(['created_at' => $made]);
            DB::table('activity_log')
                ->where('subject_type', (new Reservation)->getMorphClass())
                ->where('subject_id', $booking->id)
                ->where('event', 'reservation.created')
                ->update(['created_at' => $made, 'updated_at' => $made]);
            $this->count('booking dates spread');
        }
    }

    private function nameParties(Property $property, User $owner, CarbonImmutable $today): void
    {
        $units = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('stay_type', StayType::Overnight->value)
            ->whereIn('status', [UnitStatus::Confirmed->value, UnitStatus::CheckedIn->value])
            ->whereBetween('arrival_date', [$today->subDays(7)->toDateString(), $today->addDays(self::PARTY_DAYS)->toDateString()])
            ->with(['guests.guest', 'reservation.booker'])
            ->orderBy('id')
            ->get();

        foreach ($units as $unit) {
            $booker = $unit->reservation->booker;

            if ($booker !== null && blank($booker->phone)) {
                app(UpdateGuest::class)($owner, $booker, ['phone' => '+60 1'.mt_rand(1, 9).' '.mt_rand(100, 999).' '.mt_rand(1000, 9999)]);
                $this->count('booker phones');
            }

            $people = $unit->adults + $unit->children;
            $named = $unit->guests->count();

            if ($people < 2 || $named >= $people || mt_rand(1, 100) > 60) {
                continue;
            }

            $lead = $unit->guests->firstWhere('is_primary', true)->guest ?? $booker;

            if ($lead !== null && $lead->date_of_birth === null) {
                app(UpdateGuest::class)($owner, $lead, ['date_of_birth' => $today->subYears(mt_rand(24, 58))->subDays(mt_rand(0, 364))->toDateString()]);
            }

            foreach (range($named + 1, $people) as $position) {
                [$first, $last, $nationality] = self::COMPANIONS[mt_rand(0, count(self::COMPANIONS) - 1)];
                $isChild = $position > $unit->adults;
                $guest = app(CreateGuest::class)($owner, $property, [
                    'full_name' => "{$first} {$last}",
                    'nationality_code' => $nationality,
                    'date_of_birth' => $today->subYears($isChild ? mt_rand(4, 15) : mt_rand(20, 60))->subDays(mt_rand(0, 364))->toDateString(),
                ]);
                $result = app(AttachGuest::class)($owner, $unit, $guest);
                $this->count($result->isRefused() ? "companion refused ({$result->refusal})" : 'companions named');
            }
        }
    }

    /**
     * Each business date before today: no-shows, arrivals, departures, then the night audit.
     */
    private function catchUp(Property $property, User $owner, CarbonImmutable $today): void
    {
        for ($guard = 0; $guard < 60; $guard++) {
            $date = app(CurrentBusinessDate::class)->date($property);

            if ($date === null || $date->toDateString() >= $today->toDateString()) {
                return;
            }

            $this->runDay($property, $owner, $date, arrivalsShare: 100, departuresShare: 100);
            $result = app(RunNightAudit::class)($property, AuditTrigger::Manual, $owner);

            if ($result->isRefused()) {
                $result = app(RunNightAudit::class)($property, AuditTrigger::Manual, $owner, force: true, forceReason: 'Demo catch-up');
            }

            $this->count($result->isRefused() ? "audit refused ({$result->refusal})" : 'night audits');

            if ($result->isRefused()) {
                return;
            }
        }
    }

    private function midday(Property $property, User $owner, CarbonImmutable $today): void
    {
        $date = app(CurrentBusinessDate::class)->date($property);

        if ($date !== null && $date->toDateString() === $today->toDateString()) {
            $this->runDay($property, $owner, $date, arrivalsShare: 40, departuresShare: 60);
        }
    }

    private function runDay(Property $property, User $owner, CarbonImmutable $date, int $arrivalsShare, int $departuresShare): void
    {
        $day = $date->toDateString();

        $missed = ReservationUnit::query()->where('property_id', $property->id)->where('status', UnitStatus::Confirmed->value)
            ->where('arrival_date', '<', $day)->orderBy('id')->get();

        foreach ($missed as $unit) {
            $result = app(MarkNoShow::class)($owner, $unit, doNotCharge: true, reason: 'Did not arrive');
            $this->count($result->isRefused() ? "no-show refused ({$result->refusal})" : 'no-shows');
        }

        $leaving = ReservationUnit::query()->where('property_id', $property->id)->where('status', UnitStatus::CheckedIn->value)
            ->where('departure_date', '<=', $day)->orderBy('departure_time')->orderBy('id')->get();

        foreach ($leaving as $unit) {
            if (mt_rand(1, 100) <= $departuresShare || $unit->departure_date->lessThan($date)) {
                $this->checkOut($owner, $unit, $property);
            }
        }

        $arriving = ReservationUnit::query()->where('property_id', $property->id)->where('status', UnitStatus::Confirmed->value)
            ->where('arrival_date', $day)->where('departure_date', '>', $day)->orderBy('arrival_time')->orderBy('id')->get();

        foreach ($arriving as $unit) {
            if (mt_rand(1, 100) <= $arrivalsShare) {
                $this->checkIn($owner, $unit);
            }
        }
    }

    private function checkIn(User $owner, ReservationUnit $unit): void
    {
        app(AutoAssignBookedUnit::class)($owner, $unit);
        $result = app(CheckInUnit::class)($owner, $unit->refresh(), withoutCard: true);

        if ($result->isRefused() && $result->refusal === 'nationality_required') {
            $guest = $unit->guests()->with('guest')->get()->firstWhere('is_primary', true)->guest ?? $unit->reservation->booker;

            if ($guest instanceof Guest) {
                app(UpdateGuest::class)($owner, $guest, ['nationality_code' => 'MY']);
                $result = app(CheckInUnit::class)($owner, $unit->refresh(), withoutCard: true);
            }
        }

        $this->count($result->isRefused() ? "check-in refused ({$result->refusal})" : 'check-ins');
    }

    private function checkOut(User $owner, ReservationUnit $unit, Property $property): void
    {
        $settlement = app(CheckOutSettlement::class);
        $balance = $settlement->balance($settlement->folios($unit));
        $cash = PaymentMethod::query()->where('property_id', $property->id)->where('type', PaymentMethodType::Cash->value)->orderBy('id')->first();
        $collect = $balance->isPositive() && $cash !== null
            ? new CheckOutCollectData($cash->id, (string) $balance->toScale(2), null, (string) Str::uuid())
            : null;

        $result = app(CheckOutAndRefund::class)($owner, $unit, collect: $collect, idempotencyKey: (string) Str::uuid());

        if ($result->isRefused()) {
            $result = app(CheckOutUnit::class)($owner, $unit->refresh(), skipBalanceCheck: true);
        }

        $this->count($result->isRefused() ? "check-out refused ({$result->refusal})" : 'check-outs');
    }

    private function owner(Property $property): User
    {
        return User::query()->memberOf($property)->get()->first(fn (User $user): bool => $user->hasRole('Owner'))
            ?? throw new RuntimeException("{$property->name} has no owner.");
    }

    private function count(string $what): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + 1;
    }
}
