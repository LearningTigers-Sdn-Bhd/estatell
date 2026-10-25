import { router } from '@inertiajs/react';
import { Ban, Plus } from 'lucide-react';
import { useMemo } from 'react';
import { RoomBlockForm } from '@/components/pms/room-block-form';
import type { RoomBlockStart } from '@/components/pms/room-block-form';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { newMethod } from '@/routes/reservations';
import type { CellAction } from './bookable-cell';
import { dayLabel, placeLabel } from './stay-places';

type Room = App.Data.StayView.RoomRowData;

/** The room or bed and night of an empty cell that staff chose to block. */
export type BlockCell = { room: Room; date: string };

const cellClass =
    'absolute inset-0 cursor-pointer focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

function cellName(room: Room): string {
    return room.bedId === null ? `Room ${room.roomNumber}` : placeLabel(room);
}

function openBooking(room: Room, date: string) {
    router.visit(
        newMethod.url(undefined, {
            query: {
                arrival: date,
                ...(room.bedId === null
                    ? { room: room.roomId }
                    : { bed: room.bedId }),
                // The Timeline keeps its window and filters in the address, so the form can link back to this view.
                back: 'timeline',
                back_query: globalThis.location.search.replace(/^\?/, ''),
            },
        }),
    );
}

/**
 * The click target of an empty Timeline cell. New booking opens the booking form with this night as the arrival, the
 * next day as the departure and this room or bed picked, and a back link to this view. Block room hands the cell to `onBlock`. With two actions the
 * click opens a menu at the cell, with one it runs that action at once, and with none the cell takes no click.
 */
export function EmptyCell({
    room,
    date,
    actions,
    onBlock,
}: {
    room: Room;
    date: string;
    actions: CellAction[];
    onBlock: (cell: BlockCell) => void;
}) {
    const run = (action: CellAction) =>
        action === 'booking'
            ? openBooking(room, date)
            : onBlock({ room, date });

    if (actions.length === 0) {
        return null;
    }

    if (actions.length === 1) {
        return (
            <button
                type="button"
                tabIndex={-1}
                aria-label={`${actions[0] === 'booking' ? 'New booking' : 'Block room'}, ${cellName(room)}, ${dayLabel(date)}`}
                className={cellClass}
                onClick={() => run(actions[0])}
            />
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={
                    <button
                        type="button"
                        tabIndex={-1}
                        aria-label={`Actions, ${cellName(room)}, ${dayLabel(date)}`}
                        className={cellClass}
                    />
                }
            />
            <DropdownMenuContent className="w-auto min-w-44">
                <DropdownMenuGroup>
                    <DropdownMenuLabel>
                        {cellName(room)} · {dayLabel(date)}
                    </DropdownMenuLabel>
                    <DropdownMenuItem onClick={() => run('booking')}>
                        <Plus />
                        New booking
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => run('block')}>
                        <Ban />
                        Block room
                    </DropdownMenuItem>
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * The Block room Sheet of the Timeline. The room is fixed to the cell's room, and a bed row starts on its bed. The
 * room and its beds come from the loaded rows, so opening it makes no request.
 */
export function BlockRoomSheet({
    cell,
    rooms,
    reasons,
    today,
    maxNights,
    onClose,
}: {
    cell: BlockCell | null;
    rooms: Room[];
    reasons: { value: string; label: string }[];
    today: string;
    maxNights: number;
    onClose: () => void;
}) {
    const blockRoom = useMemo((): App.Data.Rooms.RoomBlockRoomData | null => {
        if (cell === null) {
            return null;
        }

        const beds = rooms.filter(
            (row) => row.roomId === cell.room.roomId && row.bedId !== null,
        );

        return {
            id: cell.room.roomId,
            number: cell.room.roomNumber,
            roomTypeName: cell.room.roomTypeName,
            roomGroupId: cell.room.roomGroupId,
            sellsBeds: cell.room.sellUnit === 'bed',
            beds: beds.map((row) => ({
                id: row.bedId as number,
                roomId: row.roomId,
                label: row.label,
                archived: false,
            })),
        };
    }, [cell, rooms]);
    const start: RoomBlockStart | undefined = cell
        ? {
              roomId: cell.room.roomId,
              bedId: cell.room.bedId,
              startsOn: cell.date,
              endsOn: cell.date,
          }
        : undefined;

    return (
        <Sheet open={cell !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="data-[side=right]:sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>Block room</SheetTitle>
                    <SheetDescription>
                        A blocked room or bed cannot be sold for these nights.
                    </SheetDescription>
                </SheetHeader>
                {cell && blockRoom && (
                    <RoomBlockForm
                        key={`${cell.room.key}|${cell.date}`}
                        block={null}
                        start={start}
                        rooms={[blockRoom]}
                        reasons={reasons}
                        today={today}
                        maxNights={maxNights}
                        onDone={onClose}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}
