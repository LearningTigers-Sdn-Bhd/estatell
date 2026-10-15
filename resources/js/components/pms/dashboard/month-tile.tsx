import { MoneyText } from '@/components/pms/money-text';
import { CalendarDays } from 'lucide-react';
import { TileCard } from '@/components/pms/dashboard/tile-card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { revenue } from '@/routes/reports';

const shortDay = (date: string) => {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
};

/**
 * The month tile. Posted and Booked count different things, so each says which in a tooltip: Posted is revenue
 * posted to folios by posting date, Booked is room nights by stay date, past and future.
 */
export function MonthTile({
    tile,
}: {
    tile: App.Data.Dashboard.MonthTileData;
}) {
    return (
        <TileCard
            title={tile.month}
            icon={CalendarDays}
            href={revenue.url(undefined, {
                query: { from: tile.from, to: tile.to },
            })}
        >
            <Tooltip>
                <TooltipTrigger
                    render={<p tabIndex={0} />}
                    className="relative w-fit rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <span className="font-heading text-2xl font-semibold">
                        <MoneyText
                            amount={tile.posted}
                            currency={tile.currency}
                        />
                    </span>{' '}
                    <span className="text-sm">posted</span>
                </TooltipTrigger>
                <TooltipContent>
                    Posted to folios from {shortDay(tile.from)} to today.
                </TooltipContent>
            </Tooltip>
            <Tooltip>
                <TooltipTrigger
                    render={<p tabIndex={0} />}
                    className="relative w-fit rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <span className="font-heading text-2xl font-semibold tabular-nums">
                        {tile.bookedNights}
                    </span>{' '}
                    <span className="text-sm">nights booked</span>
                </TooltipTrigger>
                <TooltipContent>
                    Room nights staying in {tile.month}, booked so far:{' '}
                    <MoneyText
                        amount={tile.bookedAmount}
                        currency={tile.currency}
                    />
                    .
                </TooltipContent>
            </Tooltip>
        </TileCard>
    );
}
