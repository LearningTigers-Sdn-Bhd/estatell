<?php

namespace App\Actions\Events;

use App\Actions\Facilities\BookFacility;
use App\Actions\Guests\CreateGuest;
use App\Data\Facilities\FacilityBookingInputData;
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
        private CreateGuest $createGuest,
        private BookFacility $bookFacility,
    ) {}

    /**
     * Create the event, its folio and its facility bookings in one transaction, as the New event page saves them.
     * The lines are booked in order with `BookFacility`, so a line is checked against the earlier ones. The first
     * refused line rolls everything back, the event number included, and is returned with its index.
     * See docs/33-facilities/03-events-and-event-folios.md#new-event-page.
     *
     * @param  array{name: string, payer_guest_id: ?int, payer_company_id: ?int, new_guest?: array{full_name: string}|null, starts_on: string, ends_on: string, status: EventStatus, notes?: ?string}  $attributes
     * @param  list<FacilityBookingInputData>  $bookings
     */
    public function withBookings(Property $property, User $actor, array $attributes, array $bookings): CreateEventResult
    {
        DB::beginTransaction();

        try {
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
     * to the property and that the status is Tentative or Confirmed. A `new_guest` (a walk-in) is added with
     * `CreateGuest` in the same transaction and becomes the payer. Logs `events.created`.
     * See docs/33-facilities/03-events-and-event-folios.md.
     *
     * @param  array{name: string, payer_guest_id: ?int, payer_company_id: ?int, new_guest?: array{full_name: string}|null, starts_on: string, ends_on: string, status: EventStatus, notes?: ?string}  $attributes
     */
    public function __invoke(Property $property, User $actor, array $attributes): Event
    {
        return DB::transaction(function () use ($property, $actor, $attributes): Event {
            $newGuest = $attributes['new_guest'] ?? null;
            $payerGuestId = $newGuest === null ? $attributes['payer_guest_id'] : ($this->createGuest)($actor, $property, $newGuest)->id;

            $event = new Event([
                'number' => ($this->nextDocumentNumber)($property, DocumentType::Event)->reference,
                'name' => $attributes['name'],
                'payer_guest_id' => $payerGuestId,
                'payer_company_id' => $newGuest === null ? $attributes['payer_company_id'] : null,
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
