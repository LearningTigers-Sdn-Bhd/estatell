<?php

namespace App\Services\Reports;

use App\Data\Shared\BadgeData;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * Sorts and pages the full row list of a report on the server, with the `?sort` (`name` or `-name`) and `?page`
 * keys of `DataTable`. The sort name is a column key, which is also a property of the row.
 */
class ReportPage
{
    public const int PER_PAGE = 25;

    /**
     * @template TRow of Data
     *
     * @param  list<TRow>  $rows
     * @param  list<string>  $columnKeys  The sort names that are allowed.
     * @return array{0: LengthAwarePaginator<int, TRow>, 1: string} The page and the sort that was used
     */
    public function paginate(array $rows, string $sort, string $defaultSort, array $columnKeys, int $page, string $path): array
    {
        $descending = str_starts_with($sort, '-');
        $key = ltrim($sort, '-');

        if (! in_array($key, $columnKeys, true)) {
            $sort = $defaultSort;
            $descending = str_starts_with($sort, '-');
            $key = ltrim($sort, '-');
        }

        $sorted = Collection::make($rows)->sortBy(
            fn (Data $row): float|int|string => $this->sortValue($row, $key),
            SORT_REGULAR,
            $descending,
        )->values();

        $paginator = new LengthAwarePaginator(
            $sorted->forPage($page, self::PER_PAGE)->values(),
            $sorted->count(),
            self::PER_PAGE,
            $page,
            ['path' => $path],
        );

        return [$paginator, $sort];
    }

    private function sortValue(Data $row, string $key): float|int|string
    {
        $value = $row->{$key} ?? '';
        $value = $value instanceof BadgeData ? $value->label : $value;

        return match (true) {
            is_int($value) => $value,
            is_numeric($value) => (float) $value,
            default => mb_strtolower((string) $value),
        };
    }
}
