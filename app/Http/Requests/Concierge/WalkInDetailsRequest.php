<?php

namespace App\Http\Requests\Concierge;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The room a walk-in picked, on the page where they give their details. Nothing is booked yet: the page only reads the choice
 * from the link, so the room type is required and no search default is filled in.
 */
class WalkInDetailsRequest extends FormRequest
{
    use ReadsWalkInSearch;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->searchRules(roomTypeRequired: true);
    }
}
