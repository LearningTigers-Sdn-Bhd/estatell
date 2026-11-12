<?php

namespace App\Services\Guests;

use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use App\Services\Property\PropertyToday;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The guest list of one property: search, filters, sorts and pagination from the URL.
 * Search matches name, email and phone, and an exact ID number for staff who may view ID documents.
 */
class GuestDirectory
{
    public const int PER_PAGE = 25;

    public const int LOOKUP_LIMIT = 8;

    public const int LOOKUP_MIN_LENGTH = 2;

    public const string STATUS_VIP = 'vip';

    public const string STATUS_BLACKLISTED = 'blacklisted';

    public const string STATUS_ANONYMIZED = 'anonymized';

    public const string RETURNING_FIRST = 'first';

    public const string RETURNING_YES = 'returning';

    public const string CONTACT_NO_EMAIL = 'no_email';

    public const string CONTACT_NO_PHONE = 'no_phone';

    public const string CONTACT_MISSING = 'missing';

    public function __construct(
        private IdentityNumber $identityNumber,
        private GuestStayHistory $stayHistory,
        private PropertyToday $propertyToday,
        private CountryOptions $countryOptions,
    ) {}

    /**
     * Resolve the entire selection before a bulk write. A missing or foreign ID refuses the whole request.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Guest>
     */
    public function selected(Property $property, array $ids): Collection
    {
        return Guest::query()->where('property_id', $property->id)->orderBy('id')->findOrFail($ids);
    }

    /**
     * @param  bool  $searchIdNumbers  Let the search also find an exact ID number. Only for staff who may view ID documents.
     * @return LengthAwarePaginator<int, Guest>
     */
    public function paginate(Property $property, Request $request, bool $searchIdNumbers = false): LengthAwarePaginator
    {
        $anonymized = $request->input('filter.status') === self::STATUS_ANONYMIZED;

        $since = $this->propertyToday->date($property)->subYear()->toDateString();
        $base = Guest::query()
            ->where('guests.property_id', $property->id)
            ->notMerged()
            ->leftJoinSub($this->stayHistory->figures($property, $since), 'guest_stays', 'guest_stays.guest_id', '=', 'guests.id')
            ->select('guests.*', 'guest_stays.stays', 'guest_stays.spend', 'guest_stays.last_visit', 'guest_stays.in_house');

        $guests = QueryBuilder::for($base, $request)
            ->allowedFilters(
                AllowedFilter::callback('search', $this->searchFilter($searchIdNumbers)),
                AllowedFilter::callback('status', $this->status(...)),
                AllowedFilter::callback('tag', fn (Builder $query, string $tag) => $query->whereHas(
                    'tags',
                    fn (Builder $tags) => $tags->where('tags.id', (int) $tag)->where('tags.property_id', $property->id),
                )),
                AllowedFilter::callback('source', fn (Builder $query, string $source) => $query->whereIn('guests.id', $this->stayHistory->guestIdsWithSource($property, (int) $source))),
                AllowedFilter::callback('returning', $this->returning(...)),
                AllowedFilter::callback('contact', $this->contact(...)),
            )
            ->allowedSorts(
                'full_name',
                'created_at',
                AllowedSort::callback('email', $this->emptyLastSort("NULLIF(lower(guests.email), '')")),
                AllowedSort::callback('phone', $this->emptyLastSort("NULLIF(guests.phone, '')")),
                AllowedSort::callback('nationality', $this->nationalitySort(...)),
                AllowedSort::callback('last_visit', $this->figureSort('guest_stays.latest_departure')),
                AllowedSort::callback('stays', $this->figureSort('guest_stays.stays')),
                AllowedSort::callback('spend', $this->figureSort('guest_stays.spend')),
            )
            ->defaultSort('full_name')
            ->when($anonymized, fn (Builder $query) => $query->whereNotNull('guests.anonymized_at'), fn (Builder $query) => $query->whereNull('guests.anonymized_at'))
            ->with('tags')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $sources = $this->stayHistory->latestSources($property, array_values($guests->getCollection()->map(fn (Guest $guest): int => $guest->id)->all()));

        foreach ($guests->getCollection() as $guest) {
            $guest->setAttribute('latest_source', $sources[$guest->id] ?? null);
        }

        return $guests;
    }

