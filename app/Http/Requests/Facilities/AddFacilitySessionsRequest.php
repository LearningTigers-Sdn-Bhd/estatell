<?php

namespace App\Http\Requests\Facilities;

use App\Enums\FacilitySessionLength;
use App\Models\Facility;
use App\Models\FacilitySession;
use App\Services\Facilities\FacilitySessionSlots;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add one session, or a range of back-to-back sessions, to a facility. */
class AddFacilitySessionsRequest extends FormRequest
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
        if (! filled($this->input('length'))) {
            $this->merge(['length' => FacilitySessionLength::Whole->value]);
        }
    }

    /**
     * The range must fit at least one session of the length (`length`), and add one the facility does not have yet
     * (`ends_at`).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $facility = $this->route('facility');
        $facilityId = $facility instanceof Facility ? $facility->id : null;

        return [
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => [
                'required', 'date_format:H:i', 'after:starts_at',
                function (string $attribute, mixed $value, Closure $fail) use ($facilityId): void {
                    $slots = $this->slots();

                    if ($slots === []) {
                        return;
                    }

                    $existing = FacilitySession::query()
                        ->where('facility_id', $facilityId)
                        ->get(['starts_at', 'ends_at'])
                        ->map(fn (FacilitySession $session): string => substr($session->starts_at, 0, 5).'-'.substr($session->ends_at, 0, 5))
                        ->all();
                    $new = array_filter($slots, fn (array $slot): bool => ! in_array($slot['starts_at'].'-'.$slot['ends_at'], $existing, true));

                    if ($new === []) {
                        $fail(__('This facility already has a session at these times.'));
                    }
                },
            ],
            'length' => [
                'required', Rule::enum(FacilitySessionLength::class),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->validTimes() && FacilitySessionLength::tryFrom((string) $value) !== null && $this->slots() === []) {
                        $fail(__('A session this long does not fit between From and To.'));
                    }
                },
            ],
        ];
    }

    /**
     * The sessions the input asks for, or none while the times or the length are not valid yet.
     *
     * @return list<array{starts_at: string, ends_at: string}>
     */
    private function slots(): array
    {
        $length = FacilitySessionLength::tryFrom((string) $this->input('length'));

        if ($length === null || ! $this->validTimes()) {
            return [];
        }

        return app(FacilitySessionSlots::class)->between((string) $this->input('starts_at'), (string) $this->input('ends_at'), $length);
    }

    private function validTimes(): bool
    {
        $startsAt = (string) $this->input('starts_at');
        $endsAt = (string) $this->input('ends_at');

        return preg_match('/^\d{2}:\d{2}$/', $startsAt) === 1 && preg_match('/^\d{2}:\d{2}$/', $endsAt) === 1 && $endsAt > $startsAt;
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

    public function length(): FacilitySessionLength
    {
        return FacilitySessionLength::from((string) $this->validated('length'));
    }
}
