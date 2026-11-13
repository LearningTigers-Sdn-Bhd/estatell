<?php

namespace App\Actions\Concierge;

use App\Models\PropertyFaq;
use App\Models\User;

class RemoveFaq
{
    public function __invoke(User $actor, PropertyFaq $faq): void
    {
        activity()->performedOn($faq)->causedBy($actor)->event('concierge.faq-removed')
            ->withProperties(['property_id' => $faq->property_id])->log('FAQ removed');

        $faq->delete();
    }
}
