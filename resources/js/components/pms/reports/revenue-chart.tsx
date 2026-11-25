import {
    Bar,
    BarChart,
    CartesianGrid,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';
import type { BarShapeProps } from 'recharts';
import { ChartBar } from '@/components/pms/reports/chart-bar';
import {
    axisDate,
    moneyText,
    moneyTick,
    tooltipDate,
} from '@/components/pms/reports/chart-format';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

const config = {
    value: { label: 'Amount', color: 'var(--chart-3)' },
} satisfies ChartConfig;

/** One tooltip row: the name in muted text and the amount in mono. */
function AmountRow({
    name,
    amount,
    currency,
}: {
    name: string;
    amount: number;
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <span className="flex w-full items-center justify-between gap-4">
            <span className="text-muted-foreground">{name}</span>
            <span className="font-mono font-medium text-foreground tabular-nums">
                {moneyText(amount, currency)}
            </span>
        </span>
    );
}

/**
 * The two charts above Revenue by transaction code, for the whole range (not the table's page): the codes ranked by
 * amount, the highest on top, and the total of each day or month of the Group by. A negative amount (a code that is
 * mostly corrections or refunds) is a `warning` bar on the other side of zero. See docs/22-reports/06-report-charts.md.
 */
export function RevenueChart({
    chart,
    currency,
}: {
    chart: App.Data.Reports.RevenueChartData;
    currency: App.Data.Property.CurrencyData | null;
}) {
    if (chart.codes.length === 0) {
        return null;
    }

    const codes = chart.codes.map((code) => ({
        name: code.code === '' ? code.description : code.code,
        description: code.description,
        value: Number(code.amount),
    }));
    const periods = chart.periods.map((period) => ({
        date: period.period,
        value: Number(period.amount),
    }));
    const step = chart.group === 'month' ? 'month' : 'day';

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card size="sm">
                <CardHeader>
                    <CardTitle>By transaction code</CardTitle>
                </CardHeader>
                <CardContent>
                    <ChartContainer
                        config={config}
                        className="aspect-auto w-full"
                        style={{
                            height: Math.max(160, codes.length * 32 + 32),
                        }}
                        role="img"
                        aria-label="Revenue by transaction code"
                    >
                        <BarChart
                            accessibilityLayer
                            layout="vertical"
                            data={codes}
                            margin={{ top: 0, right: 8, left: 0, bottom: 0 }}
                        >
                            <CartesianGrid horizontal={false} />
                            <XAxis
                                type="number"
                                tickLine={false}
                                axisLine={false}
                                tickFormatter={(tick: number) =>
                                    moneyTick(tick, currency)
                                }
                            />
                            <YAxis
                                type="category"
                                dataKey="name"
                                tickLine={false}
                                axisLine={false}
                                width={96}
                            />
                            <ChartTooltip
                                cursor={false}
                                content={
                                    <ChartTooltipContent
                                        hideIndicator
                                        labelFormatter={(label, items) =>
                                            items[0]?.payload?.description ??
                                            label
                                        }
                                        formatter={(amount) => (
                                            <AmountRow
                                                name="Amount"
                                                amount={Number(amount)}
                                                currency={currency}
                                            />
                                        )}
                                    />
                                }
                            />
                            <Bar
                                dataKey="value"
                                fill="var(--color-value)"
                                maxBarSize={20}
                                shape={(props: BarShapeProps) => (
                                    <ChartBar {...props} horizontal />
                                )}
                                activeBar={(props: BarShapeProps) => (
                                    <ChartBar {...props} horizontal />
                                )}
                            />
                            <ReferenceLine
                                x={0}
                                stroke="var(--foreground)"
                                strokeOpacity={0.3}
                            />
                        </BarChart>
                    </ChartContainer>
                </CardContent>
            </Card>
            <Card size="sm">
                <CardHeader>
                    <CardTitle>Total by {step}</CardTitle>
                </CardHeader>
                <CardContent>
                    <ChartContainer
                        config={config}
                        className="aspect-auto h-56 w-full"
                        role="img"
                        aria-label={`Revenue by ${step}`}
                    >
                        <BarChart
                            accessibilityLayer
                            data={periods}
                            margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="date"
                                tickLine={false}
                                axisLine={false}
                                tickMargin={8}
                                minTickGap={24}
                                tickFormatter={axisDate}
                            />
                            <YAxis
                                tickLine={false}
                                axisLine={false}
                                width={72}
                                tickFormatter={(tick: number) =>
                                    moneyTick(tick, currency)
                                }
                            />
                            <ChartTooltip
                                cursor={false}
                                content={
                                    <ChartTooltipContent
                                        hideIndicator
                                        labelFormatter={(label) =>
                                            tooltipDate(String(label))
                                        }
                                        formatter={(amount) => (
                                            <AmountRow
                                                name="Revenue"
                                                amount={Number(amount)}
                                                currency={currency}
                                            />
                                        )}
                                    />
                                }
                            />
                            <Bar
                                dataKey="value"
                                fill="var(--color-value)"
                                maxBarSize={32}
                                shape={(props: BarShapeProps) => (
                                    <ChartBar {...props} />
                                )}
                                activeBar={(props: BarShapeProps) => (
                                    <ChartBar {...props} />
                                )}
                            />
                            <ReferenceLine
                                y={0}
                                stroke="var(--foreground)"
                                strokeOpacity={0.3}
                            />
                        </BarChart>
                    </ChartContainer>
                </CardContent>
            </Card>
        </div>
    );
}
