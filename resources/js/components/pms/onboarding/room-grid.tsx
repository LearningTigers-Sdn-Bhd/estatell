import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type RoomGridRoom = {
    number: string;
    /** Room type name, short label, colour classes and the bed text, or null when the room has no type yet. */
    typeName: string | null;
    shortLabel: string;
    colorClass: string;
    bedText: string | null;
    repeated: boolean;
};

export type RoomGridSection = {
    key: string;
    title: string;
    rooms: RoomGridRoom[];
};

/**
 * The rooms of each group row, in the order typed, as buttons filled with their room type's category colour. The
 * page decides what a click does; `marked` is the first room of a Mark a run.
 */
export function RoomGrid({
    sections,
    marked,
    disabled,
    onRoomClick,
}: {
    sections: RoomGridSection[];
    marked: { section: string; number: string } | null;
    disabled?: boolean;
    onRoomClick: (sectionKey: string, index: number, shiftKey: boolean) => void;
}) {
    return (
        <div className="flex flex-col gap-6">
            {sections.map((section) => (
                <section
                    key={section.key}
                    className="flex flex-col gap-2"
                    aria-label={section.title}
                >
                    <div className="flex items-baseline justify-between gap-2">
                        <h4 className="text-sm font-medium">{section.title}</h4>
                        <span className="text-sm text-muted-foreground">
                            {section.rooms.length === 1
                                ? '1 room'
                                : `${section.rooms.length} rooms`}
                        </span>
                    </div>
                    <div className="grid grid-cols-5 gap-2 sm:grid-cols-8 lg:grid-cols-11">
                        {section.rooms.map((room, index) => {
                            const name = [
                                `Room ${room.number}`,
                                room.repeated ? 'listed twice' : room.typeName,
                                room.repeated ? null : room.bedText,
                            ]
                                .filter(Boolean)
                                .join(', ');
                            const isMarked =
                                marked?.section === section.key &&
                                marked.number === room.number;

                            return (
                                <Tooltip key={room.number}>
                                    <TooltipTrigger
                                        render={
                                            <button
                                                type="button"
                                                aria-label={name}
                                                aria-pressed={isMarked}
                                                disabled={
                                                    disabled || room.repeated
                                                }
                                                onClick={(event) =>
                                                    onRoomClick(
                                                        section.key,
                                                        index,
                                                        event.shiftKey,
                                                    )
                                                }
                                                className={cn(
                                                    'flex min-h-14 flex-col items-center justify-center rounded-md px-1 py-1.5 transition-transform outline-none hover:ring-2 hover:ring-current focus-visible:ring-3 focus-visible:ring-ring/50 active:scale-95 disabled:cursor-not-allowed motion-reduce:transition-none motion-reduce:active:scale-100',
                                                    room.repeated
                                                        ? 'bg-destructive/10 text-destructive'
                                                        : room.colorClass,
                                                    isMarked &&
                                                        'outline-2 outline-offset-2 outline-current outline-dashed',
                                                )}
                                            />
                                        }
                                    >
                                        <span className="text-base font-semibold">
                                            {room.number}
                                        </span>
                                        <span className="text-xs">
                                            {room.repeated
                                                ? 'twice'
                                                : room.shortLabel}
                                        </span>
                                        {!room.repeated && room.bedText && (
                                            <span className="text-xs">
                                                {room.bedText}
                                            </span>
                                        )}
                                    </TooltipTrigger>
                                    <TooltipContent>{name}</TooltipContent>
                                </Tooltip>
                            );
                        })}
                    </div>
                </section>
            ))}
        </div>
    );
}
