<?php

namespace App\Http\Requests\Folio;

use App\Services\Folio\MoveChargesPreview;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The choice on the Move charges page: the target folio, the ticked charges and one scheduled choice per room. The
 * preview and the save send the same body. Only the shape is checked here. The controller finds the folio, lines and
 * units inside the reservation (404 otherwise), and `MoveCharges` refuses with a code.
 */
class MoveChargesRequest extends FormRequest
{
    /**
     * The `can:folio.transfer` route middleware authorizes this request.
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
            'target_folio_id' => ['required', 'integer'],
            'line_ids' => ['present', 'array', 'max:2000'],
            'line_ids.*' => ['integer', 'distinct'],
            'schedules' => ['present', 'array', 'max:500'],
            'schedules.*.unit_id' => ['required', 'integer', 'distinct'],
            'schedules.*.scope' => ['required', Rule::in([MoveChargesPreview::SCOPE_ROOM, MoveChargesPreview::SCOPE_ALL])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_folio_id.required' => __('Choose the folio to move the charges to.'),
        ];
    }

    /**
     * @return list<int>
     */
    public function lineIds(): array
    {
        return array_values(array_map('intval', $this->array('line_ids')));
    }

    /**
     * The scheduled choices as unit id to scope.
     *
     * @return array<int, string>
     */
    public function scopes(): array
    {
        $scopes = [];

        foreach ($this->array('schedules') as $schedule) {
            $scopes[(int) $schedule['unit_id']] = (string) $schedule['scope'];
        }

        return $scopes;
    }
}
