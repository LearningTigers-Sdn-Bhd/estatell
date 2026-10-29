/** Literal class strings, so Tailwind sees every category pair. */
const categoryClasses = [
    'bg-category-1 text-category-1-foreground',
    'bg-category-2 text-category-2-foreground',
    'bg-category-3 text-category-3-foreground',
    'bg-category-4 text-category-4-foreground',
    'bg-category-5 text-category-5-foreground',
    'bg-category-6 text-category-6-foreground',
    'bg-category-7 text-category-7-foreground',
    'bg-category-8 text-category-8-foreground',
    'bg-category-9 text-category-9-foreground',
    'bg-category-10 text-category-10-foreground',
    'bg-category-11 text-category-11-foreground',
    'bg-category-12 text-category-12-foreground',
] as const;

/**
 * The category colour classes of a room type, by its zero-based place among the property's active room types (by
 * position). The 13th room type starts again at `category-1`. Not stored: reordering room types changes colours.
 */
export function roomTypeColorClass(index: number): string {
    return categoryClasses[index % categoryClasses.length];
}

/**
 * The short label of a room type: the first letter of each word, upper case, with `+` kept and the word "Room"
 * dropped. Words split on spaces and hyphens. "Standard Queen Room" gives SQ, "6-Bed Mixed Dorm" gives 6BMD.
 */
export function roomTypeShortLabel(name: string): string {
    const label = name
        .split(/[\s-]+/)
        .filter((word) => word !== '' && word.toLowerCase() !== 'room')
        .map((word) => word.match(/[\p{L}\p{N}+]/u)?.[0] ?? '')
        .join('')
        .toUpperCase();

    return label === '' ? name.trim().charAt(0).toUpperCase() : label;
}
