<?php

namespace App\Actions\Concierge;

use App\Models\PropertyInfoTopic;
use App\Models\User;

class SetInfoTopicActive
{
    /**
     * Show a topic to guests or hide it. A hidden topic stays in the staff list and still counts toward
     * `AddInfoTopic::MAX`. Nothing is written or logged when it is already in that state.
     */
    public function __invoke(User $actor, PropertyInfoTopic $topic, bool $active): void
    {
        if ($topic->active === $active) {
            return;
        }

        $topic->active = $active;
        $topic->save();

        activity()->performedOn($topic)->causedBy($actor)->event($active ? 'concierge.topic-shown' : 'concierge.topic-hidden')
            ->withProperties(['property_id' => $topic->property_id])->log($active ? 'Info topic shown' : 'Info topic hidden');
    }
}
