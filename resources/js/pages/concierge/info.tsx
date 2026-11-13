import {
    BookOpen,
    Building2,
    CircleHelp,
    ShieldCheck,
    Sparkles,
    Wifi,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { AmenityGroups } from '@/components/concierge/amenity-groups';
import {
    PropertyFaqs,
    PropertyOverview,
    PropertyPolicies,
    PropertyWifi,
} from '@/components/concierge/property-guide';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { TabLinks } from '@/components/pms/tab-links';

type Section = { key: string; label: string; url: string };
const sectionIcons: Record<string, LucideIcon> = {
    property: Building2,
    amenities: Sparkles,
    policies: ShieldCheck,
    faqs: CircleHelp,
    wifi: Wifi,
};

const sectionDescriptions: Record<string, string> = {
    property:
        'Arrival details, facilities and useful information for your stay.',
    amenities: 'Facilities and services available at the property.',
    policies: 'Arrival times, house rules and policies for your stay.',
    faqs: 'Answers to common questions about the property.',
    wifi: 'Network details for your stay.',
};

/** Each guide chapter has its own URL; only that chapter's guest-safe data is sent. */
export default function ConciergeInfo({
    section,
    sections,
    back,
    details,
    policies,
    amenities,
    faqs,
    wifi,
}: {
    section: string;
    sections: Section[];
    back: { href: string; label: string };
    details: App.Data.Concierge.ConciergePropertyData | null;
    policies: App.Data.Concierge.ConciergePoliciesData | null;
    amenities: App.Data.Concierge.ConciergeAmenityData[];
    faqs: App.Data.Concierge.ConciergeFaqData[];
    wifi: App.Data.Concierge.ConciergeWifiData[];
}) {
    return (
        <GuestFrame
            back={back}
            title={
                section === 'property'
                    ? 'Property guide'
                    : (sections.find((item) => item.key === section)?.label ??
                      'Property guide')
            }
            description={
                sectionDescriptions[section] ?? sectionDescriptions.property
            }
        >
            <TabLinks
                className="concierge-guide-tabs"
                activeTab={section}
                tabs={sections.map((item) => ({
                    value: item.key,
                    label: item.key === 'property' ? 'Overview' : item.label,
                    href: item.url,
                    icon: sectionIcons[item.key] ?? BookOpen,
                }))}
                triggerClassName="min-h-11 px-2 md:px-4"
                panelWidth="full"
                panelClassName="pt-6"
            >
                {section === 'property' && details && (
                    <PropertyOverview details={details} />
                )}
                {section === 'policies' && policies && (
                    <PropertyPolicies policies={policies} />
                )}
                {section === 'amenities' && (
                    <AmenityGroups amenities={amenities} />
                )}
                {section === 'faqs' && <PropertyFaqs faqs={faqs} />}
                {section === 'wifi' && <PropertyWifi wifi={wifi} />}
            </TabLinks>
        </GuestFrame>
    );
}
