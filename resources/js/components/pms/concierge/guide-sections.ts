import {
    CircleHelp,
    Hotel,
    LayoutDashboard,
    MapPin,
    Phone,
    ScrollText,
    Sparkles,
    Wifi,
    type LucideIcon,
} from 'lucide-react';

export type Sections = {
    hasAddress: boolean;
    hasContact: boolean;
    amenities: number;
    nearbyPlaces: number;
};

type Tab = { key: string; label: string; icon: LucideIcon };

/** The sections of the guest guide, in the order staff see them. */
export const GUIDE_SECTIONS = [
    { key: 'hotel-info', label: 'Hotel info', icon: Hotel },
    { key: 'policies', label: 'Policies', icon: ScrollText },
    { key: 'faqs', label: 'FAQs', icon: CircleHelp },
    { key: 'amenities', label: 'Amenities', icon: Sparkles },
    { key: 'nearby', label: 'Nearby places', icon: MapPin },
    { key: 'wifi', label: 'Wi-Fi', icon: Wifi },
    { key: 'contact', label: 'Contact', icon: Phone },
] as const satisfies readonly Tab[];

export type GuideSectionKey = (typeof GUIDE_SECTIONS)[number]['key'];

/** Every tab of the settings page: the overview and each guide section. */
export const CONCIERGE_TABS: readonly Tab[] = [
    { key: 'overview', label: 'Overview', icon: LayoutDashboard },
    ...GUIDE_SECTIONS,
];

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
    faqCount,
    wifiCount,
}: {
    sections: Sections;
    houseRules: string | null;
    faqCount: number;
    wifiCount: number;
}): GuideSectionStatus[] {
    const state: Record<GuideSectionKey, { ready: boolean; detail: string }> = {
        'hotel-info': {
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
            ready: faqCount > 0,
            detail: sectionSummary(faqCount, 'question', 'questions'),
        },
        amenities: {
            ready: sections.amenities > 0,
            detail: sectionSummary(
                sections.amenities,
                'facility',
                'facilities',
            ),
        },
        nearby: {
            ready: sections.nearbyPlaces > 0,
            detail: sectionSummary(sections.nearbyPlaces, 'place', 'places'),
        },
        wifi: {
            ready: wifiCount > 0,
            detail: sectionSummary(wifiCount, 'network', 'networks'),
        },
        contact: {
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
