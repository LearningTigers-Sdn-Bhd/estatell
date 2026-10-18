import { Hourglass } from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { rangeLabel } from '@/components/pms/dashboard/performance/format';

const labels: Record<string, string> = {
    'same-day': 'Same day',
    '1-7': '1–7 days',
    '8-30': '8–30 days',
    '31-plus': '31+ days',
};

/**
 * How far ahead guests book: four buckets as a share of the counted arrivals. Walk-ins are left out.
 */
export function LeadTimeCard({
    leadTime,
    period,
}: {
    leadTime: App.Data.Dashboard.LeadTimeData;
    period: App.Data.Dashboard.PerformancePeriodData;
}) {
    return (
        <Card className="min-w-0">
            <CardHeader>
                <div className="flex items-center gap-2">
                    <Hourglass
                        aria-hidden
                        className="size-5 shrink-0 text-muted-foreground"
                    />
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
                    <p className="text-sm text-muted-foreground">
                        No arrivals in this period
                    </p>
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
