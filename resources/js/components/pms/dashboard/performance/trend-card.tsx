import { ChartLine } from 'lucide-react';
import { useState } from 'react';
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    longDate,
    monthLabel,
    shortDate,
} from '@/components/pms/dashboard/performance/format';

type Metric = 'occupancy' | 'adr' | 'revpar';

const metrics: { value: Metric; label: string }[] = [
    { value: 'occupancy', label: 'Occupancy' },
    { value: 'adr', label: 'ADR' },
    { value: 'revpar', label: 'RevPAR' },
];

const config = {
    current: { label: 'This period', color: 'var(--chart-3)' },
    previous: { label: 'Compared', color: 'var(--muted-foreground)' },
} satisfies ChartConfig;

/**
 * The trend of one metric by day, or by month for 12 months, with the compared range as a dashed line. The metric
 * switch is display only.
 */
export function TrendCard({
    summary,
    period,
}: {
    summary: App.Data.Dashboard.PerformanceSummaryData;
    period: App.Data.Dashboard.PerformancePeriodData;
}) {
    const [metric, setMetric] = useState<Metric>('occupancy');
    const byMonth = period.groupBy === 'month';
    const label = (date: string) =>
        byMonth ? monthLabel(date) : longDate(date);
    const previousKey =
        metric === 'occupancy'
            ? 'previousOccupancy'
            : metric === 'adr'
              ? 'previousAdr'
              : 'previousRevpar';
    const data = summary.trend.map((point) => ({
        date: point.date,
        previousDate: point.previousDate,
        current: point[metric],
        previous: point[previousKey],
    }));
    const format = (value: number) =>
        metric === 'occupancy' ? `${Math.round(value)}%` : value.toFixed(0);

    const empty = data.every((point) => point.current === null);

    return (
        <Card className="min-w-0 flex-1">
            <CardHeader className="flex flex-wrap items-center justify-between gap-2">
                <CardTitle className="flex items-center gap-2">
                    <ChartLine
                        aria-hidden
                        className="size-5 text-muted-foreground"
                    />
                    Trend
                </CardTitle>
                <ToggleGroup
                    variant="outline"
                    size="sm"
                    spacing={0}
                    value={[metric]}
                    onValueChange={(value) =>
                        value[0] && setMetric(value[0] as Metric)
                    }
                    aria-label="Trend metric"
                >
                    {metrics.map((item) => (
                        <ToggleGroupItem key={item.value} value={item.value}>
                            {item.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col">
                {empty ? (
                    <p className="flex min-h-64 flex-1 items-center justify-center text-sm text-muted-foreground">
                        {summary.hasStays
                            ? 'No night audit figures for this period'
                            : 'No stays in this period'}
                    </p>
                ) : (
                    <ChartContainer
                        config={config}
                        className="aspect-auto min-h-64 w-full flex-1"
                        role="img"
                        aria-label={`${metrics.find((item) => item.value === metric)?.label} trend`}
                    >
                        <LineChart
                            accessibilityLayer
                            data={data}
                            margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="date"
                                tickLine={false}
                                axisLine={false}
                                tickMargin={8}
                                minTickGap={32}
                                tickFormatter={(date: string) =>
                                    byMonth ? monthLabel(date) : shortDate(date)
                                }
                            />
                            <YAxis
                                domain={
                                    metric === 'occupancy'
                                        ? [0, 100]
                                        : [0, 'auto']
                                }
                                tickLine={false}
                                axisLine={false}
                                width={48}
                                tickFormatter={format}
                            />
                            <ChartTooltip
                                cursor={false}
                                content={
                                    <ChartTooltipContent
                                        indicator="line"
                                        labelFormatter={(_, payload) =>
                                            payload[0]
                                                ? label(payload[0].payload.date)
                                                : ''
                                        }
                                        formatter={(value, name) =>
                                            `${config[name as keyof typeof config].label} ${format(Number(value))}`
                                        }
                                    />
                                }
                            />
                            <Line
                                dataKey="previous"
                                type="monotone"
                                stroke="var(--color-previous)"
                                strokeWidth={2}
                                strokeDasharray="4 4"
                                dot={false}
                            />
                            <Line
                                dataKey="current"
                                type="monotone"
                                stroke="var(--color-current)"
                                strokeWidth={2}
                                dot={false}
                            />
                        </LineChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}
