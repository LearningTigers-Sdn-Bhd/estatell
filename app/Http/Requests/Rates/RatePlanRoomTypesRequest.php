<?php

namespace App\Http\Requests\Rates;

use App\Models\RatePlan;
use App\Services\Rates\RatePlanCoverage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RatePlanRoomTypesRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['room_type_ids' => $this->input('room_type_ids', [])]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'room_type_ids' => ['present', 'array'],
            'room_type_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var RatePlan $ratePlan */
                $ratePlan = $this->route('ratePlan');
                $ids = $this->roomTypeIds();

                $coverage = app(RatePlanCoverage::class);
                $eligible = $coverage->sellableRoomTypeIds($ratePlan->property_id, $ratePlan->pricing_basis);

                if (array_diff($ids, $eligible) !== []) {
                    $validator->errors()->add('room_type_ids', __('This plan cannot sell one of the chosen room types.'));

                    return;
                }

                $message = $coverage->message($ratePlan, $ids);

                if ($message !== null) {
                    $validator->errors()->add('room_type_ids', $message);
                }
            },
        ];
    }

    /**
     * @return list<int>
     */
    public function roomTypeIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('room_type_ids'))));
    }
}
