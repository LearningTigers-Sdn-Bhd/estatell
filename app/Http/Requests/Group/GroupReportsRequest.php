<?php

namespace App\Http\Requests\Group;

use App\Data\Reports\ReportFilters;
use App\Enums\GroupReportTab;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The tab (`?tab`, By property when missing) and the dates (`from`, `to`, 366 days at most) of Group reports. With
 * no dates, By property and By date open on the month of today, Bookings on today. See docs/03-staff/05-group-mode.md.
 */
class GroupReportsRequest extends FormRequest
{
    public const MAX_DAYS = 366;

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
            'tab' => ['nullable', Rule::enum(GroupReportTab::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $filters = $this->filters(CarbonImmutable::today());
            $days = CarbonImmutable::parse($filters->from)->diffInDays(CarbonImmutable::parse($filters->to), false);

            if ($days < 0) {
                $validator->errors()->add('to', __('The end date must be on or after the start date.'));
            } elseif ($days + 1 > self::MAX_DAYS) {
                $validator->errors()->add('to', __('Pick at most :days days.', ['days' => self::MAX_DAYS]));
            }
        }];
    }

    public function tab(): GroupReportTab
    {
        return GroupReportTab::tryFrom((string) $this->input('tab')) ?? GroupReportTab::ByProperty;
    }

    public function filters(CarbonImmutable $today): ReportFilters
    {
        $from = $this->input('from');
        $to = $this->input('to');
        $from = is_string($from) && $from !== '' ? $from : null;
        $to = is_string($to) && $to !== '' ? $to : null;
        $month = $this->tab()->opensOnMonth();
        $defaultFrom = $month ? $today->startOfMonth()->toDateString() : $today->toDateString();
        $defaultTo = $month ? $today->endOfMonth()->toDateString() : $today->toDateString();

        return new ReportFilters(from: $from ?? $to ?? $defaultFrom, to: $to ?? $from ?? $defaultTo);
    }
}
