<?php

namespace App\Http\Requests\Rates;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;

class RateCalendarRequest extends FormRequest
{
    /**
     * The longest range, counting both ends.
     */
    public const MAX_DAYS = 62;

    /**
     * The length of the range when the request gives none, counting both ends.
     */
    public const DEFAULT_DAYS = 14;

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
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => [
                'nullable', 'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $from = $this->input('from');

                    if (! is_string($value) || ! is_string($from) || ! Date::hasFormat($from, 'Y-m-d')) {
                        return;
                    }

                    $days = Date::parse($from)->startOfDay()->diffInDays(Date::parse($value)->startOfDay(), false);

                    if ($days < 0) {
                        $fail(__('The end date must be on or after the start date.'));
                    } elseif ($days + 1 > self::MAX_DAYS) {
                        $fail(__('Pick at most :days days.', ['days' => self::MAX_DAYS]));
                    }
                },
            ],
        ];
    }

    /**
     * The first and last date to show. The first defaults to today and the last to a default-length range.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public function range(CarbonInterface $today): array
    {
        $from = $this->validated('from');
        $to = $this->validated('to');

        $first = $from === null ? $today : Date::createFromFormat('!Y-m-d', $from, $today->getTimezone());
        $last = $to === null
            ? $first->addDays(self::DEFAULT_DAYS - 1)
            : Date::createFromFormat('!Y-m-d', $to, $today->getTimezone());

        return [$first, $last];
    }
}
