<?php

namespace App\Http\Requests\Reservations;

use App\Models\MarketSegment;
use App\Models\Property;
use App\Services\Reservations\MarketSegments;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class MarketSegmentRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
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

        $segment = $this->route('marketSegment');
        $segmentId = $segment instanceof MarketSegment ? $segment->id : null;

        return [
            'name' => [
                'required', 'string', 'max:50',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $segmentId): void {
                    if (app(MarketSegments::class)->nameTaken($property->id, (string) $value, $segmentId)) {
                        $fail(__('Another market segment already uses this name.'));
                    }
                },
            ],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }
}
