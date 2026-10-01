<?php

namespace App\Services\Guests;

use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The guest list of one property: search, filters, sorts and pagination from the URL.
 * Search matches name, email and phone, and an exact ID number for staff who may view ID documents.
 */
class GuestDirectory
{
    public const int PER_PAGE = 25;

    public const string STATUS_VIP = 'vip';

    public const string STATUS_BLACKLISTED = 'blacklisted';

    public const string STATUS_ANONYMIZED = 'anonymized';

    public function __construct(private IdentityNumber $identityNumber) {}

    /**
     * @param  bool  $searchIdNumbers  Let the search also find an exact ID number. Only for staff who may view ID documents.
     * @return LengthAwarePaginator<int, Guest>
     */
    public function paginate(Property $property, Request $request, bool $searchIdNumbers = false): LengthAwarePaginator
    {
        $anonymized = $request->input('filter.status') === self::STATUS_ANONYMIZED;

        return QueryBuilder::for(Guest::query()->where('guests.property_id', $property->id)->notMerged(), $request)
            ->allowedFilters(
                AllowedFilter::callback('search', fn (Builder $query, string $search) => $this->search($query, $search, $searchIdNumbers)),
                AllowedFilter::callback('status', fn (Builder $query, string $status) => $this->status($query, $status)),
                AllowedFilter::callback('tag', fn (Builder $query, string $tag) => $query->whereHas(
                    'tags',
                    fn (Builder $tags) => $tags->where('tags.id', (int) $tag)->where('tags.property_id', $property->id),
                )),
            )
            ->when($anonymized, fn (Builder $query) => $query->whereNotNull('guests.anonymized_at'), fn (Builder $query) => $query->whereNull('guests.anonymized_at'))
            ->allowedSorts('full_name', 'created_at')
            ->defaultSort('full_name')
            ->with('tags')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @param  Builder<Guest>  $query
     */
    private function search(Builder $query, string $search, bool $searchIdNumbers): void
    {
        $search = trim($search);
        $idNumber = $this->identityNumber->normalize($search);

        $query->where(function (Builder $query) use ($search, $idNumber, $searchIdNumbers): void {
            $query->whereLike('guests.full_name', "%{$search}%")
                ->orWhereLike('guests.email', "%{$search}%")
                ->orWhereLike('guests.phone', '%'.preg_replace('/[\s()-]+/', '', $search).'%');

            if ($searchIdNumbers && strlen($idNumber) >= 4) {
                $query->orWhereIn('guests.id', GuestIdentityDocument::query()
                    ->whereBlind('number', GuestIdentityDocument::NUMBER_INDEX, $idNumber)
                    ->select('guest_identity_documents.guest_id'));
            }
        });
    }

    /**
     * @param  Builder<Guest>  $query
     */
    private function status(Builder $query, string $status): void
    {
        match ($status) {
            self::STATUS_VIP => $query->where('guests.is_vip', true),
            self::STATUS_BLACKLISTED => $query->blacklisted(),
            self::STATUS_ANONYMIZED => null,
            default => null,
        };
    }
}
