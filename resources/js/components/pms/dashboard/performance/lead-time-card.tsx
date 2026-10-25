import { IconChip } from '@/components/pms/dashboard/performance/icon-chip';
import { CalendarClock, Hourglass } from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ChangeLine } from '@/components/pms/dashboard/performance/change-line';
import {
    describeChange,
    rangeLabel,
} from '@/components/pms/dashboard/performance/format';
import { EmptyState } from '@/components/pms/empty-state';

const labels: Record<string, string> = {
    'same-day': 'Same day',
    '1-7': '1–7 days',
    '8-30': '8–30 days',
    '31-plus': '31+ days',
};

/**
 * The average lead time: whole days from booking to arrival. Neither direction is good or bad, so the change stays muted.
 */
export function LeadTimeCard({
    leadTime,
}: {
    leadTime: App.Data.Dashboard.LeadTimeData;
}) {
    const lead = leadTime.average;
    const days = lead.value === null ? null : Number(lead.value);

    return (
        <Card className="min-w-0">
            <CardHeader>
                <div className="flex items-center gap-2">
                    <IconChip icon={CalendarClock} />
                    <div className="flex min-w-0 flex-col gap-1">
                        <CardTitle>Lead time</CardTitle>
                        <CardDescription>
                            Average days between booking and arrival. A longer
                            lead time means guests plan earlier.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col items-end gap-2">
                <p className="font-heading text-2xl font-semibold tabular-nums">
                    {days === null
                        ? 'No data'
                        : `${days} ${days === 1 ? 'day' : 'days'}`}
                </p>
                {days === null ? (
                    <p className="text-xs text-muted-foreground">
                        No arrivals in this period
                    </p>
                ) : (
                    <ChangeLine
                        change={describeChange(lead.change, 'days')}
                        neutral
                    />
                )}
            </CardContent>
        </Card>
    );
}

/**
 * How far ahead guests book: four buckets as a share of the counted arrivals. Walk-ins are left out.
 */
export function HowFarAheadCard({
    leadTime,
    period,
}: {
    leadTime: App.Data.Dashboard.LeadTimeData;
    period: App.Data.Dashboard.PerformancePeriodData;
}) {
    return (
        <Card className="min-w-0 flex-1">
            <CardHeader>
                <div className="flex items-center gap-2">
                    <IconChip icon={Hourglass} />
                    <div className="flex min-w-0 flex-col gap-1">
                        <CardTitle>How far ahead guests book</CardTitle>
                        <CardDescription>
                            Arrivals {rangeLabel(period.from, period.to)} ·
                            walk-ins not counted
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                {leadTime.units === 0 ? (
                    <EmptyState
                        icon={Hourglass}
                        title="No arrivals in this period"
                        description="Walk-ins are not counted here."
                    />
                ) : (
                    leadTime.buckets.map((bucket) => (
                        <div
                            key={bucket.key}
                            className="grid grid-cols-[5.5rem_1fr_3rem] items-center gap-3 text-sm"
                        >
                            <span>{labels[bucket.key]}</span>
                            <div
                                className="h-3 overflow-hidden rounded-full bg-muted"
                                role="presentation"
                            >
                                <div
                                    className="h-full bg-chart-3"
                                    style={{ width: `${bucket.share}%` }}
                                />
                            </div>
                            <span className="text-right tabular-nums">
                                {bucket.share}%
                            </span>
                        </div>
                    ))
                )}
            </CardContent>
        </Card>
    );
}
