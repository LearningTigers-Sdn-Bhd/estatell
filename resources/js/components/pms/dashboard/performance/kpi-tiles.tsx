import { BedDouble, CalendarClock, Coins, TrendingUp } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { MoneyText } from '@/components/pms/money-text';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from 'cn';
import {
    describeChange,
    whole,
} from '@/components/pms/dashboard/performance/format';
import type { Change } from '@/components/pms/dashboard/performance/format';

type Summary = App.Data.Dashboard.PerformanceSummaryData;
type Kpi = App.Data.Dashboard.PerformanceKpiData;

export function ChangeLine({
    change,
    neutral = false,
}: {
    change: Change | null;
    neutral?: boolean;
}) {
    if (change === null) {
        return (
            <p className="text-xs text-muted-foreground">
                Nothing to compare with
            </p>
        );
    }

    return (
        <p
            className={cn(
                'w-fit rounded-md px-1.5 py-0.5 text-xs font-medium tabular-nums',
                neutral || change.direction === 'same'
                    ? 'bg-muted text-muted-foreground'
                    : change.direction === 'up'
                      ? 'bg-primary/10 text-primary'
                      : 'bg-warning/10 text-warning-text',
            )}
        >
            {change.text}
        </p>
    );
}

function KpiTile({
    title,
    icon: Icon,
    value,
    empty,
    change,
    neutral,
}: {
    title: string;
    icon: LucideIcon;
    value: React.ReactNode | null;
    empty: string;
    change: Change | null;
    neutral?: boolean;
}) {
    return (
        <Card size="sm" className="min-w-0">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <span
                        aria-hidden
                        className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/5 text-primary"
                    >
                        <Icon className="size-5" />
                    </span>
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-1">
                <p className="font-heading text-xl font-semibold tabular-nums">
                    {value ?? '–'}
                </p>
                {value === null ? (
                    <p className="text-xs text-muted-foreground">{empty}</p>
                ) : (
                    <ChangeLine change={change} neutral={neutral} />
                )}
            </CardContent>
        </Card>
    );
}

/**
 * Occupancy, ADR, RevPAR and lead time. A figure the range cannot give shows a dash and says why.
 */
export function KpiTiles({ summary }: { summary: Summary }) {
    const noStays = 'No stays in this period';
    const auditEmpty = summary.hasStays
        ? 'No night audit figures for this period'
        : noStays;
    const money = (kpi: Kpi) =>
        kpi.value === null ? null : (
            <MoneyText amount={kpi.value} currency={summary.currency} />
        );
    const lead = summary.leadTime.average;
    const days = lead.value === null ? null : Number(lead.value);

    return (
        <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
            <KpiTile
                title="Occupancy"
                icon={BedDouble}
                value={
                    summary.occupancy.value === null
                        ? null
                        : `${whole(summary.occupancy.value)}%`
                }
                empty={auditEmpty}
                change={describeChange(summary.occupancy.change, 'pt')}
            />
            <KpiTile
                title="ADR"
                icon={Coins}
                value={money(summary.adr)}
                empty={auditEmpty}
                change={describeChange(summary.adr.change, '%')}
            />
            <KpiTile
                title="RevPAR"
                icon={TrendingUp}
                value={money(summary.revpar)}
                empty={auditEmpty}
                change={describeChange(summary.revpar.change, '%')}
            />
            <KpiTile
                title="Lead time"
                icon={CalendarClock}
                value={
                    days === null
                        ? null
                        : `${days} ${days === 1 ? 'day' : 'days'}`
                }
                empty="No arrivals in this period"
                change={describeChange(lead.change, 'days')}
                neutral
            />
        </div>
    );
}
