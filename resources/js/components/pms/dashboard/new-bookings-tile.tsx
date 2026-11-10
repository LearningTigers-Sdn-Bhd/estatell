import { CalendarPlus } from 'lucide-react';
import { TileCard } from '@/components/pms/dashboard/tile-card';
import { index } from '@/routes/reservations';

/** Today's New Booking: bookings made on the business date. */
export function NewBookingsTile({
    tile,
}: {
    tile: App.Data.Dashboard.NewBookingsTileData;
}) {
    return (
        <TileCard
            title="Today's new booking"
            href={index.url()}
            icon={CalendarPlus}
            tone="primary"
        >
            <p className="font-heading text-2xl font-semibold tabular-nums">
                {tile.bookings}
            </p>
            <p className="text-sm text-muted-foreground">
                {tile.rooms} {tile.rooms === 1 ? 'room' : 'rooms'} booked
            </p>
        </TileCard>
    );
}
