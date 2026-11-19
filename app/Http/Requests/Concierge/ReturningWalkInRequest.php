<?php

namespace App\Http\Requests\Concierge;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The email or phone a walk-in gives to be recognised, with the room they picked, so a match can book the room for the returning
 * guest in the same step.
 */
class ReturningWalkInRequest extends ReturningGuestRequest
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
