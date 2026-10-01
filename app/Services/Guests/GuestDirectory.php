<?php

namespace App\Services\Guests;

use App\Models\Guest;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The guest list of one property: search, filters, sorts and pagination from the URL.
 */
class GuestDirectory
{
    public const int PER_PAGE = 25;

    public const string STATUS_VIP = 'vip';

    public const string STATUS_BLACKLISTED = 'blacklisted';

    /**
     * @return LengthAwarePaginator<int, Guest>
     */
    public function paginate(Property $property, Request $request): LengthAwarePaginator
    {
        return QueryBuilder::for(Guest::query()->where('guests.property_id', $property->id)->notMerged(), $request)
            ->allowedFilters(
                AllowedFilter::callback('search', fn (Builder $query, string $search) => $this->search($query, $search)),
                AllowedFilter::callback('status', fn (Builder $query, string $status) => $this->status($query, $status)),
                AllowedFilter::callback('tag', fn (Builder $query, string $tag) => $query->whereHas(
                    'tags',
                    fn (Builder $tags) => $tags->where('tags.id', (int) $tag)->where('tags.property_id', $property->id),
                )),
            )
            ->allowedSorts('full_name', 'created_at')
            ->defaultSort('full_name')
            ->with('tags')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @param  Builder<Guest>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $search = trim($search);

        $query->where(fn (Builder $query) => $query
            ->whereLike('guests.full_name', "%{$search}%")
            ->orWhereLike('guests.email', "%{$search}%")
            ->orWhereLike('guests.phone', '%'.preg_replace('/[\s()-]+/', '', $search).'%'));
    }

    /**
     * @param  Builder<Guest>  $query
     */
    private function status(Builder $query, string $status): void
    {
        match ($status) {
            self::STATUS_VIP => $query->where('guests.is_vip', true),
            self::STATUS_BLACKLISTED => $query->blacklisted(),
            default => null,
        };
    }
}
