<?php

namespace App\Services\Platform;

use App\Data\Platform\AdminSignInEventRowData;
use App\Enums\SignInResult;
use App\Models\Property;
use App\Models\SignInEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The admin sign-in log: search, filters, sorts and pagination from the URL. See docs/01-platform/07-sign-in-log.md.
 */
class SignInLogDirectory
{
    public const int PER_PAGE = 25;

    /**
     * @return LengthAwarePaginator<int, SignInEvent>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        return QueryBuilder::for(SignInEvent::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('search', fn (Builder $query, string $search) => $this->search($query, $search)),
                AllowedFilter::exact('result'),
                AllowedFilter::callback('property', fn (Builder $query, string $propertyId) => $query->whereHas('user', fn (Builder $user) => $user->where('property_id', $propertyId))),
                AllowedFilter::callback('from', fn (Builder $query, string $date) => $query->where('occurred_at', '>=', $date.' 00:00:00')),
                AllowedFilter::callback('to', fn (Builder $query, string $date) => $query->where('occurred_at', '<=', $date.' 23:59:59')),
            )
            ->allowedSorts('occurred_at')
            ->defaultSort('-occurred_at')
            ->with(AdminSignInEventRowData::RELATIONS)
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
     * How many rows each result has, with every result present.
     *
     * @return array<string, int>
     */
    public function resultCounts(): array
    {
        $counts = SignInEvent::query()->selectRaw('result, count(*) as total')->groupBy('result')->pluck('total', 'result');

        return collect(SignInResult::cases())->mapWithKeys(fn (SignInResult $result): array => [$result->value => (int) ($counts[$result->value] ?? 0)])->all();
    }

    /**
     * An IP address matches exactly (blind index), an email address matches the account's email exactly, anything
     * else matches part of the account's name.
     *
     * @param  Builder<Model>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $search = trim($search);

        if (filter_var($search, FILTER_VALIDATE_IP) !== false) {
            $query->whereBlind('ip_address', SignInEvent::IP_INDEX, $search);

            return;
        }

        if (filter_var($search, FILTER_VALIDATE_EMAIL) !== false) {
            $query->whereHas('user', fn (Builder $user) => $user->where('email', Str::lower($search)));

            return;
        }

        $query->whereHas('user', fn (Builder $user) => $user->whereLike('name', "%{$search}%"));
    }
}
