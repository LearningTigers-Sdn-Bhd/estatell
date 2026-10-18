import { Deferred } from '@inertiajs/react';
import { ChannelMixCard } from '@/components/pms/dashboard/performance/channel-mix-card';
import { KpiTiles } from '@/components/pms/dashboard/performance/kpi-tiles';
import { LeadTimeCard } from '@/components/pms/dashboard/performance/lead-time-card';
import { PerformanceSkeleton } from '@/components/pms/dashboard/performance/performance-skeleton';
import { PeriodBar } from '@/components/pms/dashboard/performance/period-bar';
import { TrendCard } from '@/components/pms/dashboard/performance/trend-card';
import { cn } from 'cn';

/**
 * The Performance tab: the period bar comes with the page, the cards load as one deferred group. Inertia v3 shows no
 * fallback on a partial reload, so the cards dim while a new period loads. On wide screens Trend sits beside Channel mix
 * and Lead time, and that row fills the page height.
 */
export function PerformanceTab({
    period,
    performance,
}: {
    period: App.Data.Dashboard.PerformancePeriodData;
    performance?: App.Data.Dashboard.PerformanceSummaryData | null;
}) {
    return (
        <div className="flex flex-1 flex-col gap-4">
            <PeriodBar period={period} />
            <Deferred data="performance" fallback={<PerformanceSkeleton />}>
                {({ reloading }) =>
                    performance && (
                        <div
                            className={cn(
                                'flex flex-1 flex-col gap-4 transition-opacity',
                                reloading && 'opacity-50',
                            )}
                            aria-busy={reloading}
                        >
                            <KpiTiles summary={performance} />
                            <div className="grid gap-4 xl:flex-1 xl:grid-cols-3">
                                <div className="flex min-w-0 flex-col xl:col-span-2">
                                    <TrendCard
                                        summary={performance}
                                        period={period}
                                    />
                                </div>
                                <div className="flex min-w-0 flex-col gap-4">
                                    <ChannelMixCard
                                        mix={performance.channelMix}
                                        currency={performance.currency}
                                    />
                                    <LeadTimeCard
                                        leadTime={performance.leadTime}
                                        period={period}
                                    />
                                </div>
                            </div>
                        </div>
                    )
                }
            </Deferred>
        </div>
    );
}
