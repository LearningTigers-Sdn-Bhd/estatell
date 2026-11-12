<?php

namespace App\Actions\Concierge;

use App\Models\PropertyInfoTopic;
use App\Models\User;

class RemoveInfoTopic
{
    public function __invoke(User $actor, PropertyInfoTopic $topic): void
    {
        activity()->performedOn($topic)->causedBy($actor)->event('concierge.topic-removed')
            ->withProperties(['property_id' => $topic->property_id])->log('Info topic removed');

        $topic->delete();
    }
}
