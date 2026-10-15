<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\PricingBasis;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The Basic rate table as it is on screen, for the guest pays preview. A blank or half-typed price is allowed: the
 * preview shows nothing for it. The `onboarding.only` middleware limits who can reach this route.
 */
class PreviewBasicRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return [
            'rows' => ['required', 'array', 'max:'.SaveRoomTypesRequest::MAX_ROWS],
            'rows.*.room_type_id' => ['required', 'integer', Rule::exists('room_types', 'id')->where('property_id', $property->id)],
            'rows.*.price_by' => ['required', Rule::enum(PricingBasis::class)->only([PricingBasis::PerRoom, PricingBasis::PerGuest])],
            'rows.*.weekday' => ['nullable', 'string', 'max:20'],
            'rows.*.weekend' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return list<array{room_type_id: int, price_by: PricingBasis, weekday: string|null, weekend: string|null}>
     */
    public function rows(): array
    {
        return array_map(fn (array $row): array => [
            'room_type_id' => (int) $row['room_type_id'],
            'price_by' => PricingBasis::from($row['price_by']),
            'weekday' => $row['weekday'] ?? null,
            'weekend' => $row['weekend'] ?? null,
        ], array_values($this->validated('rows')));
    }
}
