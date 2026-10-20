<?php

namespace App\Actions\Channels;

use App\Actions\FrontDesk\CancelUnits;
use App\Actions\FrontDesk\ChangeUnitDates;
use App\Actions\Guests\CreateGuest;
use App\Actions\Reservations\AddUnit;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\EnsureOtaBookingSource;
use App\Data\Reservations\NewReservationData;
use App\Data\Reservations\NewUnitData;
use App\Enums\GuestMatchReason;
use App\Enums\RevisionKind;
use App\Enums\UnitStatus;
use App\Models\ChannelAlert;
use App\Models\ChannelBookingRevision;
use App\Models\Guest;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ChannelBookingCancelled;
use App\Notifications\ChannelBookingReceived;
use App\Services\Channels\ChannelActionResult;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\ChannelRevisionMapper;
use App\Services\Channels\ChannelRevisionRefused;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;
use App\Services\Guests\GuestMatcher;
use App\Services\Staff\PropertyOwners;
use Illuminate\Support\Facades\DB;

class IngestChannelBooking
{
    public function __construct(
        private ChannelRevisionMapper $mapper,
        private PropertyOwners $propertyOwners,
        private GuestMatcher $guestMatcher,
        private CreateGuest $createGuest,
        private EnsureOtaBookingSource $ensureOtaBookingSource,
        private CreateReservation $createReservation,
        private AddUnit $addUnit,
        private ChangeUnitDates $changeUnitDates,
        private CancelUnits $cancelUnits,
        private NotifyChannelReservationStaff $notifyStaff,
        private ChannelDriverManager $drivers,
    ) {}

    /**
     * Save one booking revision as a reservation and tell the provider it is saved. The revision is saved first, then
     * acknowledged: a revision that is saved but not yet acknowledged only repeats the acknowledgement, so nothing is
     * booked twice. A refused revision keeps its `error`, is not acknowledged, and shows on the admin Channels tab. A
     * New revision creates a reservation with one unit for each room; a Modified one moves the units to the new rooms
     * and dates (matched in order); a Cancelled one cancels the units without a fee. OTA bookings are always
     * accepted: availability, restrictions and missing prices do not refuse them. The reservation is created by the
     * property's owner. Staff are notified after the save. See docs/21-channels/05-booking-ingestion.md.
     */
    public function __invoke(ChannelBookingRevision $revision): ChannelActionResult
    {
        if ($revision->acknowledged_at !== null) {
            return ChannelActionResult::ok(__('Already acknowledged.'));
        }

        $revision->loadMissing('channelProperty.property.settings');

        if ($revision->processed_at === null) {
            try {
                $saved = DB::transaction(fn (): array => $this->save($revision));
            } catch (ChannelRevisionRefused $refused) {
                $revision->update(['error' => $refused->getMessage()]);

                return ChannelActionResult::failed($refused->getMessage());
            }

            $this->notify($saved['reservation'], $revision->kind, $saved['channel_name']);
        }

        return $this->acknowledge($revision);
    }

    /**
     * @return array{reservation: Reservation, channel_name: string}
     *
     * @throws ChannelRevisionRefused
     */
    private function save(ChannelBookingRevision $revision): array
    {
        $property = $revision->channelProperty->property;
        $payload = $revision->payload;
        $actor = $this->propertyOwners->firstFor($property) ?? throw new ChannelRevisionRefused(__('The property has no owner to book as.'));
        $otaName = (string) ($payload['ota_name'] ?? '');
        $channel = $this->mapper->channel($otaName);
        $channelName = $this->mapper->channelName($channel, $otaName);

        $existing = $this->existingReservation($revision);

        if ($existing === null && $revision->kind !== RevisionKind::New) {
            throw new ChannelRevisionRefused(__('The earlier revision of this booking is not saved yet.'));
        }

        if ($revision->kind === RevisionKind::Cancelled) {
            $this->cancel($actor, $existing, $channelName);
            $reservation = $existing;
        } else {
            $this->guardCurrency($property, $payload);
            $lines = $this->mapper->units($revision->channelProperty, $payload, $channelName);
            $reservation = $existing === null
                ? $this->create($actor, $property, $payload, $lines, $channel, $otaName)
                : $this->modify($actor, $existing, $lines, $channelName);
        }

        $revision->update(['processed_at' => now(), 'reservation_id' => $reservation->id, 'error' => null]);

        return ['reservation' => $reservation, 'channel_name' => $channelName];
    }

