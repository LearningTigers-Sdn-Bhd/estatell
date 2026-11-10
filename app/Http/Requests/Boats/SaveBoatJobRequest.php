<?php

namespace App\Http\Requests\Boats;

use App\Data\Boats\SailingData;
use App\Enums\BoatJobType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or change a boat job on the Boat Trips board. The note is required for Other. Whether the job belongs to the
 * property is a 404 in the controller; whether a boat or crew member can be picked is the Action's refusal. The route
 * checks `boats` and `reservations.manage`.
 */
class SaveBoatJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'boat_id' => filled($this->input('boat_id')) ? $this->input('boat_id') : null,
            'in_charge_id' => filled($this->input('in_charge_id')) ? $this->input('in_charge_id') : null,
            'note' => filled($this->input('note')) ? $this->input('note') : null,
            'assistant_ids' => array_values(array_filter((array) $this->input('assistant_ids', []), filled(...))),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'job_type' => ['required', Rule::enum(BoatJobType::class)],
            'leaves_at' => ['required', 'date_format:H:i'],
            'boat_id' => ['nullable', 'integer'],
            'in_charge_id' => ['nullable', 'integer'],
            'assistant_ids' => ['present', 'array', 'max:20'],
            'assistant_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:500', Rule::requiredIf($this->input('job_type') === BoatJobType::Other->value)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['note.required' => __('Say what the job is.')];
    }

    public function jobDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', (string) $this->validated('date'))->startOfDay();
    }

    public function jobType(): BoatJobType
    {
        return BoatJobType::from((string) $this->validated('job_type'));
    }

    public function crew(): SailingData
    {
        return new SailingData(
            boatId: $this->validated('boat_id') === null ? null : (int) $this->validated('boat_id'),
            inChargeId: $this->validated('in_charge_id') === null ? null : (int) $this->validated('in_charge_id'),
            assistantIds: array_values(array_map(intval(...), $this->validated('assistant_ids'))),
        );
    }
}
