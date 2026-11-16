<?php

namespace App\Services\Platform;

use App\Enums\ContactEnquiryStatus;
use App\Models\ContactEnquiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The admin Enquiries list: search, status filter, sorts and pagination from the URL. See docs/01-platform/08-contact-enquiries.md.
 */
class ContactEnquiryDirectory
{
    public const int PER_PAGE = 25;

    /**
     * @return LengthAwarePaginator<int, ContactEnquiry>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        return QueryBuilder::for(ContactEnquiry::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('search', fn (Builder $query, string $search) => $this->search($query, $search)),
                AllowedFilter::exact('status'),
            )
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * How many rows each status has, with every status present.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = ContactEnquiry::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(ContactEnquiryStatus::cases())->mapWithKeys(fn (ContactEnquiryStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)])->all();
    }

    /**
     * Part of the hotel name, the sender's name, the email address or the location.
     *
     * @param  Builder<Model>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $search = trim($search);

        $query->where(fn (Builder $match) => $match
            ->whereLike('hotel_name', "%{$search}%")
            ->orWhereLike('name', "%{$search}%")
            ->orWhereLike('email', "%{$search}%")
            ->orWhereLike('location', "%{$search}%"));
    }
}
