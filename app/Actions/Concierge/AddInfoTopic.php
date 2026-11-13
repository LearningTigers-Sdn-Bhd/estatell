<?php

namespace App\Actions\Concierge;

use App\Models\Property;
use App\Models\PropertyInfoTopic;
use App\Models\User;

class AddInfoTopic
{
    /** Topics a property can list, so the guide stays short. */
    public const int MAX = 20;

    /**
     * Add a topic to the end of the property's list. Returns null, writing nothing, when the property already lists
     * `MAX` topics.
     */
    public function __invoke(User $actor, Property $property, string $title, string $body): ?PropertyInfoTopic
    {
        if ($property->infoTopics()->count() >= self::MAX) {
            return null;
        }

        $topic = $property->infoTopics()->create([
            'title' => trim($title),
            'body' => trim($body),
            'position' => (int) $property->infoTopics()->max('position') + 1,
        ]);

        activity()->performedOn($topic)->causedBy($actor)->event('concierge.topic-added')
            ->withProperties(['property_id' => $property->id])->log('Info topic added');

        return $topic;
    }
}
