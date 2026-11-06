<?php

namespace App\Http\Requests\Booking;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The dates of the booking calendar, at most 62 days at a time.
 */
class CalendarRequest extends FormRequest
{
    public const int MAX_DAYS = 62;

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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->from()->diffInDays($this->to()) >= self::MAX_DAYS) {
                $validator->errors()->add('to', __('Choose at most :count days.', ['count' => self::MAX_DAYS]));
            }
        }];
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('from')->toString());
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('to')->toString());
    }
}
