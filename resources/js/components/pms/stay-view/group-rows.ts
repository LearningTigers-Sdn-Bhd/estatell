type Room = App.Data.StayView.RoomRowData;

export type TimelineRow =
    | {
          key: string;
          kind: 'group';
          /** The key to pass to `toggleCollapsed`. */
          groupKey: string;
          title: string;
          count: number;
          collapsed: boolean;
      }
    | { key: string; kind: 'row'; room: Room };

function groupOf(
    room: Room,
    grouping: App.Enums.StayViewGrouping,
): { key: string; title: string } {
    if (grouping === 'room_type') {
        return { key: `type-${room.roomTypeId}`, title: room.roomTypeName };
    }

    return {
        key: `group-${room.roomGroupId ?? 'none'}`,
        title: room.roomGroupName ?? 'No room group',
    };
}

/**
 * The grid rows: a heading for each group, then its rooms, in the order the rooms arrive. With no grouping the rooms
 * are one list by room number, and the beds of a room stay together. A collapsed group keeps its heading only.
 */
export function buildRows(
    rooms: Room[],
    grouping: App.Enums.StayViewGrouping,
    collapsed: ReadonlySet<string> = new Set(),
): TimelineRow[] {
    if (grouping === 'none') {
        return rooms
            .map((room, index) => ({ room, index }))
            .sort(
                (a, b) =>
                    a.room.roomNumber.localeCompare(
                        b.room.roomNumber,
                        undefined,
                        { numeric: true },
                    ) || a.index - b.index,
            )
            .map(({ room }) => ({ key: room.key, kind: 'row', room }));
    }

    const groups = new Map<string, { title: string; rooms: Room[] }>();

    for (const room of rooms) {
        const { key, title } = groupOf(room, grouping);
        const group = groups.get(key) ?? { title, rooms: [] };

        group.rooms.push(room);
        groups.set(key, group);
    }

    return [...groups].flatMap(([groupKey, group]): TimelineRow[] => {
        const isCollapsed = collapsed.has(groupKey);

        return [
            {
                key: groupKey,
                kind: 'group',
                groupKey,
                title: group.title,
                count: group.rooms.length,
                collapsed: isCollapsed,
            },
            ...(isCollapsed
                ? []
                : group.rooms.map((room): TimelineRow => ({
                      key: room.key,
                      kind: 'row',
                      room,
                  }))),
        ];
    });
}

export function toggleCollapsed(
    collapsed: ReadonlySet<string>,
    groupKey: string,
): Set<string> {
    const next = new Set(collapsed);

    if (!next.delete(groupKey)) {
        next.add(groupKey);
    }

    return next;
}
