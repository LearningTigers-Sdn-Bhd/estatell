<?php

namespace App\Actions\Concierge;

use App\Models\PropertyFaq;
use App\Models\User;

class UpdateFaq
{
    /**
     * Change the topic, question and answer of an FAQ. Nothing is written or logged when nothing changed.
     */
    public function __invoke(User $actor, PropertyFaq $faq, string $question, string $answer, ?string $topic): void
    {
        $faq->fill([
            'question' => trim($question),
            'answer' => trim($answer),
            'topic' => filled($topic) ? trim((string) $topic) : null,
        ]);
        $changed = array_keys($faq->getDirty());

        if ($changed === []) {
            return;
        }

        $faq->save();

        activity()->performedOn($faq)->causedBy($actor)->event('concierge.faq-updated')
            ->withProperties(['property_id' => $faq->property_id, 'changed' => $changed])->log('FAQ updated');
    }
}
