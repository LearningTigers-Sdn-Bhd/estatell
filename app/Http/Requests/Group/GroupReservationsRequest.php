<?php

namespace App\Http\Requests\Group;

use App\Enums\ReservationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Group › Reservations filters: `search`, `status` (one reservation status, or all), `from` and `to` (stays that touch
 * the dates; from today when neither is sent) and `property` (a property code of the group, or all).
 */
class GroupReservationsRequest extends FormRequest
{
    /**
     * `EnsureGroupAccess` lets only the group's staff in; each property's bookings follow the role there.
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
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ReservationStatus::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'property' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array{search: ?string, status: ?ReservationStatus, from: ?string, to: ?string, property: ?string}
     */
    public function filters(string $today): array
    {
        $text = fn (string $name): ?string => is_string($this->validated($name)) && $this->validated($name) !== '' ? $this->validated($name) : null;
        $search = $text('search');

        return [
            'search' => $search,
            'status' => ReservationStatus::tryFrom((string) $this->validated('status')),
            // A search looks at every date; a plain list starts today.
            'from' => $text('from') ?? ($search === null && $text('to') === null ? $today : null),
            'to' => $text('to'),
            'property' => $text('property'),
        ];
    }
}
