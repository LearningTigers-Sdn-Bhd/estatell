<?php

namespace App\Http\Requests\Reservations;

use App\Models\ReservationUnit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Change guests form and its preview: the adults, one age per child, whether charged nights are priced again and
 * which charged add-ons to leave as they are.
 */
class ChangeUnitPartyRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` route middleware authorizes this request. Pricing charged nights again also needs
     * `reservations.change-price`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'adults' => ['required', 'integer', 'between:1,50'],
            'child_ages' => ['present', 'array', 'max:20'],
            'child_ages.*' => ['required', 'integer', 'between:0,17'],
            'reprice_charged' => ['boolean'],
            'skip_add_ons' => ['nullable', 'array', 'max:50'],
            'skip_add_ons.*' => ['string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'child_ages.*.required' => __('Enter the age of each child.'),
            'child_ages.*.between' => __('A child is 0 to 17 years old.'),
        ];
    }

    public function adults(): int
    {
        return (int) $this->validated('adults');
    }

    /**
     * @return list<int>
     */
    public function childAges(): array
    {
        return array_values(array_map(intval(...), (array) $this->validated('child_ages')));
    }

    public function repriceCharged(): bool
    {
        return $this->boolean('reprice_charged') && ($this->user()?->can('reservations.change-price') ?? false);
    }

    /**
     * The charged add-ons staff unticked, by `PostedAddOnAdjustment::$key`.
     *
     * @return list<string>
     */
    public function skipAddOns(): array
    {
        return array_values(array_map(strval(...), (array) ($this->validated('skip_add_ons') ?? [])));
    }

    public function unit(): ReservationUnit
    {
        /** @var ReservationUnit $unit */
        $unit = $this->route('unit');

        return $unit;
    }
}
