import { Head } from '@inertiajs/react';
import { Eye, FileText, PanelTop, Type } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { PageHeader } from '@/components/pms/page-header';
import { PanelHeading } from '@/components/pms/panel-heading';
import { AutoSaveStatus } from '@/components/pms/settings/auto-save-status';
import type {
    FontSizeOption,
    FontStyleOption,
    TableStyleOption,
} from '@/components/pms/settings/print-general-form';
import { PrintDocumentsForm } from '@/components/pms/settings/print-documents-form';
import { PrintDocumentsPreview } from '@/components/pms/settings/print-documents-preview';
import { PrintGeneralForm } from '@/components/pms/settings/print-general-form';
import { PrintHeaderForm } from '@/components/pms/settings/print-header-form';
import { PrintPreview } from '@/components/pms/settings/print-preview';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Workspace } from '@/components/pms/workspace';
import { useAutoSave } from '@/hooks/use-auto-save';
import { documents, general, header, update } from '@/routes/settings/printing';
import { update as updateDocuments } from '@/routes/settings/printing/documents';
import { update as updateHeader } from '@/routes/settings/printing/header';

type Settings = App.Data.Property.PrintSettingsData;
type Tab = 'general' | 'header' | 'documents';

const request = (tab: Tab) => (value: Settings) =>
    tab === 'general'
        ? {
              url: update.url(),
              data: {
                  font: value.font,
                  font_size: value.fontSize,
                  table_style: value.tableStyle,
                  footer_text: value.footerText,
              },
          }
        : tab === 'header'
          ? {
                url: updateHeader.url(),
                data: {
                    show_logo: value.showLogo,
                    show_address: value.showAddress,
                    show_contact: value.showContact,
                    show_registration_number: value.showRegistrationNumber,
                    show_tin: value.showTin,
                    tax_ids: value.taxIds,
                },
            }
          : {
                url: updateDocuments.url(),
                data: {
                    show_unit_on_lines: value.showUnitOnLines,
                    nightly_breakdown: value.nightlyBreakdown,
                    tax_breakdown: value.taxBreakdown,
                    show_amount_in_words: value.showAmountInWords,
                    show_cancellation_policy: value.showCancellationPolicy,
                    show_signature_lines: value.showSignatureLines,
                    bank_details: value.bankDetails,
                },
            };

/** The Printing tabs, for the tab bar and the breadcrumbs. */
function printingTabs(): TabLink[] {
    return [
        {
            value: 'general',
            label: 'General',
            icon: Type,
            href: general(),
        },
        {
            value: 'header',
            label: 'Header',
            icon: PanelTop,
            href: header(),
        },
        {
            value: 'documents',
            label: 'Documents',
            icon: FileText,
            href: documents(),
        },
    ];
}

/** The heading of each tab's main panel. */
const tabHeadings: Record<
    Tab,
    { icon: LucideIcon; title: string; description: string }
> = {
    general: {
        icon: Type,
        title: 'General',
        description: 'Font, text size, table style and the footer line.',
    },
    header: {
        icon: PanelTop,
        title: 'Header',
        description:
            'The property details printed at the top of every document.',
    },
    documents: {
        icon: FileText,
        title: 'Documents',
        description:
            'The lines and sections on invoices, receipts and the booking summary.',
    },
};

/**
 * Settings › Printing. Each tab is its own URL. The tab content sits in a fixed-height `Workspace` like
 * Settings › Property: each panel scrolls on its own, with the live preview in a 28rem right rail. Every change
 * saves by itself, so there is no Save button.
 */
export default function PrintingSettings({
    tab,
    settings,
    profile,
    taxes,
    fonts = [],
    fontSizes = [],
    tableStyles = [],
    bankAccounts = [],
}: {
    tab: Tab;
    settings: Settings;
    profile: App.Data.Property.PropertyProfileData;
    taxes: App.Data.Property.PrintTaxData[];
    fonts?: FontStyleOption[];
    /** General tab only. */
    fontSizes?: FontSizeOption[];
    /** General tab only. */
    tableStyles?: TableStyleOption[];
    /** Documents tab only. */
    bankAccounts?: string[];
}) {
    const form = useAutoSave(settings, request(tab));

    return (
        <>
            <Head title="Printing" />
            <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 lg:overflow-hidden">
                <PageHeader
                    title="Printing"
                    description="How every PDF of this property looks: receipts, invoices, statements and reports."
                />
                <TabLinks
                    activeTab={tab}
                    tabs={printingTabs()}
                    triggerClassName="md:min-w-0"
                    panelWidth="full"
                    className="flex flex-col gap-4 lg:min-h-0 lg:flex-1"
                    panelClassName="flex flex-col lg:min-h-0 lg:flex-1"
                >
                    <Workspace
                        asideLabel="Preview"
                        asideWidth="wide"
                        aside={
                            <section className="flex flex-col gap-4">
                                <PanelHeading
                                    icon={Eye}
                                    title="Preview"
                                    description="A sample document with your settings."
                                />
                                {tab === 'documents' ? (
                                    <PrintDocumentsPreview
                                        settings={form.value}
                                        fonts={fonts}
                                        propertyName={profile.name}
                                        bankAccounts={bankAccounts}
                                    />
                                ) : (
                                    <PrintPreview
                                        settings={form.value}
                                        profile={profile}
                                        taxes={taxes}
                                        fonts={fonts}
                                    />
                                )}
                            </section>
                        }
                    >
                        <section className="flex flex-col gap-5 pb-6">
                            <PanelHeading
                                {...tabHeadings[tab]}
                                actions={
                                    <AutoSaveStatus saving={form.saving} />
                                }
                            />
                            {tab === 'general' ? (
                                <PrintGeneralForm
                                    value={form.value}
                                    propertyName={profile.name}
                                    fonts={fonts}
                                    fontSizes={fontSizes}
                                    tableStyles={tableStyles}
                                    onChange={form.change}
                                    onDraft={form.draft}
                                />
                            ) : tab === 'header' ? (
                                <PrintHeaderForm
                                    value={form.value}
                                    profile={profile}
                                    taxes={taxes}
                                    onChange={form.change}
                                />
                            ) : (
                                <PrintDocumentsForm
                                    value={form.value}
                                    onChange={form.change}
                                    onDraft={form.draft}
                                    bankAccounts={bankAccounts}
                                />
                            )}
                        </section>
                    </Workspace>
                </TabLinks>
            </div>
        </>
    );
}

PrintingSettings.layout = (props: { tab: Tab }) => ({
    variant: 'fixed',
    breadcrumbs: withTabCrumb(
        [{ title: 'Printing', href: general() }],
        printingTabs(),
        props.tab,
    ),
});
