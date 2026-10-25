import type { Lane } from './unassigned-lane';

type Room = App.Data.StayView.RoomRowData;

/** One sub-row of an unassigned lane. Only the first one carries the unit count. */
export type LaneRow = {
    key: string;
    kind: 'row';
    room: null;
    lane: { roomTypeId: number; roomTypeName: string; units: number | null };
};

export type TimelineRow =
    | {
          key: string;
          kind: 'group';
          /** The key to pass to `toggleCollapsed`. */
          groupKey: string;
          title: string;
          /** The room type of the group when rooms are grouped by room type. */
          roomTypeId: number | null;
          count: number;
          collapsed: boolean;
      }
    | { key: string; kind: 'row'; room: Room; lane?: undefined }
    | LaneRow;

function groupOf(
    room: Room,
    grouping: App.Enums.StayViewGrouping,
): { key: string; title: string; roomTypeId: number | null } {
    if (grouping === 'room_type') {
        return {
            key: `type-${room.roomTypeId}`,
            title: room.roomTypeName,
            roomTypeId: room.roomTypeId,
        };
    }

    return {
        key: `group-${room.roomGroupId ?? 'none'}`,
        title: room.roomGroupName ?? 'No room group',
        roomTypeId: null,
    };
}

function laneRows(lane: Lane): LaneRow[] {
    return lane.rowKeys.map((key, index) => ({
        key,
        kind: 'row',
        room: null,
        lane: {
            roomTypeId: lane.roomTypeId,
            roomTypeName: lane.roomTypeName,
            units: index === 0 ? lane.units : null,
        },
    }));
}

/** The key of the group that holds the lanes when rooms are not grouped by room type. */
export const UNASSIGNED_GROUP = 'unassigned';

const ALL_ROOMS_GROUP = 'all-rooms';

/**
 * The grid rows: a heading for each group, then its rooms, in the order the rooms arrive. With no grouping the rooms
 * are one list by room number, and the beds of a room stay together. A collapsed group keeps its heading only.
 *
 * Unassigned lanes sit at the top of their room type's group when rooms are grouped by room type. Otherwise they
 * share one Unassigned group at the top of the grid, and with no grouping the rooms get an All rooms heading under
 * it, so the sticky heading does not claim them. A room type with a lane but no room on screen still gets a group.
 */
export function buildRows(
    rooms: Room[],
    grouping: App.Enums.StayViewGrouping,
    collapsed: ReadonlySet<string> = new Set(),
    lanes: Lane[] = [],
): TimelineRow[] {
    const unassigned: TimelineRow[] =
        lanes.length === 0 || grouping === 'room_type'
            ? []
            : [
                  {
                      key: UNASSIGNED_GROUP,
                      kind: 'group',
                      groupKey: UNASSIGNED_GROUP,
                      title: 'Unassigned',
                      roomTypeId: null,
                      count: lanes.reduce((sum, lane) => sum + lane.units, 0),
                      collapsed: collapsed.has(UNASSIGNED_GROUP),
                  },
                  ...(collapsed.has(UNASSIGNED_GROUP)
                      ? []
                      : lanes.flatMap(laneRows)),
              ];

    if (grouping === 'none') {
        const flat: TimelineRow[] = rooms
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

        if (unassigned.length === 0) {
            return flat;
        }

        const roomsCollapsed = collapsed.has(ALL_ROOMS_GROUP);

        return [
            ...unassigned,
            {
                key: ALL_ROOMS_GROUP,
                kind: 'group',
                groupKey: ALL_ROOMS_GROUP,
                title: 'All rooms',
                roomTypeId: null,
                count: flat.length,
                collapsed: roomsCollapsed,
            },
            ...(roomsCollapsed ? [] : flat),
        ];
    }

    const groups = new Map<
        string,
        { title: string; roomTypeId: number | null; rooms: Room[] }
    >();

    for (const room of rooms) {
        const { key, title, roomTypeId } = groupOf(room, grouping);
        const group = groups.get(key) ?? { title, roomTypeId, rooms: [] };

        group.rooms.push(room);
        groups.set(key, group);
    }

    const laneOf = new Map<string, Lane>();

    if (grouping === 'room_type') {
        for (const lane of lanes) {
            const key = `type-${lane.roomTypeId}`;

            laneOf.set(key, lane);

            if (!groups.has(key)) {
                groups.set(key, {
                    title: lane.roomTypeName,
                    roomTypeId: lane.roomTypeId,
                    rooms: [],
                });
            }
        }
    }

    const grouped = [...groups].flatMap(([groupKey, group]): TimelineRow[] => {
        const isCollapsed = collapsed.has(groupKey);

        return [
            {
                key: groupKey,
                kind: 'group',
                groupKey,
                title: group.title,
                roomTypeId: group.roomTypeId,
                count: group.rooms.length,
                collapsed: isCollapsed,
            },
            ...(isCollapsed
                ? []
                : [
                      ...(laneOf.has(groupKey)
                          ? laneRows(laneOf.get(groupKey) as Lane)
                          : []),
                      ...group.rooms.map((room): TimelineRow => ({
                          key: room.key,
                          kind: 'row',
                          room,
                      })),
                  ]),
        ];
    });

    return [...unassigned, ...grouped];
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
