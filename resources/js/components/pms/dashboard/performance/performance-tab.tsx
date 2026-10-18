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
 * fallback on a partial reload, so the cards dim while a new period loads.
 */
export function PerformanceTab({
    period,
    performance,
}: {
    period: App.Data.Dashboard.PerformancePeriodData;
    performance?: App.Data.Dashboard.PerformanceSummaryData | null;
}) {
    return (
        <div className="flex flex-col gap-4">
            <PeriodBar period={period} />
            <Deferred data="performance" fallback={<PerformanceSkeleton />}>
                {({ reloading }) =>
                    performance && (
                        <div
                            className={cn(
                                'flex flex-col gap-4 transition-opacity',
                                reloading && 'opacity-50',
                            )}
                            aria-busy={reloading}
                        >
                            <KpiTiles summary={performance} />
                            <div className="grid gap-4 xl:grid-cols-3">
                                <div className="min-w-0 xl:col-span-2">
                                    <TrendCard
                                        summary={performance}
                                        period={period}
                                    />
                                </div>
                                <div className="min-w-0">
                                    <ChannelMixCard
                                        mix={performance.channelMix}
                                        currency={performance.currency}
                                    />
                                </div>
                            </div>
                            <LeadTimeCard
                                leadTime={performance.leadTime}
                                period={period}
                            />
                        </div>
                    )
                }
            </Deferred>
        </div>
    );
}
