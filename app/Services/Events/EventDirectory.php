<?php

namespace App\Services\Events;

use App\Data\Events\EventRowData;
use App\Enums\EventStatus;
use App\Enums\FolioStatus;
use App\Models\Event;
use App\Models\FolioLine;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The Events list: a property's events with search (number, name or payer), status and date filters in the URL,
 * soonest first. The date filter keeps events whose dates overlap the month, counted from the business date. The
 * balance of each event folio comes from one sub-query, so the list runs a constant number of queries.
 * See docs/33-facilities/03-events-and-event-folios.md#events-list-index.
 */
class EventDirectory
{
    public const int PER_PAGE = 25;

    public const string DEFAULT_PERIOD = 'this_month';

    /** @var list<string> */
    public const array PERIODS = ['this_month', 'next_month', 'last_month', 'all'];

    public function __construct(private CurrentBusinessDate $currentBusinessDate, private PropertyToday $propertyToday) {}

    /**
     * @return list<array{value: string, label: string}>
     */
    public function periodOptions(): array
    {
        return [
            ['value' => 'this_month', 'label' => __('This month')],
            ['value' => 'next_month', 'label' => __('Next month')],
            ['value' => 'last_month', 'label' => __('Last month')],
            ['value' => 'all', 'label' => __('All dates')],
        ];
    }

    /**
     * The period in the URL, or This month. An unknown value is This month.
     */
    public function period(Request $request): string
    {
        $period = $request->string('filter.period')->toString();

        return in_array($period, self::PERIODS, true) ? $period : self::DEFAULT_PERIOD;
    }

    /**
     * @return LengthAwarePaginator<int, EventRowData>
     */
    public function paginate(Property $property, Request $request): LengthAwarePaginator
    {
        $balance = FolioLine::query()
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->selectRaw('COALESCE(SUM(folio_lines.amount), 0)::numeric(14, 2)')
            ->whereColumn('folios.event_id', 'events.id')
            ->where('folios.status', '<>', FolioStatus::Voided->value);
        $month = $this->monthOf($property, $this->period($request));

        return QueryBuilder::for(Event::query()->where('events.property_id', $property->id), $request)
            ->allowedFilters(
                AllowedFilter::callback('search', fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                    ->where('events.number', 'ilike', '%'.$search.'%')
                    ->orWhere('events.name', 'ilike', '%'.$search.'%')
                    ->orWhereHas('payerGuest', fn (Builder $guest) => $guest->where('full_name', 'ilike', '%'.$search.'%'))
                    ->orWhereHas('payerCompany', fn (Builder $company) => $company->where('name', 'ilike', '%'.$search.'%')))),
                AllowedFilter::callback('status', fn (Builder $query, string $status) => EventStatus::tryFrom($status) === null ? $query : $query->where('events.status', $status)),
                AllowedFilter::callback('period', fn (Builder $query) => $query),
            )
            ->when($month !== null, fn (Builder $query) => $query
                ->where('events.starts_on', '<=', $month[1]->toDateString())
                ->where('events.ends_on', '>=', $month[0]->toDateString()))
            ->orderBy('events.starts_on')
            ->orderBy('events.id')
            ->addSelect('events.*')
            ->selectSub($balance, 'balance')
            ->with(['payerGuest:id,full_name', 'payerCompany:id,name'])
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Event $event): EventRowData => EventRowData::fromModel($event, (string) ($event->getAttribute('balance') ?? '0.00')));
    }

    /**
     * The first and last day of the period's month, or null for All dates.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function monthOf(Property $property, string $period): ?array
    {
        $today = $this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property);
        $month = match ($period) {
            'next_month' => $today->startOfMonth()->addMonth(),
            'last_month' => $today->startOfMonth()->subMonth(),
            'all' => null,
            default => $today->startOfMonth(),
        };

        return $month === null ? null : [$month, $month->endOfMonth()];
    }
}
