import { router } from '@inertiajs/react';
import { newMethod } from '@/routes/reservations';
import { dayLabel, placeLabel } from './stay-places';

/**
 * The click target of an empty Timeline cell. It opens the booking form with this night as the arrival, the next day
 * as the departure and this room or bed picked. Until Block room is built the click goes straight to the form.
 */
export function EmptyCell({
    room,
    date,
}: {
    room: App.Data.StayView.RoomRowData;
    date: string;
}) {
    return (
        <button
            type="button"
            tabIndex={-1}
            aria-label={`New booking, ${room.bedId === null ? `Room ${room.roomNumber}` : placeLabel(room)}, ${dayLabel(date)}`}
            className="absolute inset-0 cursor-pointer focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            onClick={() =>
                router.visit(
                    newMethod.url(undefined, {
                        query: {
                            arrival: date,
                            ...(room.bedId === null
                                ? { room: room.roomId }
                                : { bed: room.bedId }),
                        },
                    }),
                )
            }
        />
    );
}
