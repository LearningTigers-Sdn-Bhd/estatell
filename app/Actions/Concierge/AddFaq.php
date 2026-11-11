<?php

namespace App\Actions\Concierge;

use App\Models\Property;
use App\Models\PropertyFaq;
use App\Models\User;

class AddFaq
{
    /** Questions a property can list, so the guide stays short. */
    public const int MAX = 30;

    /**
     * Add a question and its answer to the end of the property's list. Returns null, writing nothing, when the property
     * already lists `MAX` questions.
     */
    public function __invoke(User $actor, Property $property, string $question, string $answer): ?PropertyFaq
    {
        if ($property->faqs()->count() >= self::MAX) {
            return null;
        }

        $faq = $property->faqs()->create([
            'question' => trim($question),
            'answer' => trim($answer),
            'position' => (int) $property->faqs()->max('position') + 1,
        ]);

        activity()->performedOn($faq)->causedBy($actor)->event('concierge.faq-added')
            ->withProperties(['property_id' => $property->id])->log('FAQ added');

        return $faq;
    }
}
