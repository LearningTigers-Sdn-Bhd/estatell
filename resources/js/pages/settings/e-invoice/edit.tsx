import { Head } from '@inertiajs/react';
import { Building2, Plug, SlidersHorizontal } from 'lucide-react';
import { EInvoiceBusinessForm } from '@/components/pms/einvoice/einvoice-business-form';
import { EInvoiceConnectionForm } from '@/components/pms/einvoice/einvoice-connection-form';
import { EInvoiceGeneralForm } from '@/components/pms/einvoice/einvoice-general-form';
import { EInvoiceStatusRail } from '@/components/pms/einvoice/einvoice-status-rail';
import type {
    EInvoicePropertyDefaults,
    EInvoiceSetting,
} from '@/components/pms/einvoice/settings-types';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Workspace } from '@/components/pms/workspace';
import { edit as settingsEdit } from '@/routes/settings';
import { business, connection, general } from '@/routes/settings/e-invoice';

type Tab = 'general' | 'connection' | 'business';

/** The E-invoicing tabs, for the tab bar and the breadcrumbs. */
function eInvoiceTabs(): TabLink[] {
    return [
        {
            value: 'general',
            label: 'General',
            icon: SlidersHorizontal,
            href: general(),
        },
        {
            value: 'connection',
            label: 'Connection',
            icon: Plug,
            href: connection(),
        },
        {
            value: 'business',
            label: 'Business details',
            icon: Building2,
            href: business(),
        },
    ];
}

/**
 * Settings › E-invoicing. Each tab is its own URL and saves only its own fields from the floating Save bar. The tab
 * content sits in a fixed-height `Workspace` like Settings › Property, with the Status rail on every tab.
 */
export default function EInvoiceSettings({
    tab,
    setting,
    problems,
    malaysiaStates = [],
    propertyDefaults,
}: {
    tab: Tab;
    setting: EInvoiceSetting | null;
    /** What stops e-invoices from going out (or e-invoicing from switching on), by field. */
    problems: Record<string, string>;
    /** Business details tab only. */
    malaysiaStates?: EnumOption[];
    /** Business details tab only. */
    propertyDefaults?: EInvoicePropertyDefaults;
}) {
    return (
        <>
            <Head title="E-invoicing" />
            <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 lg:overflow-hidden">
                <PageHeader
                    title="E-invoicing"
                    description="Send e-invoices to LHDN (the Malaysian tax authority) through MyInvois. Each tab is saved on its own."
                />
                <TabLinks
                    activeTab={tab}
                    tabs={eInvoiceTabs()}
                    triggerClassName="md:min-w-0"
                    panelWidth="full"
                    className="flex flex-col gap-4 lg:min-h-0 lg:flex-1"
                    panelClassName="flex flex-col lg:min-h-0 lg:flex-1"
                >
                    <Workspace
                        asideLabel="Status"
                        asideWidth="wide"
                        aside={
                            <EInvoiceStatusRail
                                setting={setting}
                                problems={problems}
                                onBusinessTab={tab === 'business'}
                            />
                        }
                    >
                        {tab === 'general' && (
                            <EInvoiceGeneralForm setting={setting} />
                        )}
                        {tab === 'connection' && (
                            <EInvoiceConnectionForm setting={setting} />
                        )}
                        {tab === 'business' && propertyDefaults && (
                            <EInvoiceBusinessForm
                                setting={setting}
                                propertyDefaults={propertyDefaults}
                                malaysiaStates={malaysiaStates}
                            />
                        )}
                    </Workspace>
                </TabLinks>
            </div>
        </>
    );
}

EInvoiceSettings.layout = (props: { tab: Tab }) => ({
    variant: 'fixed',
    breadcrumbs: withTabCrumb(
        [
            { title: 'Settings', href: settingsEdit() },
            { title: 'E-invoicing', href: general() },
        ],
        eInvoiceTabs(),
        props.tab,
    ),
});
