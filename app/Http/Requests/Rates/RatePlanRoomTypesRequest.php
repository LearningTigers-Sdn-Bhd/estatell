<?php

namespace App\Http\Requests\Rates;

use App\Models\RatePlan;
use App\Services\Channels\ChannelArchiveGuard;
use App\Services\Rates\DerivedRatePlans;
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

                if ($ratePlan->parent_rate_plan_id !== null) {
                    $parent = $ratePlan->parent;

                    if (array_diff($ids, $parent->roomTypes()->pluck('room_types.id')->all()) !== []) {
                        $validator->errors()->add('room_type_ids', __('This plan can sell only the room types of :parent, the plan it follows.', ['parent' => $parent->name]));

                        return;
                    }
                }

                $removed = array_diff($ratePlan->roomTypes()->active()->pluck('room_types.id')->all(), $ids);
                $message = app(DerivedRatePlans::class)->unlinkMessage($ratePlan, array_values($removed)) ?? $coverage->message($ratePlan, $ids) ?? app(ChannelArchiveGuard::class)->unlinkMessage($ratePlan, array_values($removed));

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
