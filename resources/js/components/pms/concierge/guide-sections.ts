import {
    BookOpenText,
    CircleHelp,
    MapPin,
    Navigation,
    Phone,
    ScrollText,
    Sparkles,
    Wifi,
    type LucideIcon,
} from 'lucide-react';

export type Sections = {
    hasArrivalInstructions: boolean;
    hasAddress: boolean;
    hasContact: boolean;
    amenities: number;
    nearbyPlaces: number;
    faqs: number;
    wifiNetworks: number;
};

type Tab = { key: string; label: string; icon: LucideIcon };

/** The sections of the guest guide, in the order staff see them. */
export const GUIDE_SECTIONS = [
    { key: 'guest-guidelines', label: 'Guest guidelines', icon: BookOpenText },
    { key: 'getting-here', label: 'Getting here', icon: Navigation },
    { key: 'policies', label: 'Policies', icon: ScrollText },
    { key: 'faqs', label: 'FAQs', icon: CircleHelp },
    { key: 'amenities', label: 'Amenities', icon: Sparkles },
    { key: 'nearby-places', label: 'Nearby places', icon: MapPin },
    { key: 'wifi', label: 'Wi-Fi', icon: Wifi },
    { key: 'contacts', label: 'Contact', icon: Phone },
] as const satisfies readonly Tab[];

export type GuideSectionKey = (typeof GUIDE_SECTIONS)[number]['key'];

export type GuideSectionStatus = Tab & {
    key: GuideSectionKey;
    ready: boolean;
    detail: string;
};

/** "3 questions", "1 question". */
export function countLabel(n: number, one: string, many: string): string {
    return `${n} ${n === 1 ? one : many}`;
}

/** "No places yet." or "3 places shown to guests." */
export function sectionSummary(n: number, one: string, many: string): string {
    return n === 0
        ? `No ${many} yet.`
        : `${countLabel(n, one, many)} shown to guests.`;
}

/** Whether each guide section has content yet, with a one-line description of where it stands. */
export function guideReadiness({
    sections,
    houseRules,
}: {
    sections: Sections;
    houseRules: string | null;
}): GuideSectionStatus[] {
    const state: Record<GuideSectionKey, { ready: boolean; detail: string }> = {
        'guest-guidelines': {
            ready: sections.hasArrivalInstructions,
            detail: sections.hasArrivalInstructions
                ? 'Arrival instructions are written.'
                : 'Add the arrival instructions.',
        },
        'getting-here': {
            ready: sections.hasAddress,
            detail: sections.hasAddress
                ? 'The property address is set.'
                : 'Add the property address.',
        },
        policies: {
            ready: !!houseRules,
            detail: houseRules
                ? 'House rules are written.'
                : 'No house rules yet.',
        },
        faqs: {
            ready: sections.faqs > 0,
            detail: sectionSummary(sections.faqs, 'question', 'questions'),
        },
        amenities: {
            ready: sections.amenities > 0,
            detail: sectionSummary(
                sections.amenities,
                'facility',
                'facilities',
            ),
        },
        'nearby-places': {
            ready: sections.nearbyPlaces > 0,
            detail: sectionSummary(sections.nearbyPlaces, 'place', 'places'),
        },
        wifi: {
            ready: sections.wifiNetworks > 0,
            detail: sectionSummary(
                sections.wifiNetworks,
                'network',
                'networks',
            ),
        },
        contacts: {
            ready: sections.hasContact,
            detail: sections.hasContact
                ? 'A phone number or email is set.'
                : 'Add a phone number or email.',
        },
    };

    return GUIDE_SECTIONS.map((section) => ({
        ...section,
        ...state[section.key],
    }));
}
