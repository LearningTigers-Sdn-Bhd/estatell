type Room = App.Data.StayView.RoomRowData;
type Stay = App.Data.StayView.StayData;
type Filters = App.Data.StayView.StayViewFiltersData;

function matches(value: string | null | undefined, text: string): boolean {
    return (value ?? '').toLowerCase().includes(text);
}

/**
 * The rows and stays that pass the filters. An empty list filter means all. Search matches a room number or label, a
 * guest, a reservation reference or a group name; a stay that matches shows its rows even when the room does not.
 * Stays are kept only when one of their segments is in a visible row. Display only.
 */
export function filterStays(
    rooms: Room[],
    stays: Stay[],
    filters: Pick<
        Filters,
        'roomGroupIds' | 'roomTypeIds' | 'statuses' | 'search'
    >,
): { rooms: Room[]; stays: Stay[] } {
    const text = filters.search.trim().toLowerCase();

    const passing = rooms.filter(
        (room) =>
            (filters.roomGroupIds.length === 0 ||
                (room.roomGroupId !== null &&
                    filters.roomGroupIds.includes(room.roomGroupId))) &&
            (filters.roomTypeIds.length === 0 ||
                filters.roomTypeIds.includes(room.roomTypeId)) &&
            (filters.statuses.length === 0 ||
                filters.statuses.includes(room.housekeepingStatus)),
    );

    if (text === '') {
        const keys = new Set(passing.map((room) => room.key));

        return {
            rooms: passing,
            stays: stays.filter((stay) =>
                stay.segments.some((segment) => keys.has(segment.rowKey)),
            ),
        };
    }

    const matchingStays = stays.filter(
        (stay) =>
            matches(stay.guestLabel, text) ||
            matches(stay.reference, text) ||
            matches(stay.groupName, text),
    );
    const stayRows = new Set(
        matchingStays.flatMap((stay) =>
            stay.segments.map((segment) => segment.rowKey),
        ),
    );
    const visible = passing.filter(
        (room) =>
            matches(room.label, text) ||
            matches(room.roomNumber, text) ||
            stayRows.has(room.key),
    );
    const keys = new Set(visible.map((room) => room.key));

    return {
        rooms: visible,
        stays: stays.filter((stay) =>
            stay.segments.some((segment) => keys.has(segment.rowKey)),
        ),
    };
}
