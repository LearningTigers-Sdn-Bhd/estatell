<?php

namespace App\Services\Platform;

use App\Data\Platform\AdminMailEventRowData;
use App\Enums\MailStatus;
use App\Models\MailEvent;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The admin mail log: search, filters, sorts and pagination from the URL. See docs/04-notifications/03-mail-log.md.
 */
class MailLogDirectory
{
    public const int PER_PAGE = 25;

    /**
     * @return LengthAwarePaginator<int, MailEvent>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        return QueryBuilder::for(MailEvent::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('search', fn (Builder $query, string $search) => $this->search($query, $search)),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('property', 'property_id'),
                AllowedFilter::callback('from', fn (Builder $query, string $date) => $query->where('sent_at', '>=', $date.' 00:00:00')),
                AllowedFilter::callback('to', fn (Builder $query, string $date) => $query->where('sent_at', '<=', $date.' 23:59:59')),
            )
            ->allowedSorts('sent_at')
            ->defaultSort('-sent_at')
            ->with(AdminMailEventRowData::RELATIONS)
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * The properties the property filter offers, by name.
     *
     * @return list<array{value: string, label: string}>
     */
    public function propertyOptions(): array
    {
        return array_map(
            fn (Property $property): array => ['value' => (string) $property->id, 'label' => $property->name],
            array_values(Property::query()->orderBy('name')->get(['id', 'name'])->all()),
        );
    }

    /**
     * How many rows each status has, with every status present.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = MailEvent::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(MailStatus::cases())->mapWithKeys(fn (MailStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)])->all();
    }

    /**
     * An email address matches the recipient exactly (blind index); anything else matches part of the subject.
     *
     * @param  Builder<Model>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $search = trim($search);

        if (filter_var($search, FILTER_VALIDATE_EMAIL) !== false) {
            $query->whereBlind('recipient', MailEvent::RECIPIENT_INDEX, $search);

            return;
        }

        $query->whereLike('subject', "%{$search}%");
    }
}
