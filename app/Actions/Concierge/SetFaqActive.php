<?php

namespace App\Actions\Concierge;

use App\Models\PropertyFaq;
use App\Models\User;

class SetFaqActive
{
    /**
     * Show an FAQ to guests or hide it. A hidden FAQ stays in the staff list and still counts toward `AddFaq::MAX`.
     * Nothing is written or logged when it is already in that state.
     */
    public function __invoke(User $actor, PropertyFaq $faq, bool $active): void
    {
        if ($faq->active === $active) {
            return;
        }

        $faq->active = $active;
        $faq->save();

        activity()->performedOn($faq)->causedBy($actor)->event($active ? 'concierge.faq-shown' : 'concierge.faq-hidden')
            ->withProperties(['property_id' => $faq->property_id])->log($active ? 'FAQ shown' : 'FAQ hidden');
    }
}