    private function existingReservation(ChannelBookingRevision $revision): ?Reservation
    {
        $reservationId = ChannelBookingRevision::query()
            ->where('channel_property_id', $revision->channel_property_id)
            ->where('external_booking_id', $revision->external_booking_id)
            ->whereNotNull('reservation_id')
            ->whereKeyNot($revision->id)
            ->latest('id')
            ->value('reservation_id');

        return $reservationId === null ? null : Reservation::query()->find($reservationId);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ChannelRevisionRefused
     */
    private function guardCurrency(Property $property, array $payload): void
    {
        $currency = strtoupper((string) ($payload['currency'] ?? ''));

        if ($currency !== '' && $currency !== $property->settings->currency->getCurrencyCode()) {
            throw new ChannelRevisionRefused(__('The booking is in :currency, and the property uses :property.', ['currency' => $currency, 'property' => $property->settings->currency->getCurrencyCode()]));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<NewUnitData>  $lines
     *
     * @throws ChannelRevisionRefused
     */
    private function create(User $actor, Property $property, array $payload, array $lines, OtaChannel $channel, string $otaName): Reservation
    {
        $result = ($this->createReservation)($actor, $property, new NewReservationData(
            bookerGuestId: $this->resolveGuest($actor, $property, $payload)->id,
            bookingSource: ($this->ensureOtaBookingSource)($property, $channel, $otaName),
            units: $lines,
            externalReference: filled($payload['unique_id'] ?? null) ? (string) $payload['unique_id'] : null,
            notes: filled($payload['notes'] ?? null) ? mb_substr((string) $payload['notes'], 0, 2000) : null,
        ), fromChannel: true);

        if ($result->isRefused()) {
            throw new ChannelRevisionRefused(__('estatell refused the booking (:code).', ['code' => $result->refusal]));
        }

        return $result->reservation;
    }

    /**
     * Match the units of the reservation to the rooms of the revision in order. A unit with another room type or rate
     * plan is cancelled and replaced, one with other dates keeps its room and changes dates, a room with no unit is
     * added, and a unit with no room is cancelled. Nothing is charged for the changes: the channel decides.
     *
     * @param  list<NewUnitData>  $lines
     *
     * @throws ChannelRevisionRefused
     */
    private function modify(User $actor, Reservation $reservation, array $lines, string $channelName): Reservation
    {
        $units = $reservation->units()->whereIn('status', [UnitStatus::Confirmed, UnitStatus::CheckedIn])->orderBy('id')->get()->values();
        $reason = __('Changed on :channel', ['channel' => $channelName]);

        foreach ($lines as $index => $line) {
            $unit = $units->get($index);

            if ($unit === null) {
                $this->addLine($actor, $reservation, $line, $index);

                continue;
            }

            if ($unit->room_type_id !== $line->roomTypeId || $unit->rate_plan_id !== $line->ratePlanId) {
                $this->cancelLive($actor, $reservation, [$unit->id], $reason);
                $this->addLine($actor, $reservation, $line, $index);

                continue;
            }

            if (! $unit->arrival_date->equalTo($line->arrivalDate) || ! $unit->departure_date->equalTo($line->departureDate)) {
                $result = ($this->changeUnitDates)($actor, $unit, $line->arrivalDate, $line->departureDate, true, $reason, null, $reason);

                if ($result->isRefused()) {
                    throw new ChannelRevisionRefused(__('estatell refused the date change (:code).', ['code' => $result->refusal]));
                }
            }
        }

        $surplus = $units->slice(count($lines))->pluck('id')->all();

        if ($surplus !== []) {
            $this->cancelLive($actor, $reservation, $surplus, $reason);
        }

        return $reservation;
    }

    /**
     * @throws ChannelRevisionRefused
     */
    private function addLine(User $actor, Reservation $reservation, NewUnitData $line, int $index): void
    {
        $result = ($this->addUnit)($actor, $reservation, $line, $index, fromChannel: true);

        if ($result->isRefused()) {
            throw new ChannelRevisionRefused(__('estatell refused room :number (:code).', ['number' => $index + 1, 'code' => $result->refusal]));
        }
    }

    /**
     * @throws ChannelRevisionRefused
     */
    private function cancel(User $actor, Reservation $reservation, string $channelName): void
    {
        $ids = $reservation->units()->whereIn('status', [UnitStatus::Confirmed, UnitStatus::CheckedIn])->pluck('id')->all();

        if ($ids !== []) {
            $this->cancelLive($actor, $reservation, $ids, __('Cancelled on :channel', ['channel' => $channelName]));
        }
    }

    /**
     * @param  list<int>  $unitIds
     *
     * @throws ChannelRevisionRefused
     */
    private function cancelLive(User $actor, Reservation $reservation, array $unitIds, string $reason): void
    {
        $result = ($this->cancelUnits)($actor, $reservation, $unitIds, doNotCharge: true, reason: $reason);

        if ($result->isRefused()) {
            throw new ChannelRevisionRefused(__('estatell could not cancel a room (:code).', ['code' => $result->refusal]));
        }
    }

    /**
     * The booker: a guest of the property with the same email or phone, or a new guest.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveGuest(User $actor, Property $property, array $payload): Guest
    {
        $details = $this->mapper->guest($payload);
        $phone = $this->guestMatcher->normalizePhone($details['phone'], $property);

        $match = array_find(
            $this->guestMatcher->find($property, $details['full_name'], $details['email'], $details['phone']),
            fn (array $match): bool => in_array(GuestMatchReason::Email, $match['reasons'], true) || in_array(GuestMatchReason::Phone, $match['reasons'], true),
        );

        return $match['guest'] ?? ($this->createGuest)($actor, $property, array_filter([
            'full_name' => $details['full_name'],
            'email' => $details['email'],
            'phone' => $phone,
            'country_code' => $details['country_code'],
        ], fn (mixed $value): bool => $value !== null));
    }

    private function notify(Reservation $reservation, RevisionKind $kind, string $channelName): void
    {
        match ($kind) {
            RevisionKind::New => ($this->notifyStaff)($reservation, ChannelBookingReceived::class, $channelName),
            RevisionKind::Cancelled => ($this->notifyStaff)($reservation, ChannelBookingCancelled::class, $channelName),
            RevisionKind::Modified => null,
        };
    }

    /**
     * Tell the provider the revision is saved. When the call fails the revision stays saved and unacknowledged, and the
     * next feed pull repeats only this step.
     */
    private function acknowledge(ChannelBookingRevision $revision): ChannelActionResult
    {
        try {
            $this->drivers->forProvider($revision->channelProperty->provider)->acknowledge($revision->revision_id);
        } catch (ChannexRetryLater|ChannexRequestFailed $exception) {
            return ChannelActionResult::failed(__('Saved, but Channex did not take the acknowledgement yet. It will be sent again.'));
        }

        $revision->update(['acknowledged_at' => now()]);

        ChannelAlert::query()
            ->where('channel_property_id', $revision->channel_property_id)
            ->where('revision_id', $revision->revision_id)
            ->open()
            ->update(['resolved_at' => now()]);

        return ChannelActionResult::ok(__('Booking saved.'));
    }
}
