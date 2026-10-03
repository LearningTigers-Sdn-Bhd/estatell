export type BookingTab = 'rooms' | 'discounts' | 'add-ons' | 'notes';

type Errors = Record<string, string | undefined>;

const ORDER: BookingTab[] = ['rooms', 'discounts', 'add-ons', 'notes'];

/**
 * Which tab each error belongs to. A discount or add-on error is for its own tab, a note error for Notes, and every
 * other error on a line or a guest is for Rooms & guests. Errors outside the tabs (dates, source, `form`) are not
 * counted.
 */
export function errorTabs(errors: Errors): Record<BookingTab, boolean> {
    const tabs: Record<BookingTab, boolean> = {
        rooms: false,
        discounts: false,
        'add-ons': false,
        notes: false,
    };

    for (const key of Object.keys(errors)) {
        if (/^lines\.\d+\.discount_id/.test(key)) {
            tabs.discounts = true;
        } else if (/^lines\.\d+\.add_ons/.test(key)) {
            tabs['add-ons'] = true;
        } else if (key === 'notes') {
            tabs.notes = true;
        } else if (key.startsWith('lines.') || key.startsWith('guests.')) {
            tabs.rooms = true;
        }
    }

    return tabs;
}

/** The first tab, left to right, that has an error, or null when none has. */
export function firstErrorTab(
    tabs: Record<BookingTab, boolean>,
): BookingTab | null {
    return ORDER.find((tab) => tabs[tab]) ?? null;
}

/** The class of a tab trigger: red while the tab has an error. */
export function tabClass(hasError: boolean): string | undefined {
    return hasError ? 'text-destructive' : undefined;
}
