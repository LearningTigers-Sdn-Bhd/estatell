type Facility = App.Data.Facilities.FacilityTimelineRowData;
type Cell = App.Data.Facilities.FacilityDayCellData;

/** The key of the Facilities heading, for `toggleCollapsed`. */
export const FACILITIES_GROUP = 'facilities';

/** A facility row of the Timeline grid. It has no room, so the room code skips it. */
export type FacilityRow = {
    key: string;
    kind: 'row';
    room: null;
    lane?: undefined;
    facility: Facility;
};

export type FacilityGroupRow = {
    key: string;
    kind: 'group';
    groupKey: string;
    title: string;
    roomTypeId: null;
    count: number;
    collapsed: boolean;
    facility?: undefined;
};

/**
 * The Facilities section under the rooms: a heading, then the facilities in the order the server sends them. A
 * collapsed section keeps its heading only. No facilities, no section.
 */
export function buildFacilityRows(
    facilities: Facility[],
    collapsed: ReadonlySet<string>,
): (FacilityGroupRow | FacilityRow)[] {
    if (facilities.length === 0) {
        return [];
    }

    const isCollapsed = collapsed.has(FACILITIES_GROUP);
    const heading: FacilityGroupRow = {
        key: `group-${FACILITIES_GROUP}`,
        kind: 'group',
        groupKey: FACILITIES_GROUP,
        title: 'Facilities',
        roomTypeId: null,
        count: facilities.length,
        collapsed: isCollapsed,
    };

    return isCollapsed
        ? [heading]
        : [
              heading,
              ...facilities.map((facility): FacilityRow => ({
                  key: `facility-${facility.id}`,
                  kind: 'row',
                  room: null,
                  facility,
              })),
          ];
}

/** The key of a cell in the map from `cellsByKey`. */
export function facilityCellKey(facilityId: number, date: string): string {
    return `${facilityId}|${date}`;
}

export function cellsByKey(cells: Cell[]): Map<string, Cell> {
    return new Map(
        cells.map((cell) => [
            facilityCellKey(cell.facilityId, cell.date),
            cell,
        ]),
    );
}

export type FacilityCellView = {
    /** The text in the cell. Empty for a facility with 1 item and no booking. */
    text: string;
    /** The cell title, for the pointer and screen readers. */
    title: string;
    full: boolean;
    /** Every booking that day is Tentative: the cell is hatched and muted. */
    tentative: boolean;
};

/**
 * What a facility cell shows. A facility with 1 item names its booking, or "{n} booked" when it has more than one
 * that day. Any other facility shows the most booked at one time of its How many, and "Full" when it is reached.
 * Only Confirmed bookings count towards the number, like the clash check.
 */
export function facilityCellView(
    facility: Facility,
    cell: Cell | undefined,
): FacilityCellView {
    const bookings = cell?.bookings ?? 0;
    const tentative = bookings > 0 && cell?.tentative === bookings;
    const held =
        cell && cell.tentative > 0 ? ` · ${cell.tentative} tentative` : '';

    if (facility.single) {
        const text =
            bookings === 0 ? '' : (cell?.label ?? `${bookings} booked`);

        return {
            text,
            title: bookings === 0 ? 'Free' : `${text}${held}`,
            full: false,
            tentative,
        };
    }

    const booked = cell?.booked ?? 0;
    const full = cell?.full ?? false;

    return {
        text: `${booked}/${facility.capacity}${full ? ' Full' : ''}`,
        title: `${booked} of ${facility.capacity} ${facility.unit} booked at the busiest time${held}`,
        full,
        tentative,
    };
}
