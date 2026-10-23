<?php

namespace App\Services\Platform;

use App\Models\Property;
use App\Services\Staff\PropertyOwners;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The admin list of all properties: search, filters, sorts and pagination from the URL.
 */
class PropertyDirectory
{
    public const int PER_PAGE = 25;

    public const string DIRECT = 'direct';

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
                AllowedFilter::callback('partner', fn (Builder $query, string $partner) => $this->partner($query, $partner)),
            )
            ->allowedSorts('code', 'name', 'created_at')
            ->defaultSort('-created_at')
            ->select('properties.*')
            ->with('partner:id,name')
            ->tap(fn (Builder $query) => $this->propertyOwners->addOwnerColumns($query))
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * A partner id lists that partner's properties; `direct` lists the properties that came with no partner.
     *
     * @param  Builder<Model>  $query
     */
    private function partner(Builder $query, string $partner): void
    {
        if ($partner === self::DIRECT) {
            $query->whereNull('properties.partner_id');
        } elseif (ctype_digit($partner)) {
            $query->where('properties.partner_id', (int) $partner);
        } else {
            $query->whereRaw('false');
        }
    }

    /**
     * A property code (two letters and digits) matches the code exactly; anything else matches the name or city.
     *
     * @param  Builder<Model>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $search = trim($search);

        if (preg_match('/^[A-Za-z]{2}[0-9]{4,}$/', $search) === 1) {
            $query->where('code', Str::upper($search));

            return;
        }

        $query->where(fn (Builder $query) => $query
            ->whereLike('name', "%{$search}%")
            ->orWhereLike('city', "%{$search}%"));
    }
}
