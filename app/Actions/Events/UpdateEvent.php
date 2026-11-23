<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Enums\FolioStatus;
use App\Models\Event;
use App\Models\Folio;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateEvent
{
    /**
     * Change an event's name, payer, dates and notes. A Completed or Cancelled event is refused (false), and nothing
     * is written or logged. A new payer also becomes the payer of the Open event folio. Logs `events.updated`.
     * See docs/33-facilities/03-events-and-event-folios.md.
     *
     * @param  array{name: string, payer_guest_id: ?int, payer_company_id: ?int, starts_on: string, ends_on: string, notes?: ?string}  $attributes
     */
    public function __invoke(User $actor, Event $event, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $event, $attributes): bool {
            $current = Event::query()->lockForUpdate()->findOrFail($event->id);

            if (! in_array($current->status, [EventStatus::Tentative, EventStatus::Confirmed], true)) {
                return false;
            }

            $current->fill([
                'name' => $attributes['name'],
                'payer_guest_id' => $attributes['payer_guest_id'],
                'payer_company_id' => $attributes['payer_company_id'],
                'starts_on' => $attributes['starts_on'],
                'ends_on' => $attributes['ends_on'],
                'notes' => $attributes['notes'] ?? null,
            ]);
            $changes = array_keys($current->getDirty());
            $current->save();

            if (in_array('payer_guest_id', $changes, true) || in_array('payer_company_id', $changes, true)) {
                Folio::query()
                    ->where('event_id', $current->id)
                    ->where('status', FolioStatus::Open)
                    ->update(['payer_guest_id' => $current->payer_guest_id, 'payer_company_id' => $current->payer_company_id]);
            }

            activity()
                ->performedOn($current)
                ->causedBy($actor)
                ->event('events.updated')
                ->withProperties(['property_id' => $current->property_id, 'changed' => $changes])
                ->log('Event updated');

            return true;
        });
    }
}
