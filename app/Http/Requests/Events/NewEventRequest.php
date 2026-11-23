<?php

namespace App\Http\Requests\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The New event page. `?date=Y-m-d&facility={id}` (from Book facility on the Timeline) starts the page on that date
 * with one line for that facility. A value that does not parse is ignored, so a bad link still opens the page.
 */
class NewEventRequest extends FormRequest
{
    /**
     * The `can:events.manage` route middleware authorizes this request.
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
        return [];
    }

    /**
     * @return array{date: string|null, facility: int|null}
     */
    public function prefill(): array
    {
        $date = $this->string('date')->toString();
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : null;
        $facility = $this->string('facility')->toString();

        return [
            'date' => $parsed instanceof CarbonImmutable && $parsed->toDateString() === $date ? $date : null,
            'facility' => ctype_digit($facility) ? (int) $facility : null,
        ];
    }
}
