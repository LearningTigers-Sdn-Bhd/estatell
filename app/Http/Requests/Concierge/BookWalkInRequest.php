<?php

namespace App\Http\Requests\Concierge;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A walk-in's details and the room they picked, sent together: the booking is made only when the guest has given their ID, so
 * the booking is born with the guest's name. A bad form is an ordinary validation error on the details page.
 */
class BookWalkInRequest extends SaveIdentityRequest
{
    use ReadsWalkInSearch;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [...parent::rules(), ...$this->searchRules(roomTypeRequired: true)];
    }
}
