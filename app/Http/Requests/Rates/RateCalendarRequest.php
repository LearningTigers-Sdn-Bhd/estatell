<?php

namespace App\Http\Requests\Rates;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;

class RateCalendarRequest extends FormRequest
{
    /**
     * The `can:rates.view` route middleware authorizes this request.
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
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    /**
     * The first day of the requested month, or the current month when none is given.
     */
    public function month(CarbonInterface $today): CarbonInterface
    {
        $month = $this->validated('month');

        return $month === null
            ? $today->startOfMonth()
            : Date::createFromFormat('!Y-m', $month, $today->getTimezone())->startOfMonth();
    }
}
