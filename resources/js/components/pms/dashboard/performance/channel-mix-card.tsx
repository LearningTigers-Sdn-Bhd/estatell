import { ChartPie, ChevronRight, Globe, Shapes } from 'lucide-react';
import { useState } from 'react';
import { Cell, Label, Pie, PieChart } from 'recharts';
import { BookingSourceBadge } from '@/components/pms/booking-source-badge';
import { EmptyState } from '@/components/pms/empty-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import {
    Popover,
    PopoverContent,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { MoneyText } from '@/components/pms/money-text';
import { cn } from 'cn';

type Row = App.Data.Dashboard.ChannelMixRowData;
type View = 'source' | 'type';

/** The four types use fixed chart tokens. A literal class for each, so Tailwind sees them. */
const typeClasses: Record<App.Enums.BookingSourceType, string> = {
    direct: 'bg-chart-2',
    ota: 'bg-chart-3',
    company: 'bg-chart-4',
    walk_in: 'bg-chart-1',
};

/** The same colors as SVG fills: the source's badge swatch, or the type's chart token. */
const typeColors: Record<App.Enums.BookingSourceType, string> = {
    direct: 'var(--chart-2)',
    ota: 'var(--chart-3)',
    company: 'var(--chart-4)',
    walk_in: 'var(--chart-1)',
};

const sliceColor = (row: Row) =>
    row.badge === null
        ? typeColors[row.type]
        : `var(--swatch-${row.badge.color})`;

const config = { nights: { label: 'Room nights' } } satisfies ChartConfig;

const nightsLabel = (nights: number) =>
    `${nights} ${nights === 1 ? 'night' : 'nights'}`;

