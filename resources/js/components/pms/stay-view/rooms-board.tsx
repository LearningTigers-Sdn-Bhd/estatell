import type { CellAction } from './bookable-cell';
import { buildRows } from './group-rows';
import { RoomCard } from './room-card';
import type { RoomDay } from './room-day';

type Badge = App.Data.Shared.BadgeData;

/**
 * The cards of the Room View under a heading for each group. The groups come from Group by through `buildRows()` and
 * the cards inside a group follow room number, with the beds of a room together. Group by only sets the headings.
 */
export function RoomsBoard({
    days,
    grouping,
    badges,
    actionsFor,
    onOpenStay,
    onBook,
    onBlock,
}: {
    days: RoomDay[];
    grouping: App.Enums.StayViewGrouping;
    badges: Record<string, Badge>;
    /** The actions a card offers. */
    actionsFor: (day: RoomDay) => CellAction[];
    onOpenStay: (unitId: number) => void;
    onBook: (room: App.Data.StayView.RoomRowData) => void;
    onBlock: (room: App.Data.StayView.RoomRowData) => void;
}) {
    const ordered = days
        .map((day, index) => ({ day, index }))
        .sort(
            (a, b) =>
                a.day.room.roomNumber.localeCompare(
                    b.day.room.roomNumber,
                    undefined,
                    { numeric: true },
                ) || a.index - b.index,
        )
        .map(({ day }) => day);
    const dayOf = new Map(ordered.map((day) => [day.room.key, day]));
    const sections: { key: string; title: string | null; days: RoomDay[] }[] =
        [];

    for (const row of buildRows(
        ordered.map((day) => day.room),
        grouping,
    )) {
        if (row.kind === 'group') {
            sections.push({ key: row.key, title: row.title, days: [] });
            continue;
        }

        const day = row.room && dayOf.get(row.room.key);

        if (!day) {
            continue;
        }

        if (sections.length === 0) {
            sections.push({ key: 'all', title: null, days: [] });
        }

        sections[sections.length - 1].days.push(day);
    }

    return (
        <div className="flex flex-col gap-6">
            {sections.map((section) => (
                <section key={section.key} className="flex flex-col gap-3">
                    {section.title !== null && (
                        <h2 className="flex items-baseline gap-2 text-sm font-semibold">
                            {section.title}
                            <span className="text-xs font-normal text-muted-foreground tabular-nums">
                                {section.days.length}
                            </span>
                        </h2>
                    )}
                    <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {section.days.map((day) => (
                            <RoomCard
                                key={day.room.key}
                                day={day}
                                badges={badges}
                                actions={actionsFor(day)}
                                onOpenStay={onOpenStay}
                                onBook={onBook}
                                onBlock={onBlock}
                            />
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    );
}
