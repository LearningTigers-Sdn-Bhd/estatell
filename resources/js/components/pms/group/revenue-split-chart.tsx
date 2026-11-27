import {
    Bar,
    BarChart,
    CartesianGrid,
    LabelList,
    XAxis,
    YAxis,
} from 'recharts';
import type { LabelProps } from 'recharts';
import { valueLabel } from '@/components/pms/chart-value-label';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

/** A segment's own figure, written inside it when it is wide enough to hold one. */
function segmentLabel(format: (value: number) => string) {
    return function SegmentLabel(props: LabelProps) {
        const width = Number(props.width ?? 0);
        const value = Number(props.value);

        if (width < 44 || !Number.isFinite(value) || value === 0) {
            return null;
        }

        return (
            <text
                x={Number(props.x ?? 0) + width / 2}
                y={Number(props.y ?? 0) + Number(props.height ?? 0) / 2}
                textAnchor="middle"
                dominantBaseline="central"
                fontSize={11}
                className="fill-background tabular-nums"
            >
                {format(value)}
            </text>
        );
    };
}

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
                margin={{ top: 0, right: 56, left: 0, bottom: 0 }}
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
                >
                    <LabelList dataKey="room" content={segmentLabel(format)} />
                </Bar>
                <Bar
                    dataKey="extra"
                    stackId="revenue"
                    fill="var(--color-extra)"
                    radius={[0, 4, 4, 0]}
                >
                    <LabelList dataKey="extra" content={segmentLabel(format)} />
                    {/* The property's total at the end of its bar. */}
                    <LabelList
                        valueAccessor={(entry) => {
                            const row = entry.payload as {
                                room: number;
                                extra: number;
                            };

                            return row.room + row.extra;
                        }}
                        content={valueLabel(format, {
                            bars: rows.length,
                            horizontal: true,
                        })}
                    />
                </Bar>
            </BarChart>
        </ChartContainer>
    );
}