function CompanyPopover({ row }: { row: Row }) {
    return (
        <Popover>
            <PopoverTrigger
                render={
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`Companies in ${row.name}`}
                    />
                }
            >
                <ChevronRight aria-hidden />
            </PopoverTrigger>
            <PopoverContent align="end">
                <PopoverHeader>
                    <PopoverTitle>{row.name}</PopoverTitle>
                </PopoverHeader>
                <ul className="flex flex-col gap-1">
                    {row.companies.map((company) => (
                        <li
                            key={company.name}
                            className="flex items-center justify-between gap-2"
                        >
                            <span className="truncate">{company.name}</span>
                            <span className="text-muted-foreground tabular-nums">
                                {company.nights} n
                            </span>
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}

/**
 * Share of room nights by booking source or by source type, as a donut with the total in the middle and the list
 * below. Hovering a slice or a row highlights both. Both views come with the page, so the switch makes no
 * request. Colors come from each source's badge swatch; color never carries meaning alone, each row names its source.
 */
export function ChannelMixCard({
    mix,
    currency,
}: {
    mix: App.Data.Dashboard.PerformanceChannelMixData;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [view, setView] = useState<View>('source');
    const rows = view === 'source' ? mix.bySource : mix.byType;
    const [hovered, setHovered] = useState<string | null>(null);
    /** The chevron slot is kept only when a row of this view opens its companies, so the figures line up. */
    const hasCompanies = rows.some((row) => row.companies.length > 0);

    return (
        <Card className="min-w-0 flex-1">
            <CardHeader className="flex flex-wrap items-center justify-between gap-2">
                <CardTitle className="flex items-center gap-2">
                    <ChartPie
                        aria-hidden
                        className="size-5 text-muted-foreground"
                    />
                    Channel mix
                </CardTitle>
                <ToggleGroup
                    variant="outline"
                    size="sm"
                    spacing={0}
                    value={[view]}
                    onValueChange={(value) =>
                        value[0] && setView(value[0] as View)
                    }
                    aria-label="Channel mix view"
                >
                    <ToggleGroupItem value="source">
                        <Globe data-icon="inline-start" />
                        Source
                    </ToggleGroupItem>
                    <ToggleGroupItem value="type">
                        <Shapes data-icon="inline-start" />
                        Type
                    </ToggleGroupItem>
                </ToggleGroup>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-4">
                {rows.length === 0 ? (
                    <div className="flex flex-1 items-center justify-center">
                        <EmptyState
                            icon={ChartPie}
                            title="No stays in this period"
                            description="The mix shows once guests stay nights in the range."
                        />
                    </div>
                ) : (
                    <>
                        <ChartContainer
                            config={config}
                            className="mx-auto aspect-square w-full max-w-52"
                            role="img"
                            aria-label="Room nights by channel"
                        >
                            <PieChart>
                                <ChartTooltip
                                    cursor={false}
                                    content={
                                        <ChartTooltipContent
                                            hideLabel
                                            formatter={(value, name, item) => (
                                                <span className="flex w-full items-center justify-between gap-4">
                                                    <span className="flex items-center gap-1.5 text-muted-foreground">
                                                        <span
                                                            aria-hidden
                                                            className="size-2.5 shrink-0 rounded-sm"
                                                            style={{
                                                                background:
                                                                    item.payload
                                                                        .fill,
                                                            }}
                                                        />
                                                        {name}
                                                    </span>
                                                    <span className="font-mono font-medium text-foreground tabular-nums">
                                                        {nightsLabel(
                                                            Number(value),
                                                        )}
                                                    </span>
                                                </span>
                                            )}
                                        />
                                    }
                                />
                                <Pie
                                    data={rows.map((row) => ({
                                        ...row,
                                        fill: sliceColor(row),
                                    }))}
                                    dataKey="nights"
                                    nameKey="name"
                                    innerRadius="62%"
                                    outerRadius="100%"
                                    stroke="var(--card)"
                                    strokeWidth={2}
                                    onMouseEnter={(_, index) =>
                                        setHovered(rows[index]?.key ?? null)
                                    }
                                    onMouseLeave={() => setHovered(null)}
                                >
                                    {rows.map((row) => (
                                        <Cell
                                            key={row.key}
                                            fill={sliceColor(row)}
                                            fillOpacity={
                                                hovered === null ||
                                                hovered === row.key
                                                    ? 1
                                                    : 0.35
                                            }
                                        />
                                    ))}
                                    <Label
                                        content={({ viewBox }) =>
                                            viewBox && 'cx' in viewBox ? (
                                                <text
                                                    x={viewBox.cx}
                                                    y={viewBox.cy}
                                                    textAnchor="middle"
                                                    dominantBaseline="middle"
                                                >
                                                    <tspan
                                                        x={viewBox.cx}
                                                        y={viewBox.cy}
                                                        className="fill-foreground font-heading text-2xl font-semibold tabular-nums"
                                                    >
                                                        {mix.nights}
                                                    </tspan>
                                                    <tspan
                                                        x={viewBox.cx}
                                                        y={
                                                            (viewBox.cy ?? 0) +
                                                            20
                                                        }
                                                        className="fill-muted-foreground text-xs"
                                                    >
                                                        {mix.nights === 1
                                                            ? 'night'
                                                            : 'nights'}
                                                    </tspan>
                                                </text>
                                            ) : null
                                        }
                                    />
                                </Pie>
                            </PieChart>
                        </ChartContainer>
                        <ul className="flex flex-col gap-1">
                            {rows.map((row) => (
                                <li
                                    key={row.key}
                                    className={cn(
                                        'flex items-center gap-2 rounded-md px-1 text-sm transition-colors',
                                        hovered === row.key && 'bg-muted',
                                    )}
                                    onMouseEnter={() => setHovered(row.key)}
                                    onMouseLeave={() => setHovered(null)}
                                >
                                    {row.badge ? (
                                        <BookingSourceBadge
                                            source={row.badge}
                                            size="sm"
                                        />
                                    ) : (
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'size-3 shrink-0 rounded-sm',
                                                typeClasses[row.type],
                                            )}
                                        />
                                    )}
                                    <span className="min-w-0 flex-1 truncate">
                                        {row.name}
                                    </span>
                                    <Tooltip>
                                        <TooltipTrigger
                                            render={<span tabIndex={0} />}
                                            className="flex shrink-0 items-center gap-3 rounded-sm tabular-nums outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            <span className="w-20 text-right text-muted-foreground">
                                                {nightsLabel(row.nights)}
                                            </span>
                                            <span className="w-10 text-right">
                                                {row.share}%
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            {row.nights} room nights, room total{' '}
                                            <MoneyText
                                                amount={row.amount}
                                                currency={currency}
                                            />
                                        </TooltipContent>
                                    </Tooltip>
                                    {row.companies.length > 0 ? (
                                        <CompanyPopover row={row} />
                                    ) : (
                                        hasCompanies && (
                                            <span className="size-7 shrink-0" />
                                        )
                                    )}
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
