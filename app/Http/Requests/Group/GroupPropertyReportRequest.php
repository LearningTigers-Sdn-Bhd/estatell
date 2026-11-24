<?php

namespace App\Http\Requests\Group;

use App\Data\Reports\ReportFilters;
use App\Enums\ReportGroup;
use App\Enums\ReportKey;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Group › Property reports: the report (`report`, Arrivals when missing; only `ReportKey::runsInGroup()`), its date
 * (`date`, for a report of one date) or dates (`from`, `to`, no longer than the report allows), and `group` for a report
 * that groups its rows. With no dates it opens on today, or this month for a report that opens on the month.
 */
class GroupPropertyReportRequest extends FormRequest
{
    /**
     * `EnsureGroupAccess` lets only the group's staff in; each property's rows follow the role there.
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
            'report' => ['nullable', Rule::in(array_map(fn (ReportKey $key): string => $key->value, self::reports()))],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'group' => ['nullable', Rule::enum(ReportGroup::class)],
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
            $longest = $this->reportKey()->maxRangeDays();

            if ($days < 0) {
                $validator->errors()->add('to', __('The end date must be on or after the start date.'));
            } elseif ($longest !== null && $days + 1 > $longest) {
                $validator->errors()->add('to', __('Pick at most :days days.', ['days' => $longest]));
            }
        }];
    }

    /**
     * The reports group mode can run, in the reports menu order.
     *
     * @return list<ReportKey>
     */
    public static function reports(): array
    {
        return array_values(array_filter(ReportKey::cases(), fn (ReportKey $key): bool => $key->runsInGroup()));
    }

    public function reportKey(): ReportKey
    {
        return ReportKey::tryFrom((string) $this->input('report')) ?? ReportKey::Arrivals;
    }

    public function filters(CarbonImmutable $today): ReportFilters
    {
        $key = $this->reportKey();
        $text = fn (string $name): ?string => is_string($this->input($name)) && $this->input($name) !== '' ? $this->input($name) : null;
        $groups = $key->groups();
        $group = ReportGroup::tryFrom((string) $this->input('group'));
        $group = $groups === [] ? null : (in_array($group, $groups, true) ? $group : $groups[0]);

        if ($key->maxRangeDays() === null) {
            $date = $text('date') ?? $today->toDateString();

            return new ReportFilters($date, $date, group: $group);
        }

        $defaultFrom = $key->opensOnMonth() ? $today->startOfMonth()->toDateString() : $today->subDays($key->defaultDays() - 1)->toDateString();
        $defaultTo = $key->opensOnMonth() ? $today->endOfMonth()->toDateString() : $today->toDateString();
        $from = $text('from');
        $to = $text('to');

        return new ReportFilters(from: $from ?? $to ?? $defaultFrom, to: $to ?? $from ?? $defaultTo, group: $group);
    }
}
