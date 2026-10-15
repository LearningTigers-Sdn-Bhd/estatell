<?php

namespace App\Services\Reports;

use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\Activitylog\Models\Activity;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ActivityLog
{
    public const int PER_PAGE = 25;

    /**
     * Only explicitly property-tagged entries and entries directly about this property belong in its log.
     * Do not infer ownership through the causer: support users work across properties.
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    public function paginate(Property $property, Request $request): LengthAwarePaginator
    {
        $search = trim($request->string('filter.search')->toString());

        $query = Activity::query()->where(fn (Builder $query) => $query
            ->where('properties->property_id', $property->id)
            ->orWhere(fn (Builder $query) => $query
                ->where('subject_type', $property->getMorphClass())
                ->where('subject_id', $property->id)));

        return QueryBuilder::for($query, $request)
            ->allowedFilters(AllowedFilter::callback('search', fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->whereLike('description', '%'.$search.'%')
                    ->orWhereLike('event', '%'.$search.'%'))))
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->orderByDesc('id')
            ->with('causer')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }
}
