<?php

namespace App\Http\Requests\Facilities;

use App\Models\Facility;
use App\Models\FacilitySession;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Change one session of a facility. Adding goes through AddFacilitySessionsRequest. */
class FacilitySessionRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
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
        $facility = $this->route('facility');
        $facilityId = $facility instanceof Facility ? $facility->id : null;
        $session = $this->route('session');
        $sessionId = $session instanceof FacilitySession ? $session->id : null;

        return [
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => [
                'required', 'date_format:H:i', 'after:starts_at',
                function (string $attribute, mixed $value, Closure $fail) use ($facilityId, $sessionId): void {
                    $taken = FacilitySession::query()
                        ->where('facility_id', $facilityId)
                        ->where('starts_at', $this->input('starts_at'))
                        ->where('ends_at', $value)
                        ->when($sessionId !== null, fn ($query) => $query->whereKeyNot($sessionId))
                        ->exists();

                    if ($taken) {
                        $fail(__('This facility already has a session at these times.'));
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_at.after' => __('The session must end after it starts.'),
        ];
    }

    /**
     * @return array{starts_at: string, ends_at: string}
     */
    public function sessionAttributes(): array
    {
        return [
            'starts_at' => (string) $this->validated('starts_at'),
            'ends_at' => (string) $this->validated('ends_at'),
        ];
    }
}
