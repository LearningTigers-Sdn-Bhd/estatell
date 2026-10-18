import { ChartBarStacked, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import {
    BookingSourceBadge,
    swatchClasses,
} from '@/components/pms/booking-source-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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

const barClass = (row: Row) =>
    row.badge === null
        ? typeClasses[row.type]
        : swatchClasses[row.badge.color].split(' ')[0];

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
 * Share of room nights by booking source or by source type. Both views come with the page, so the switch makes no
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

    return (
        <Card className="min-w-0 flex-1">
            <CardHeader className="flex flex-wrap items-center justify-between gap-2">
                <CardTitle className="flex items-center gap-2">
                    <ChartBarStacked
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
                    <ToggleGroupItem value="source">Source</ToggleGroupItem>
                    <ToggleGroupItem value="type">Type</ToggleGroupItem>
                </ToggleGroup>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {rows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No stays in this period
                    </p>
                ) : (
                    <>
                        <div
                            className="flex h-3 w-full overflow-hidden rounded-full bg-muted"
                            role="presentation"
                        >
                            {rows.map((row) => (
                                <div
                                    key={row.key}
                                    className={barClass(row)}
                                    style={{ width: `${row.share}%` }}
                                />
                            ))}
                        </div>
                        <ul className="flex flex-col gap-1">
                            {rows.map((row) => (
                                <li
                                    key={row.key}
                                    className="flex items-center gap-2 text-sm"
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
                                            <span>{row.share}%</span>
                                            <span className="w-14 text-right text-muted-foreground">
                                                {row.nights} n
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
                                        <span className="size-7 shrink-0" />
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
