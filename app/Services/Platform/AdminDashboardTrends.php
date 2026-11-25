<?php

namespace App\Services\Platform;

use App\Data\Platform\AdminDashboardMonthData;
use App\Enums\CommissionEntryType;
use App\Enums\CommissionService;
use App\Models\CommissionEntry;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * The last six months, this one included, in Malaysia time: sign-ups, go-lives and Estatell's commission income
 * by service. Income is the sum of `commission` entries shown as a positive number, so reversals net out.
 * See docs/01-platform/10-admin-dashboard.md#charts.
 */
class AdminDashboardTrends
{
    private const int MONTHS = 6;

    /**
     * @return list<AdminDashboardMonthData>
     */
    public function months(): array
    {
        $first = now(SignInLogDirectory::TIME_ZONE)->startOfMonth()->subMonths(self::MONTHS - 1);
        $since = $first->utc();

        $signUps = $this->countByMonth('created_at', $since);
        $goLives = $this->countByMonth('went_live_at', $since);
        $income = CommissionEntry::query()
            ->where('type', CommissionEntryType::Commission)
            ->where('created_at', '>=', $since)
            ->selectRaw($this->monthSql('created_at').' as month, service, sum(amount) as total')
            ->groupBy('month', 'service')
            ->get()
            ->groupBy('month');

        return array_values(collect(range(0, self::MONTHS - 1))
            ->map(function (int $offset) use ($first, $signUps, $goLives, $income): AdminDashboardMonthData {
                $month = $first->addMonths($offset)->format('Y-m');
                /** @var Collection<int, CommissionEntry> $entries */
                $entries = $income->get($month, collect());

                return new AdminDashboardMonthData(
                    month: $month,
                    signUps: (int) ($signUps[$month] ?? 0),
                    goLives: (int) ($goLives[$month] ?? 0),
                    bookingEngine: $this->income($entries, CommissionService::BookingEngine),
                    channelManager: $this->income($entries, CommissionService::ChannelManager),
                );
            })
            ->all());
    }

    /**
     * @param  literal-string  $column
     * @return Collection<string, int>
     */
    private function countByMonth(string $column, mixed $since): Collection
    {
        return Property::query()
            ->where($column, '>=', $since)
            ->selectRaw($this->monthSql($column).' as month, count(*) as total')
            ->groupBy('month')
            ->pluck('total', 'month');
    }

    /**
     * The `Y-m` month of a UTC timestamp column in Malaysia time.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    private function monthSql(string $column): string
    {
        return "to_char(({$column} at time zone 'UTC') at time zone '".SignInLogDirectory::TIME_ZONE."', 'YYYY-MM')";
    }

    /**
     * @param  Collection<int, CommissionEntry>  $entries
     */
    private function income(Collection $entries, CommissionService $service): string
    {
        $total = $entries->first(fn (CommissionEntry $entry): bool => $entry->service === $service)?->getAttribute('total');

        return (string) BigDecimal::of($total === null ? '0' : (string) $total)->negated()->toScale(2);
    }
}
