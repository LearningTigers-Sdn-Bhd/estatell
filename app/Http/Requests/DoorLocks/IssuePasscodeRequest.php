<?php

namespace App\Http\Requests\DoorLocks;

use App\Enums\PasscodeKind;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A PIN staff issue by hand. Times are typed in the property time zone. The route's `can:` middleware checks `settings.manage`.
 */
class IssuePasscodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        return [
            'door_lock_id' => ['required', 'integer', Rule::exists('door_locks', 'id')->where('property_id', $property instanceof Property ? $property->id : 0)],
            'kind' => ['required', Rule::enum(PasscodeKind::class)],
            'label' => ['required', 'string', 'max:60'],
            'code' => ['nullable', 'string', 'regex:/^\d{4,9}$/'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['required', 'date', 'after:now'],
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} start and end in UTC
     */
    public function window(string $timeZone): array
    {
        $startsAt = $this->filled('starts_at') ? CarbonImmutable::parse((string) $this->input('starts_at'), $timeZone)->utc() : now();

        return [$startsAt, CarbonImmutable::parse((string) $this->input('ends_at'), $timeZone)->utc()];
    }
}
