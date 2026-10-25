<?php

namespace App\Actions\Onboarding\Wastays;

use App\Actions\Reservations\CreateBooking;
use App\Actions\Reservations\CreateBookingSource;
use App\Actions\Reservations\EnsureOtaBookingSource;
use App\Actions\Reservations\ReservationResult;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Enums\BillingMode;
use App\Enums\BookingSourceType;
use App\Enums\CompanyAccess;
use App\Enums\DocumentType;
use App\Models\BookingSource;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Onboarding\Wastays\ImportLinks;
use App\Services\Onboarding\Wastays\PlannedNumberRunner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Creates the estatell booking of one wastays booking: its room type, plan and guests from the import links, its
 * source, its company (with the Master folio billed for everything), and the wastays reservation number. The replay
 * and the stays import both use it, so a booking is made the same way on either path. It links the booking and counts it.
 */
class CreateWastaysBooking
{
    public function __construct(
        private CreateBooking $createBooking,
        private EnsureOtaBookingSource $ensureOtaBookingSource,
        private CreateBookingSource $createBookingSource,
        private PlannedNumberRunner $numbers,
        private RecordImportLink $recordImportLink,
        private ImportLinks $links,
    ) {}

    /**
     * @param  string|null  $overrideTotal  the room total wastays charged, spread over the nights, or null to price the nights
     * @param  bool  $allowPastArrival  the stay began before the business date
     *
     * @throws ReplayStopped when a link is missing or the booking is refused
     */
    public function __invoke(ReplayContext $context, string $bookingId, string $arrival, string $departure, ?string $overrideTotal, string $overrideReason, bool $allowPastArrival): ReservationUnit
    {
        $property = $context->property;
        $booking = $context->index->booking($bookingId);
        $room = $context->index->room($bookingId);
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
            overrideTotal: $overrideTotal,
            overrideReason: $overrideTotal !== null ? $overrideReason : null,
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
        $number = (int) $booking['reservation_number'];
        $fromChannel = $companyId === null && $plan instanceof RatePlan && $plan->company_access !== CompanyAccess::Everyone;
        $book = fn (bool $skipChecks) => $this->numbers->run(DocumentType::Reservation, $number, fn () => ($this->createBooking)($context->actor, $property, $data, $skipChecks, $allowPastArrival), true, $bookingId);
        $result = $book($fromChannel);

        if ($result->isRefused() && $result->refusal === ReservationResult::REFUSAL_NOT_AVAILABLE) {
            // wastays let staff book more rooms of a type than it has. The booking is kept, as an over-capacity stay.
            $result = $book(true);
            $context->summary->count('bookings over the room type capacity');
        }

        if ($result->isRefused()) {
            throw new ReplayStopped("bookings {$bookingId}: the booking was refused ({$result->refusal}) for {$arrival} to {$departure}.");
        }

        $unit = $result->reservation->units()->orderBy('id')->firstOrFail();
        ($this->recordImportLink)($property, 'bookings', $bookingId, $unit);
        $context->summary->count('bookings created');

        return $unit;
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

    private function linked(Property $property, string $table, string $id): Model
    {
        return $this->links->target($property, $table, $id)
            ?? throw new ReplayStopped("{$table} {$id} is not in estatell. Run the earlier imports first.");
    }
}
