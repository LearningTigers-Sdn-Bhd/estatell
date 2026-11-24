import { CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

type Forecast = App.Data.Group.GroupForecastData;

/** The palette is shades of one hue, so neighbouring properties take shades far apart. */
const COLORS = [
    'var(--chart-1)',
    'var(--chart-4)',
    'var(--chart-2)',
    'var(--chart-5)',
];

/** `2026-12` as "Dec". */
export function shortMonth(month: string): string {
    const [year, number] = month.split('-').map(Number);

    return new Date(Date.UTC(year, number - 1, 1)).toLocaleDateString('en-GB', {
        month: 'short',
        timeZone: 'UTC',
    });
}

/**
 * Occupancy on the books month by month: one line per property, and the group's last year as a dashed line, so an
 * owner sees at once which lodge is ahead or behind. Display only; the server sends the figures.
 */
export function OccupancyOutlookChart({ forecast }: { forecast: Forecast }) {
    const config: ChartConfig = {
        lastYear: {
            label: 'All properties last year',
            color: 'var(--muted-foreground)',
        },
    };

    forecast.properties.forEach((property, index) => {
        config[property.code] = {
            label: property.name,
            color: COLORS[index % COLORS.length],
        };
    });

    const data = forecast.months.map((month) => {
        const point: Record<string, string | number | null> = {
            month: shortMonth(month),
        };

        for (const row of forecast.rows) {
            if (row.month !== month) {
                continue;
            }

            if (row.code === null) {
                point.lastYear =
                    row.lastYearOccupancy === null
                        ? null
                        : Number(row.lastYearOccupancy);
            } else {
                point[row.code] = Number(row.occupancy);
            }
        }

        return point;
    });

    return (
        <ChartContainer
            config={config}
            className="aspect-auto h-64 w-full"
            role="img"
            aria-label="Occupancy on the books by month for each property"
        >
            <LineChart
                accessibilityLayer
                data={data}
                margin={{ top: 8, right: 12, left: 0, bottom: 0 }}
            >
                <CartesianGrid vertical={false} />
                <XAxis
                    dataKey="month"
                    tickLine={false}
                    axisLine={false}
                    tickMargin={8}
                />
                <YAxis
                    domain={[0, 100]}
                    ticks={[0, 25, 50, 75, 100]}
                    tickLine={false}
                    axisLine={false}
                    width={40}
                    tickFormatter={(value: number) => `${value}%`}
                />
                <ChartTooltip
                    content={
                        <ChartTooltipContent
                            indicator="line"
                            formatter={(value, name) => {
                                const label = config[String(name)]?.label;

                                return `${typeof label === 'string' ? label : String(name)}: ${Number(value).toFixed(1)}%`;
                            }}
                        />
                    }
                />
                <ChartLegend content={<ChartLegendContent />} />
                {forecast.properties.map((property) => (
                    <Line
                        key={property.code}
                        dataKey={property.code}
                        type="monotone"
                        stroke={`var(--color-${property.code})`}
                        strokeWidth={2}
                        dot={{ r: 3 }}
                    />
                ))}
                <Line
                    dataKey="lastYear"
                    type="monotone"
                    stroke="var(--color-lastYear)"
                    strokeDasharray="4 4"
                    strokeWidth={1.5}
                    dot={false}
                    connectNulls
                />
            </LineChart>
        </ChartContainer>
    );
}