    /**
     * The first guests whose name, email or phone contains the text, for a picker. Merged and anonymized guests are left out.
     *
     * @return Collection<int, Guest>
     */
    public function lookup(Property $property, string $text, int $limit = self::LOOKUP_LIMIT): Collection
    {
        if (mb_strlen(trim($text)) < self::LOOKUP_MIN_LENGTH) {
            return new Collection;
        }

        return Guest::query()
            ->where('guests.property_id', $property->id)
            ->notMerged()
            ->whereNull('guests.anonymized_at')
            ->matchingText($text)
            ->orderBy('guests.full_name')
            ->orderBy('guests.id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Closure(Builder<Guest>, string): void
     */
    private function searchFilter(bool $searchIdNumbers): Closure
    {
        return fn (Builder $query, string $search) => $this->search($query, $search, $searchIdNumbers);
    }

    /**
     * @param  Builder<Guest>  $query
     */
    private function search(Builder $query, string $search, bool $searchIdNumbers): void
    {
        $search = trim($search);
        $idNumber = $this->identityNumber->normalize($search);

        $query->where(function (Builder $query) use ($search, $idNumber, $searchIdNumbers): void {
            $query->matchingText($search);

            if ($searchIdNumbers && strlen($idNumber) >= 4) {
                $query->orWhereIn('guests.id', GuestIdentityDocument::query()
                    ->whereBlind('number', GuestIdentityDocument::NUMBER_INDEX, $idNumber)
                    ->select('guest_identity_documents.guest_id'));
            }
        });
    }

    /**
     * First stay is one stay; Returning is two or more. Guests with no stays match neither.
     *
     * @param  Builder<Guest>  $query
     */
    private function returning(Builder $query, string $returning): void
    {
        match ($returning) {
            self::RETURNING_FIRST => $query->where('guest_stays.stays', 1),
            self::RETURNING_YES => $query->where('guest_stays.stays', '>=', 2),
            default => null,
        };
    }

    /**
     * Guests with no email, no phone, or either missing.
     *
     * @param  Builder<Guest>  $query
     */
    private function contact(Builder $query, string $contact): void
    {
        $noEmail = fn (Builder $query) => $query->whereNull('guests.email')->orWhere('guests.email', '');
        $noPhone = fn (Builder $query) => $query->whereNull('guests.phone')->orWhere('guests.phone', '');

        match ($contact) {
            self::CONTACT_NO_EMAIL => $query->where($noEmail),
            self::CONTACT_NO_PHONE => $query->where($noPhone),
            self::CONTACT_MISSING => $query->where(fn (Builder $query) => $query->where($noEmail)->orWhere($noPhone)),
            default => null,
        };
    }

    /**
     * A sort on a text column that may be empty. Empty values go last in both directions, then by name.
     *
     * @param  literal-string  $expression
     * @return Closure(Builder<Guest>, bool): void
     */
    private function emptyLastSort(string $expression): Closure
    {
        return function (Builder $query, bool $descending) use ($expression): void {
            $query->orderByRaw($expression.($descending ? ' DESC' : ' ASC').' NULLS LAST')->orderBy('guests.full_name')->orderBy('guests.id');
        };
    }

    /**
     * Nationality sorts by the country name shown, not the code: the position of the code in the codes listed by name.
     * An empty or unknown code goes last.
     *
     * @param  Builder<Guest>  $query
     */
    private function nationalitySort(Builder $query, bool $descending): void
    {
        $codes = array_column($this->countryOptions->options(), 'value');

        $query->orderByRaw('array_position(?::text[], guests.nationality_code)'.($descending ? ' DESC' : ' ASC').' NULLS LAST', ['{'.implode(',', $codes).'}'])
            ->orderBy('guests.full_name')
            ->orderBy('guests.id');
    }

    /**
     * A sort on a stay figure. Guests with no stays go last in both directions, then by name.
     *
     * @param  literal-string  $column
     * @return Closure(Builder<Guest>, bool): void
     */
    private function figureSort(string $column): Closure
    {
        return function (Builder $query, bool $descending) use ($column): void {
            $query->orderByRaw($column.($descending ? ' DESC' : ' ASC').' NULLS LAST')->orderBy('guests.full_name')->orderBy('guests.id');
        };
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
