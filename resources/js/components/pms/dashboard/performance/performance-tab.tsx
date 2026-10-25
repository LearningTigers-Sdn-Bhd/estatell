import { Deferred } from '@inertiajs/react';
import { ChannelMixCard } from '@/components/pms/dashboard/performance/channel-mix-card';
import {
    HowFarAheadCard,
    LeadTimeCard,
} from '@/components/pms/dashboard/performance/lead-time-card';
import { MetricCard } from '@/components/pms/dashboard/performance/metric-card';
import { PerformanceSkeleton } from '@/components/pms/dashboard/performance/performance-skeleton';
import { PeriodBar } from '@/components/pms/dashboard/performance/period-bar';
import { cn } from 'cn';

/**
 * The Performance tab: the period bar comes with the page, the cards load as one deferred group. Inertia v3 shows no
 * fallback on a partial reload, so the cards dim while a new period loads. On wide screens two rows of three: the metric
 * cards, then the two channel mix cards and the lead time cards stacked. The first row fills the page height.
 */
export function PerformanceTab({
    period,
    performance,
}: {
    period: App.Data.Dashboard.PerformancePeriodData;
    performance?: App.Data.Dashboard.PerformanceSummaryData | null;
}) {
    return (
        <div className="flex flex-1 flex-col gap-3">
            <PeriodBar period={period} />
            <Deferred data="performance" fallback={<PerformanceSkeleton />}>
                {({ reloading }) =>
                    performance && (
                        <div
                            className={cn(
                                'grid gap-3 transition-opacity xl:flex-1 xl:grid-cols-3 xl:grid-rows-[1fr_auto]',
                                reloading && 'opacity-50',
                            )}
                            aria-busy={reloading}
                        >
                            {(['occupancy', 'adr', 'revpar'] as const).map(
                                (metric) => (
                                    <MetricCard
                                        key={metric}
                                        metric={metric}
                                        summary={performance}
                                        period={period}
                                    />
                                ),
                            )}
                            <ChannelMixCard
                                view="source"
                                mix={performance.channelMix}
                                currency={performance.currency}
                            />
                            <ChannelMixCard
                                view="type"
                                mix={performance.channelMix}
                                currency={performance.currency}
                            />
                            <div className="flex min-w-0 flex-col gap-3">
                                <LeadTimeCard leadTime={performance.leadTime} />
                                <HowFarAheadCard
                                    leadTime={performance.leadTime}
                                    period={period}
                                />
                            </div>
                        </div>
                    )
                }
            </Deferred>
        </div>
    );
}
