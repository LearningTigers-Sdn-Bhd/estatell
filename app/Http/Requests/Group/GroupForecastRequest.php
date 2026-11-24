<?php

namespace App\Http\Requests\Group;

use App\Services\Group\GroupForecast;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The first month (`from`, `Y-m`, this month when missing) and the number of months (`months`, 6 when missing, 1 to
 * 24) of the group forecast.
 */
class GroupForecastRequest extends FormRequest
{
    public const int DEFAULT_MONTHS = 6;

    /**
     * `EnsureGroupAccess` lets only the group's staff in; each property's figures follow the role there.
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
            'from' => ['nullable', 'date_format:Y-m'],
            'months' => ['nullable', 'integer', 'min:1', 'max:'.GroupForecast::MAX_MONTHS],
        ];
    }

    public function firstMonth(CarbonImmutable $today): CarbonImmutable
    {
        $from = $this->validated('from');

        return is_string($from) ? CarbonImmutable::createFromFormat('!Y-m', $from) : $today->startOfMonth();
    }

    public function months(): int
    {
        return (int) ($this->validated('months') ?? self::DEFAULT_MONTHS);
    }
}
