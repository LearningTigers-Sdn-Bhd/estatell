import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

const config = {
    occupancy: { label: 'Occupied', color: 'var(--chart-3)' },
} satisfies ChartConfig;

const parseDay = (date: string) => {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day);
};

const weekday = (date: string) =>
    parseDay(date).toLocaleDateString('en-GB', { weekday: 'short' });

const fullDay = (date: string) =>
    parseDay(date).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'short',
    });

/**
 * Occupancy over the coming nights as an area chart: a 0 to 100% axis, light grid lines and a tooltip per night.
 * It grows with the Tonight card when the dashboard row fills the page.
 */
export function OccupancyChart({
    nights,
}: {
    nights: App.Data.Dashboard.TonightNightData[];
}) {
    return (
        <ChartContainer
            config={config}
            className="aspect-auto min-h-21 w-full flex-1"
            role="img"
            aria-label="Occupancy for the next seven nights"
        >
            <AreaChart
                accessibilityLayer
                data={nights}
                margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
            >
                <CartesianGrid vertical={false} />
                <XAxis
                    dataKey="date"
                    tickLine={false}
                    axisLine={false}
                    tickMargin={8}
                    tickFormatter={weekday}
                />
                <YAxis
                    domain={[0, 100]}
                    ticks={[0, 50, 100]}
                    tickLine={false}
                    axisLine={false}
                    width={40}
                    tickFormatter={(value: number) => `${value}%`}
                />
                <ChartTooltip
                    cursor={false}
                    content={
                        <ChartTooltipContent
                            indicator="line"
                            labelFormatter={(_, payload) =>
                                payload[0]
                                    ? fullDay(payload[0].payload.date)
                                    : ''
                            }
                            formatter={(value) => `${String(value)}% occupied`}
                        />
                    }
                />
                <Area
                    dataKey="occupancy"
                    type="monotone"
                    stroke="var(--color-occupancy)"
                    fill="var(--color-occupancy)"
                    fillOpacity={0.2}
                    strokeWidth={2}
                    dot={{ r: 3, fill: 'var(--color-occupancy)' }}
                />
            </AreaChart>
        </ChartContainer>
    );
}
