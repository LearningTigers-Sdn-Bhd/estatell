<?php

namespace App\Http\Requests\Reports;

use App\Data\Reports\ReportFilters;
use App\Enums\BoatDirection;
use App\Enums\ReportGroup;
use App\Enums\ReportKey;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The dates of a report page: `from` and `to` in the URL, both included. A missing date falls back to the other
 * date, and with none the report opens on the open business date, or on the days up to it that `ReportKey::defaultDays()` gives. The range may not be longer than the report allows.
 * A report of one date (`maxRangeDays()` is null) takes a single `date` instead and uses it as both ends.
 * A report that groups its rows (`ReportKey::groups()`) also takes `group`, which must be one of its groups and falls
 * back to the first. Other reports ignore `group`.
 * A report that filters by transaction code (`ReportKey::hasCodeFilter()`) also takes `code`, which must be a code of
 * the property. Other reports ignore `code`.
 * A report with payment filters (`ReportKey::hasPaymentFilters()`) also takes `staff`, which must be a user of the
 * property, and `refunds` (1 for refunds only). Other reports ignore them.
 * A report with a foreign filter (`ReportKey::hasForeignFilter()`, the Police report) also takes `foreign` (1 for foreign
 * guests only). Other reports ignore it.
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
        $groups = $this->reportKey()->groups();

        return [
            ...($this->reportKey()->maxRangeDays() === null
                ? ['date' => ['nullable', 'date_format:Y-m-d']]
                : ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]),
            ...($this->reportKey()->hasCodeFilter() ? ['code' => ['nullable', 'string', Rule::exists('transaction_codes', 'code')->where('property_id', $this->property()->id)]] : []),
            ...($this->reportKey()->hasPaymentFilters() ? [
                'staff' => ['nullable', 'integer', Rule::exists('users', 'id')->where('property_id', $this->property()->id)],
                'refunds' => ['nullable', 'boolean'],
            ] : []),
            ...($this->reportKey()->hasForeignFilter() ? ['foreign' => ['nullable', 'boolean']] : []),
            ...($this->reportKey()->hasDirectionFilter() ? ['direction' => ['nullable', Rule::enum(BoatDirection::class)]] : []),
            ...($groups === [] ? [] : ['group' => ['nullable', Rule::in(array_map(fn (ReportGroup $group): string => $group->value, $groups))]]),
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
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return $property;
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

            return new ReportFilters(from: $date, to: $date, group: $this->group(), code: $this->code(), staff: $this->staff(), refundsOnly: $this->refundsOnly(), foreignOnly: $this->foreignOnly(), direction: $this->direction());
        }

        $from = $this->input('from');
        $to = $this->input('to');
        $from = is_string($from) && $from !== '' ? $from : null;
        $to = is_string($to) && $to !== '' ? $to : null;
        $default = $this->businessDate();
        $defaultFrom = Date::parse($default)->subDays($this->reportKey()->defaultDays() - 1)->toDateString();

        return new ReportFilters(from: $from ?? $to ?? $defaultFrom, to: $to ?? $from ?? $default, group: $this->group(), code: $this->code(), staff: $this->staff(), refundsOnly: $this->refundsOnly(), foreignOnly: $this->foreignOnly(), direction: $this->direction());
    }

    /**
     * The direction of the Boat manifest: the `direction` in the URL, or Boat in when none is given. Other reports
     * ignore it.
     */
    private function direction(): ?BoatDirection
    {
        if (! $this->reportKey()->hasDirectionFilter()) {
            return null;
        }

        return BoatDirection::tryFrom($this->string('direction')->toString()) ?? BoatDirection::In;
    }

    /**
     * The transaction code of a report that filters by one: the `code` in the URL, or null for every code.
     */
    private function code(): ?string
    {
        $code = $this->string('code')->toString();

        return $this->reportKey()->hasCodeFilter() && $code !== '' ? $code : null;
    }

    /**
     * The user id of the staff filter of Payments: the `staff` in the URL, or null for all staff.
     */
    private function staff(): ?int
    {
        return $this->reportKey()->hasPaymentFilters() && $this->filled('staff') ? $this->integer('staff') : null;
    }

    private function refundsOnly(): bool
    {
        return $this->reportKey()->hasPaymentFilters() && $this->boolean('refunds');
    }

    private function foreignOnly(): bool
    {
        return $this->reportKey()->hasForeignFilter() && $this->boolean('foreign');
    }

    /**
     * The grouping of a report that takes one: the `group` in the URL, or the first group of the report.
     */
    private function group(): ?ReportGroup
    {
        $groups = $this->reportKey()->groups();

        return $groups === [] ? null : (ReportGroup::tryFrom($this->string('group')->toString()) ?? $groups[0]);
    }
}
