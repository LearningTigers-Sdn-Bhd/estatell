import { isUnassignedRow } from './unassigned-lane';

type Room = App.Data.StayView.RoomRowData;
type Stay = App.Data.StayView.StayData;
type Filters = App.Data.StayView.StayViewFiltersData;

function matches(value: string | null | undefined, text: string): boolean {
    return (value ?? '').toLowerCase().includes(text);
}

/**
 * The rows and stays that pass the filters. An empty list filter means all. Search matches a room number or label, a
 * guest, a reservation reference or a group name; a stay that matches shows its rows even when the room does not.
 * Stays are kept only when one of their segments is in a visible row, or in the unassigned lane of a room type that
 * passes the Room type filter: the room group and status filters are about physical rooms, so they never hide a
 * stay with no room yet. Display only.
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
    const typePasses = (stay: Stay) =>
        filters.roomTypeIds.length === 0 ||
        filters.roomTypeIds.includes(stay.roomTypeId);
    const hasUnassigned = (stay: Stay) =>
        typePasses(stay) &&
        stay.segments.some((segment) => isUnassignedRow(segment.rowKey));

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
            stays: stays.filter(
                (stay) =>
                    stay.segments.some((segment) => keys.has(segment.rowKey)) ||
                    hasUnassigned(stay),
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
        stays: stays.filter(
            (stay) =>
                stay.segments.some((segment) => keys.has(segment.rowKey)) ||
                (matchingStays.includes(stay) && hasUnassigned(stay)),
        ),
    };
}
