<?php

namespace App\Actions\Events;

use App\Actions\Facilities\BookFacility;
use App\Actions\Reservations\AttachGuestResult;
use App\Actions\Reservations\ResolveUnitGuest;
use App\Data\Facilities\FacilityBookingInputData;
use App\Data\Guests\IdentityDocumentInputData;
use App\Enums\DocumentType;
use App\Enums\EventStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\Event;
use App\Models\Folio;
use App\Models\Property;
use App\Models\User;
use App\Services\NightAudit\NextDocumentNumber;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateEvent
{
    public function __construct(
        private NextDocumentNumber $nextDocumentNumber,
        private ResolveUnitGuest $resolveGuest,
        private BookFacility $bookFacility,
    ) {}

    /**
     * Create the event, its folio and its facility bookings in one transaction, as the New event page saves them.
     * A `guest` row (the payer, like the booker of the booking form) is resolved first with `ResolveUnitGuest`: the
     * picked guest, with its profile updated when asked, or a new guest. The lines are booked in order with
     * `BookFacility`, so a line is checked against the earlier ones. A refused guest or the first refused line rolls
     * everything back, the event number included, and is returned.
     * See docs/33-facilities/03-events-and-event-folios.md#new-event-page.
     *
     * @param  array{name: string, payer_guest_id: ?int, payer_company_id: ?int, guest?: array{guest_id: ?int, profile: array<string, mixed>, update_profile: bool, identity: ?IdentityDocumentInputData}|null, starts_on: string, ends_on: string, status: EventStatus, notes?: ?string}  $attributes
     * @param  list<FacilityBookingInputData>  $bookings
     */
    public function withBookings(Property $property, User $actor, array $attributes, array $bookings): CreateEventResult
    {
        DB::beginTransaction();

        try {
            $row = $attributes['guest'] ?? null;

            if ($row !== null) {
                $guest = ($this->resolveGuest)($actor, $property, $row['guest_id'], $row['profile'], $row['update_profile'], $row['identity']);

                if ($guest instanceof AttachGuestResult) {
                    DB::rollBack();

                    return CreateEventResult::guestRefused((string) $guest->refusal);
                }

                $attributes['payer_guest_id'] = $guest->id;
                $attributes['payer_company_id'] = null;
            }

            $event = $this($property, $actor, $attributes);

            foreach ($bookings as $index => $booking) {
                $result = $this->bookFacility->forEvent($actor, $event, $booking);

                if ($result->isRefused()) {
                    DB::rollBack();

                    return CreateEventResult::refused($index, $result);
                }
            }

            DB::commit();

            return CreateEventResult::created($event);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * Create an event and its Open Event folio in one transaction. The event takes the next Event number and the
     * folio the next Folio number; the folio's payer is the event's payer. The caller checks that the payer belongs
     * to the property and that the status is Tentative or Confirmed. Logs `events.created`.
     * See docs/33-facilities/03-events-and-event-folios.md.
     *
     * @param  array{name: string, payer_guest_id: ?int, payer_company_id: ?int, starts_on: string, ends_on: string, status: EventStatus, notes?: ?string}  $attributes
     */
    public function __invoke(Property $property, User $actor, array $attributes): Event
    {
        return DB::transaction(function () use ($property, $actor, $attributes): Event {
            $event = new Event([
                'number' => ($this->nextDocumentNumber)($property, DocumentType::Event)->reference,
                'name' => $attributes['name'],
                'payer_guest_id' => $attributes['payer_guest_id'],
                'payer_company_id' => $attributes['payer_company_id'],
                'starts_on' => $attributes['starts_on'],
                'ends_on' => $attributes['ends_on'],
                'status' => $attributes['status'],
                'notes' => $attributes['notes'] ?? null,
            ]);
            $event->property_id = $property->id;
            $event->save();

            $folio = new Folio([
                'event_id' => $event->id,
                'number' => ($this->nextDocumentNumber)($property, DocumentType::Folio)->reference,
                'type' => FolioType::Event,
                'status' => FolioStatus::Open,
                'payer_guest_id' => $event->payer_guest_id,
                'payer_company_id' => $event->payer_company_id,
            ]);
            $folio->property_id = $property->id;
            $folio->save();

            activity()
                ->performedOn($event)
                ->causedBy($actor)
                ->event('events.created')
                ->withProperties(['property_id' => $property->id, 'number' => $event->number, 'folio_id' => $folio->id])
                ->log('Event created');

            return $event;
        });
    }
}
