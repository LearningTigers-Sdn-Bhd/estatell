<?php

namespace App\Http\Requests\Facilities;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The guide and boat of one activity slot on the Activities run sheet. `facility_guide_id` and `facility_boat_id` may be
 * null (Not set); the Action checks they belong to the property and are available.
 */
class AssignFacilitySlotCrewRequest extends FormRequest
{
    /**
     * The `can:facilities.book` route middleware authorizes this request.
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
            'date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'facility_guide_id' => ['nullable', 'integer'],
            'facility_boat_id' => ['nullable', 'integer'],
        ];
    }

    public function slotDate(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('date'));
    }

    public function guideId(): ?int
    {
        return $this->validated('facility_guide_id') === null ? null : (int) $this->validated('facility_guide_id');
    }

    public function boatId(): ?int
    {
        return $this->validated('facility_boat_id') === null ? null : (int) $this->validated('facility_boat_id');
    }
}
