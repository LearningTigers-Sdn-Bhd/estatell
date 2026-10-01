export const ROOM_TYPE_PILL_LIMIT = 3;

/**
 * Splits room type names into the pills shown and the names folded into the "+X" pill.
 */
export function splitRoomTypes(
    names: string[],
    limit = ROOM_TYPE_PILL_LIMIT,
): { shown: string[]; hidden: string[] } {
    return { shown: names.slice(0, limit), hidden: names.slice(limit) };
}
