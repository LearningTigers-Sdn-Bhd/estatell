<?php

namespace App\Actions\CityLedger;

use App\Models\Property;
use App\Models\User;

class SaveAgentPaymentTerms
{
    /**
     * Save the terms every company that pays per booking follows unless it has its own. Reservations already booked
     * keep their deadlines. Logs `agent_payment_terms.updated` when something changed.
     *
     * @param  array{agent_deposit_percent: int, agent_deposit_hours: int, agent_full_payment_days: int}  $terms
     */
    public function __invoke(User $actor, Property $property, array $terms): void
    {
        $settings = $property->settings;

        if ($settings === null) {
            return;
        }

        $settings->fill($terms);

        if (! $settings->isDirty()) {
            return;
        }

        $old = array_intersect_key($settings->getRawOriginal(), $settings->getDirty());
        $settings->save();

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('agent_payment_terms.updated')
            ->withProperties(['property_id' => $property->id, 'old' => $old, 'attributes' => $terms])
            ->log('Agent payment terms updated');
    }
}
