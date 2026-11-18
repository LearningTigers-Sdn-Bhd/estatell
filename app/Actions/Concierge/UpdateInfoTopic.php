<?php

namespace App\Actions\Concierge;

use App\Models\PropertyInfoTopic;
use App\Models\User;

class UpdateInfoTopic
{
    /**
     * Change the title and details of a topic. Nothing is written or logged when nothing changed.
     */
    public function __invoke(User $actor, PropertyInfoTopic $topic, string $title, string $body): void
    {
        $topic->fill(['title' => trim($title), 'body' => trim($body)]);
        $changed = array_keys($topic->getDirty());

        if ($changed === []) {
            return;
        }

        $topic->save();

        activity()->performedOn($topic)->causedBy($actor)->event('concierge.topic-updated')
            ->withProperties(['property_id' => $topic->property_id, 'changed' => $changed])->log('Info topic updated');
    }
}
