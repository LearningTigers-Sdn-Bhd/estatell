<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportRow;
use App\Data\Shared\BadgeData;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * Sorts and pages the full row list of a report on the server, with the `?sort` (`name` or `-name`) and `?page`
 * keys of `DataTable`. The sort name is a column key, which is also a property of the row. A row with no value (null)
 * in the sort column comes last, in either direction.
 */
class ReportPage
{
    public const int PER_PAGE = 25;

    /**
     * @template TRow of Data&ReportRow
     *
     * @param  list<TRow>  $rows
     * @param  list<string>  $columnKeys  The sort names that are allowed: the visible columns.
     * @return array{0: LengthAwarePaginator<int, TRow>, 1: string} The page and the sort that was used
     */
    public function paginate(array $rows, string $sort, string $defaultSort, array $columnKeys, int $page, string $path): array
    {
        [$sorted, $sort] = $this->sort($rows, $sort, $defaultSort, $columnKeys);

        $paginator = new LengthAwarePaginator(
            Collection::make($sorted)->forPage($page, self::PER_PAGE)->values(),
            count($sorted),
            self::PER_PAGE,
            $page,
            ['path' => $path],
        );

        return [$paginator, $sort];
    }

    /**
     * Sorts the full row list. A sort that is not a visible column falls back to the default. The exports call this
     * too, so they sort exactly like the table.
     *
     * @template TRow of Data&ReportRow
     *
     * @param  list<TRow>  $rows
     * @param  list<string>  $columnKeys  The sort names that are allowed: the visible columns.
     * @return array{0: list<TRow>, 1: string} The sorted rows and the sort that was used
     */
    public function sort(array $rows, string $sort, string $defaultSort, array $columnKeys): array
    {
        $descending = str_starts_with($sort, '-');
        $key = ltrim($sort, '-');

        if (! in_array($key, $columnKeys, true)) {
            $sort = $defaultSort;
            $descending = str_starts_with($sort, '-');
            $key = ltrim($sort, '-');
        }

        $filled = [];
        $blank = [];

        foreach ($rows as $row) {
            if (property_exists($row, $key) && $row->{$key} === null) {
                $blank[] = $row;
            } else {
                $filled[] = $row;
            }
        }

        $direction = $descending ? -1 : 1;

        usort($filled, fn (Data $a, Data $b): int => $direction * ($this->sortValue($a, $key) <=> $this->sortValue($b, $key)));

        $sorted = [...$filled, ...$blank];

        return [$sorted, $sort];
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
