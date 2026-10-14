import { Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Popover,
    PopoverContent,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { calendar } from '@/routes/rates';

const weekday = (date: string) => {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
        weekday: 'short',
    });
};

/**
 * The Tonight tile: occupancy and free units on the business date, then seven nights as bars. Hovering or tapping the
 * figures opens the room types. The title links to the rate calendar.
 */
export function TonightTile({
    tile,
}: {
    tile: App.Data.Dashboard.TonightTileData;
}) {
    return (
        <Card className="lg:col-span-2">
            <CardHeader>
                <CardTitle>
                    <Link
                        href={calendar.url()}
                        className="rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        Tonight
                    </Link>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <Popover>
                    <PopoverTrigger
                        openOnHover
                        className="flex w-full flex-col gap-3 rounded-lg text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <span className="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                            <span>
                                <span className="font-heading text-3xl font-semibold tabular-nums">
                                    {tile.occupancy}%
                                </span>{' '}
                                <span className="text-sm">occupied</span>
                            </span>
                            <span className="text-sm tabular-nums">
                                {tile.roomsFree}{' '}
                                {tile.roomsFree === 1 ? 'room' : 'rooms'} free
                                {tile.bedsFree !== null && (
                                    <>
                                        {' · '}
                                        {tile.bedsFree}{' '}
                                        {tile.bedsFree === 1 ? 'bed' : 'beds'}{' '}
                                        free
                                    </>
                                )}
                            </span>
                        </span>
                        <span className="grid grid-cols-7 gap-2">
                            {tile.nights.map((night) => (
                                <span
                                    key={night.date}
                                    className="flex flex-col items-center gap-1"
                                >
                                    <span className="flex h-12 w-full items-end rounded-sm bg-muted">
                                        <span
                                            className="w-full rounded-sm bg-primary"
                                            style={{
                                                height: `${night.occupancy}%`,
                                            }}
                                        />
                                    </span>
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {weekday(night.date)}
                                    </span>
                                    <span className="sr-only">
                                        {night.occupancy}% occupied
                                    </span>
                                </span>
                            ))}
                        </span>
                    </PopoverTrigger>
                    <PopoverContent align="start" className="w-80">
                        <PopoverHeader>
                            <PopoverTitle>Tonight by room type</PopoverTitle>
                        </PopoverHeader>
                        {tile.roomTypes.length === 0 ? (
                            <p className="text-muted-foreground">
                                No room types yet.
                            </p>
                        ) : (
                            <table className="w-full text-sm tabular-nums">
                                <thead>
                                    <tr className="text-left text-muted-foreground">
                                        <th className="font-normal">
                                            Room type
                                        </th>
                                        <th className="text-right font-normal">
                                            Sold
                                        </th>
                                        <th className="text-right font-normal">
                                            Total
                                        </th>
                                        <th className="text-right font-normal">
                                            Left
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {tile.roomTypes.map((roomType) => (
                                        <tr key={roomType.name}>
                                            <td className="truncate">
                                                {roomType.name}
                                            </td>
                                            <td className="text-right">
                                                {roomType.sold}
                                            </td>
                                            <td className="text-right">
                                                {roomType.total}
                                            </td>
                                            <td className="text-right">
                                                {roomType.left}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </PopoverContent>
                </Popover>
            </CardContent>
        </Card>
    );
}
