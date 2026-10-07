<?php

namespace App\Http\Requests\Reports;

use App\Data\Reports\ReportFilters;
use App\Enums\ReportKey;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;

/**
 * The dates of a report page: `from` and `to` in the URL, both included. A missing date falls back to the other
 * date, and with none the report opens on the open business date. The range may not be longer than the report allows.
 * A report of one date (`maxRangeDays()` is null) takes a single `date` instead and uses it as both ends.
 * The report is the `{report}` route parameter, or the slug after `reports.` in the route name.
 */
class ReportFiltersRequest extends FormRequest
{
    /**
     * The `can:reports.view` route middleware authorizes this request.
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
        if ($this->reportKey()->maxRangeDays() === null) {
            return ['date' => ['nullable', 'date_format:Y-m-d']];
        }

        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $filters = $this->filters();
            $days = Date::parse($filters->from)->diffInDays(Date::parse($filters->to), false);
            $longest = $this->reportKey()->maxRangeDays();

            if ($days < 0) {
                $validator->errors()->add('to', __('The end date must be on or after the start date.'));
            } elseif ($longest !== null && $days + 1 > $longest) {
                $validator->errors()->add('to', __('Pick at most :days days.', ['days' => $longest]));
            }
        }];
    }

    public function reportKey(): ReportKey
    {
        $report = $this->route('report');

        if ($report instanceof ReportKey) {
            return $report;
        }

        return ReportKey::from(str_replace('reports.', '', (string) $this->route()->getName()));
    }

    public function property(): Property
    {
        return $this->route('property');
    }

    /**
     * The business date the page opens on: the open date, or today in the property time zone before go-live.
     */
    public function businessDate(): string
    {
        $property = $this->property();

        return (app(CurrentBusinessDate::class)->date($property) ?? app(PropertyToday::class)->date($property))->toDateString();
    }

    public function filters(): ReportFilters
    {
        if ($this->reportKey()->maxRangeDays() === null) {
            $date = $this->input('date');
            $date = is_string($date) && $date !== '' ? $date : $this->businessDate();

            return new ReportFilters(from: $date, to: $date);
        }

        $from = $this->input('from');
        $to = $this->input('to');
        $from = is_string($from) && $from !== '' ? $from : null;
        $to = is_string($to) && $to !== '' ? $to : null;
        $default = $this->businessDate();

        return new ReportFilters(from: $from ?? $to ?? $default, to: $to ?? $from ?? $default);
    }
}
