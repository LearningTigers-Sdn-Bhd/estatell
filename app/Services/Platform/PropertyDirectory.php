<?php

namespace App\Services\Platform;

use App\Models\Property;
use App\Services\Staff\PropertyOwners;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The admin list of all properties: search, filters, sorts and pagination from the URL.
 */
class PropertyDirectory
{
    public const int PER_PAGE = 25;

    public function __construct(private PropertyOwners $propertyOwners) {}

    /**
     * @return LengthAwarePaginator<int, Property>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        return QueryBuilder::for(Property::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('search', fn (Builder $query, string $search) => $this->search($query, $search)),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('type'),
            )
            ->allowedSorts('code', 'name', 'created_at')
            ->defaultSort('-code')
            ->select('properties.*')
            ->tap(fn (Builder $query) => $this->propertyOwners->addOwnerColumns($query))
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * A number matches the code exactly; anything else matches the name or city.
     *
     * @param  Builder<Property>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $search = trim($search);

        if (ctype_digit($search)) {
            $query->where('code', (int) $search);

            return;
        }

        $query->where(fn (Builder $query) => $query
            ->whereLike('name', "%{$search}%")
            ->orWhereLike('city', "%{$search}%"));
    }
}
