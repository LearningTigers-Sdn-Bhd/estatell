<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Reservations\CreateMigratedStay;
use App\Actions\Reservations\CreateOldStay;
use App\Actions\Reservations\CreatePastStay;
use App\Data\Property\CurrencyData;
use App\Enums\BillingMode;
use App\Enums\BoatDirection;
use App\Enums\Gender;
use App\Enums\Locale;
use App\Enums\OldFolioMode;
use App\Enums\PermissionEnum;
use App\Features\Boats;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\BoatSeatsRequest;
use App\Http\Requests\Reservations\DayUseOffersRequest;
use App\Http\Requests\Reservations\FreeUnitsRequest;
use App\Http\Requests\Reservations\QuoteBookingLineRequest;
use App\Http\Requests\Reservations\StoreBookingRequest;
use App\Http\Requests\Reservations\StorePastStayRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Availability\DayUseAvailability;
use App\Services\Availability\FreeUnits;
use App\Services\Boats\BoatSeats;
use App\Services\CityLedger\CompanyOptions;
use App\Services\Guests\CountryOptions;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Property\MigrationWindow;
use App\Services\Property\PropertyToday;
use App\Services\Rates\DayUseOffers;
use App\Services\Reservations\BookingErrorKeys;
use App\Services\Reservations\BookingFormBack;
use App\Services\Reservations\BookingPrefill;
use App\Services\Reservations\BookingQuote;
use App\Services\Reservations\BookingSourceOptions;
use App\Services\Reservations\ReservationFormOptions;
use App\Services\Reservations\ReservationRefusals;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class ReservationBookingController extends Controller
{
    /**
     * The booking form for a new booking.
     */
    public function new(Request $request, Property $property, ReservationFormOptions $options, CurrentBusinessDate $businessDate, PropertyToday $today, BookingSourceOptions $sourceOptions): Response
    {
        return $this->form($request, $property, $options, $businessDate, $today, $sourceOptions, walkIn: false, readsPrefill: true);
    }

    /**
     * The same form as a walk-in: the source is fixed, the arrival is the business date and a room or bed is required.
     */
    public function walkIn(Request $request, Property $property, ReservationFormOptions $options, CurrentBusinessDate $businessDate, PropertyToday $today, BookingSourceOptions $sourceOptions): Response
    {
        return $this->form($request, $property, $options, $businessDate, $today, $sourceOptions, walkIn: true);
    }

    /**
     * The same form as a past stay: a stay that began before the business date and is still in the house. Needs `folio.manual`.
     */
    public function past(Request $request, Property $property, ReservationFormOptions $options, CurrentBusinessDate $businessDate, PropertyToday $today, BookingSourceOptions $sourceOptions): Response
    {
        return $this->form($request, $property, $options, $businessDate, $today, $sourceOptions, walkIn: false, pastStay: true);
    }

    /**
     * The same form as a migrated stay: a guest who arrived before go-live and is in the house now. Needs `folio.manual`
     * and an open migration window; after it the booking form opens in Past stay with an error toast.
     */
    public function migrated(Request $request, Property $property, ReservationFormOptions $options, CurrentBusinessDate $businessDate, PropertyToday $today, BookingSourceOptions $sourceOptions, MigrationWindow $migrationWindow, ReservationRefusals $refusals): Response|RedirectResponse
    {
        if (! $migrationWindow->isOpen($property)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refusals->errors(BookingResult::REFUSAL_MIGRATION_WINDOW_CLOSED)[ReservationRefusals::FORM]]);

            return to_route('reservations.past', ['property' => $property]);
        }

        return $this->form($request, $property, $options, $businessDate, $today, $sourceOptions, walkIn: false, pastStay: true, migratedStay: true);
    }

    /**
     * The same form as an old stay: a stay that began and ended before go-live, kept for history only. Needs
     * `folio.manual` and an open migration window; after it the booking form opens in Past stay with an error toast.
     */
    public function old(Request $request, Property $property, ReservationFormOptions $options, CurrentBusinessDate $businessDate, PropertyToday $today, BookingSourceOptions $sourceOptions, MigrationWindow $migrationWindow, ReservationRefusals $refusals): Response|RedirectResponse
    {
        if (! $migrationWindow->isOpen($property)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refusals->errors(BookingResult::REFUSAL_MIGRATION_WINDOW_CLOSED)[ReservationRefusals::FORM]]);

            return to_route('reservations.past', ['property' => $property]);
        }

        return $this->form($request, $property, $options, $businessDate, $today, $sourceOptions, walkIn: false, pastStay: true, oldStay: true);
    }

    /**
     * Save a past stay or, with `mode` = migrated, a migrated stay, or, with `mode` = old, an old stay. A refusal goes back to the form as errors keyed by line, and nothing is saved. On success the
     * folio tab opens, with the nights already posted. A stay that is already checked out opens the manual folio page
     * with the reason filled in, so staff enter the payments and close the folio.
     */
    public function storePast(StorePastStayRequest $request, Property $property, CreatePastStay $createPastStay, CreateMigratedStay $createMigratedStay, CreateOldStay $createOldStay, BookingErrorKeys $errorKeys): RedirectResponse
    {
        if ($request->isOld()) {
            return $this->storeOld($request, $property, $createOldStay, $errorKeys);
        }

        if ($request->isMigrated()) {
            return $this->storeMigrated($request, $property, $createMigratedStay, $errorKeys);
        }

        $result = $createPastStay($request->user(), $property, $request->toBookingData(), $request->reason(), $request->withoutCard(), $request->checkedOut());

        if ($result->isRefused()) {
            return back(fallback: route('reservations.past', ['property' => $property]))->withErrors(
                $errorKeys->errors($result, $request->unitsPerLine(), $request->user()->can(PermissionEnum::RatesOverrideRestrictions->value)),
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Past stay :reference created.', ['reference' => $result->reservation->reference])]);

        if ($request->checkedOut()) {
            $folio = $result->reservation->folios()->orderBy('id')->firstOrFail();

            $request->session()->flash(ReservationFolioManualController::REASON_KEY, $request->reason());
            $request->session()->flash(ReservationFolioManualController::SETTLE_PROMPT_KEY, $folio->id);

            return to_route('reservations.folio', ['property' => $property, 'reservation' => $result->reservation, 'folio' => $folio->id]);
        }

        return to_route('reservations.folio', ['property' => $property, 'reservation' => $result->reservation]);
    }

    /**
     * Save an old stay. A refusal goes back to the form as errors. On success the reservation page opens: the stay is
     * history only, with a closed folio and no lines.
     */
    private function storeOld(StorePastStayRequest $request, Property $property, CreateOldStay $createOldStay, BookingErrorKeys $errorKeys): RedirectResponse
    {
        $result = $createOldStay($request->user(), $property, $request->toBookingData(), $request->reason(), $request->withoutCard());

        if ($result->isRefused()) {
            return back(fallback: route('reservations.old', ['property' => $property]))->withErrors(
                $errorKeys->errors($result, $request->unitsPerLine(), $request->user()->can(PermissionEnum::RatesOverrideRestrictions->value)),
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Old stay :reference created.', ['reference' => $result->reservation->reference])]);

        return to_route('reservations.show', ['property' => $property, 'reservation' => $result->reservation]);
    }

    /**
     * Save a migrated stay. A refusal goes back to the form as errors. On success the folio tab opens, with the nights
     * from go-live posted and the opening balance on the first unit's folio. Copy line by line saves the stay with no
     * opening line and opens the old folio page. A stay that is already checked out with One amount opens the folio tab with
     * the settle prompt, as a checked out past stay does.
     */
    private function storeMigrated(StorePastStayRequest $request, Property $property, CreateMigratedStay $createMigratedStay, BookingErrorKeys $errorKeys): RedirectResponse
    {
        $result = $createMigratedStay($request->user(), $property, $request->toBookingData(), $request->reason(), $request->oldFolio(), $request->openingAmount(), $request->legacyNumber(), $request->withoutCard(), $request->checkedOut());

        if ($result->isRefused()) {
            return back(fallback: route('reservations.migrated', ['property' => $property]))->withErrors(
                $errorKeys->errors($result, $request->unitsPerLine(), $request->user()->can(PermissionEnum::RatesOverrideRestrictions->value)),
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Migrated stay :reference created.', ['reference' => $result->reservation->reference])]);

        if ($request->oldFolio() === OldFolioMode::CopyLineByLine) {
            return to_route('reservations.old-folio', ['property' => $property, 'reservation' => $result->reservation]);
        }

        if ($request->checkedOut()) {
            $folio = $result->reservation->folios()->orderBy('id')->firstOrFail();

            $request->session()->flash(ReservationFolioManualController::REASON_KEY, $request->reason());
            $request->session()->flash(ReservationFolioManualController::SETTLE_PROMPT_KEY, $folio->id);

            return to_route('reservations.folio', ['property' => $property, 'reservation' => $result->reservation, 'folio' => $folio->id]);
        }

        return to_route('reservations.folio', ['property' => $property, 'reservation' => $result->reservation]);
    }

    /**
     * Save the booking. A refusal goes back to the form as errors keyed by line, and nothing is saved.
     */
    public function store(StoreBookingRequest $request, Property $property, CreateBooking $createBooking, BookingErrorKeys $errorKeys): RedirectResponse
    {
        $result = $createBooking($request->user(), $property, $request->toBookingData());

        if ($result->isRefused()) {
            return back(fallback: route('reservations.new', ['property' => $property]))->withErrors(
                $errorKeys->errors($result, $request->unitsPerLine(), $request->user()->can(PermissionEnum::RatesOverrideRestrictions->value)),
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reservation :reference created.', ['reference' => $result->reservation->reference])]);

        return to_route('reservations.show', ['property' => $property, 'reservation' => $result->reservation]);
    }

    /**
     * Price one booking line for the booking form. A line that cannot be priced answers 200 with `errors`.
     */
    public function quote(QuoteBookingLineRequest $request, Property $property, BookingQuote $bookingQuote): JsonResponse
    {
        if ($request->isDayUse()) {
            return response()->json($bookingQuote->dayUseLine(
                $property,
                $request->integer('room_type_id'),
                $request->integer('rate_plan_id'),
                $request->date('arrival_date', 'Y-m-d')->toImmutable(),
                $request->string('start_time')->toString(),
                $request->integer('hours'),
                $request->integer('adults'),
                array_values(array_map(intval(...), $request->validated('child_ages') ?? [])),
                $request->addOns(),
            ));
        }

        return response()->json($bookingQuote->line(
            $property,
            $request->integer('room_type_id'),
            $request->integer('rate_plan_id'),
            $request->date('arrival_date', 'Y-m-d')->toImmutable(),
            $request->date('departure_date', 'Y-m-d')->toImmutable(),
            $request->integer('adults'),
            array_values(array_map(intval(...), $request->validated('child_ages') ?? [])),
            $request->integer('beds', 1),
            $request->addOns(),
            $request->filled('override_total') ? $request->string('override_total')->toString() : null,
        ));
    }

    /**
     * The rooms or beds of a room type that are free on every night of a stay. An unknown room type has none.
     */
    public function freeUnits(FreeUnitsRequest $request, Property $property, FreeUnits $freeUnits, DayUseAvailability $dayUseAvailability): JsonResponse
    {
        $roomType = RoomType::query()->where('property_id', $property->id)->active()->find($request->integer('room_type_id'));

        if ($request->isDayUse()) {
            $startsAt = DayUseOffers::time(DayUseOffers::minutes($request->string('start_time')->toString()));
            $endsAt = DayUseOffers::endsAt($startsAt, $request->integer('hours'));

            return response()->json(['units' => $roomType === null || $endsAt === null ? [] : $dayUseAvailability->freeRooms(
                $roomType,
                $request->date('arrival_date', 'Y-m-d'),
                $startsAt,
                $endsAt,
            )]);
        }

        return response()->json(['units' => $roomType === null ? [] : $freeUnits->forStay(
            $roomType,
            $request->date('arrival_date', 'Y-m-d'),
            $request->date('departure_date', 'Y-m-d'),
        )]);
    }

    /**
     * The hours a day use plan sells on a date from a start time, with their prices and end times, for the Hours select.
     * A plan, room type or time that does not fit gives an empty list.
     */
    public function dayUseOffers(DayUseOffersRequest $request, Property $property, DayUseOffers $dayUseOffers): JsonResponse
    {
        $roomType = RoomType::query()->where('property_id', $property->id)->active()->find($request->integer('room_type_id'));
        $ratePlan = RatePlan::query()->where('property_id', $property->id)->active()->find($request->integer('rate_plan_id'));

        return response()->json(['offers' => $roomType === null || $ratePlan === null ? [] : $dayUseOffers->forStart(
            $ratePlan,
            $roomType,
            $request->date('date', 'Y-m-d'),
            $request->string('start_time')->toString(),
        )]);
    }

    /**
     * The seats taken on each available departure of one direction for one date, for the Boat in and Boat out pickers.
     * Counts only: a full boat is a warning in the form, never a refusal. Departures of another property do not appear.
     */
    public function boatSeats(BoatSeatsRequest $request, Property $property, BoatSeats $boatSeats): JsonResponse
    {
        $direction = BoatDirection::from($request->string('direction')->toString());
        $departureIds = array_values($property->boatDepartures()
            ->where('direction', $direction->value)
            ->where('active', true)
            ->pluck('id')
            ->map(intval(...))
            ->all());

        return response()->json(['seats' => (object) $boatSeats->forDate($direction, $request->date('date', 'Y-m-d')->toImmutable(), $departureIds)]);
    }

    private function form(Request $request, Property $property, ReservationFormOptions $options, CurrentBusinessDate $businessDate, PropertyToday $today, BookingSourceOptions $sourceOptions, bool $walkIn, bool $pastStay = false, bool $migratedStay = false, bool $oldStay = false, bool $readsPrefill = false): Response
    {
        $todayDate = $businessDate->date($property) ?? $today->date($property);
        $canManageGuests = $request->user()->can(PermissionEnum::GuestsManage->value);
        $companies = $request->user()->can(PermissionEnum::CompanyView->value) ? app(CompanyOptions::class)->active($property) : [];

        return Inertia::render('reservations/create', [
            'isWalkIn' => $walkIn,
            'isPastStay' => $pastStay,
            'isMigratedStay' => $migratedStay,
            'isOldStay' => $oldStay,
            'back' => app(BookingFormBack::class)->fromQuery($property, $request->query()),
            'prefill' => $readsPrefill ? app(BookingPrefill::class)->fromQuery($property, $request->query(), $todayDate) : null,
            'canEnterMigratedStay' => $request->user()->can(PermissionEnum::FolioManual->value) && app(MigrationWindow::class)->isOpen($property),
            'canEnterOldStay' => $request->user()->can(PermissionEnum::FolioManual->value) && app(MigrationWindow::class)->isOpen($property),
            'oldFolioModes' => OldFolioMode::options(),
            'goLiveDate' => (app(FirstBusinessDate::class)->date($property) ?? $today->date($property))->toDateString(),
            'canEnterPastStay' => $request->user()->can(PermissionEnum::FolioManual->value),
            'sources' => $sourceOptions->forBooking($property, $walkIn, canUseCompany: $companies !== []),
            'today' => $todayDate->toDateString(),
            'checkInTime' => substr($property->settings->check_in_time ?? '15:00', 0, 5),
            'checkOutTime' => substr($property->settings->check_out_time ?? '11:00', 0, 5),
            'nowTime' => CarbonImmutable::now($property->settings->time_zone ?? config('app.timezone'))->format('H:i'),
            'addUnitOptions' => $options->addUnit($property),
            'chargeItems' => $options->chargeItems($property),
            'discounts' => $options->roomDiscounts($property),
            'billingModes' => BillingMode::options(),
            'canOverrideCredit' => $request->user()->can(PermissionEnum::CompanyCreditOverride->value),
            'companies' => $companies,
            'hasDayUse' => $property->ratePlans()->active()->dayUse()->exists(),
            'hasBoats' => Feature::for($property)->active(Boats::class),
            'boatDepartures' => Feature::for($property)->active(Boats::class) ? $options->boatDepartures($property) : [],
            'autoAssignRooms' => (bool) $property->settings?->auto_assign_rooms,
            'currency' => CurrencyData::forProperty($property),
            'canOverrideRestrictions' => $request->user()->can(PermissionEnum::RatesOverrideRestrictions->value),
            'canManageGuests' => $canManageGuests,
            'countries' => $canManageGuests ? app(CountryOptions::class)->options() : [],
            'defaultNationalityCode' => $property->country_code ?? 'MY',
            'genders' => Gender::options(),
            'locales' => Locale::options(),
        ]);
    }
}
