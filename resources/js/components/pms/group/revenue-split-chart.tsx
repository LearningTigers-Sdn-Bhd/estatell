import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

const config = {
    room: { label: 'Rooms', color: 'var(--chart-2)' },
    extra: { label: 'Extras', color: 'var(--chart-5)' },
} satisfies ChartConfig;

/**
 * This month's revenue (actual and on the books) by property, rooms and extras stacked, so the owner sees which lodge
 * earns what and how much comes from extras. Display only.
 */
export function RevenueSplitChart({
    rows,
    currency,
}: {
    rows: { name: string; room: number; extra: number }[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const format = (value: number) =>
        new Intl.NumberFormat(undefined, {
            notation: 'compact',
            maximumFractionDigits: 1,
        }).format(value);

    return (
        <ChartContainer
            config={config}
            className="aspect-auto w-full"
            style={{ height: Math.max(rows.length * 56 + 64, 160) }}
            role="img"
            aria-label="Revenue this month by property"
        >
            <BarChart
                accessibilityLayer
                data={rows}
                layout="vertical"
                margin={{ top: 0, right: 12, left: 0, bottom: 0 }}
            >
                <CartesianGrid horizontal={false} />
                <XAxis
                    type="number"
                    tickLine={false}
                    axisLine={false}
                    tickFormatter={format}
                />
                <YAxis
                    type="category"
                    dataKey="name"
                    tickLine={false}
                    axisLine={false}
                    width={120}
                />
                <ChartTooltip
                    cursor={false}
                    content={
                        <ChartTooltipContent
                            formatter={(value, name) =>
                                `${config[name as keyof typeof config]?.label ?? name}: ${currency?.code ?? ''} ${Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                            }
                        />
                    }
                />
                <ChartLegend content={<ChartLegendContent />} />
                <Bar
                    dataKey="room"
                    stackId="revenue"
                    fill="var(--color-room)"
                    radius={[4, 0, 0, 4]}
                />
                <Bar
                    dataKey="extra"
                    stackId="revenue"
                    fill="var(--color-extra)"
                    radius={[0, 4, 4, 0]}
                />
            </BarChart>
        </ChartContainer>
    );
}
