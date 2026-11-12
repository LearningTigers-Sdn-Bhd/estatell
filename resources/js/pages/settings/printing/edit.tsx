import { Head } from '@inertiajs/react';
import { FileText, PanelTop, Type } from 'lucide-react';
import Heading from '@/components/heading';
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
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title="Printing"
                        description="How every PDF of this property looks: receipts, invoices, statements and reports."
                        className="mb-0"
                    />
                    <AutoSaveStatus saving={form.saving} />
                </div>
                <TabLinks
                    activeTab={tab}
                    className="flex flex-col gap-4"
                    panelWidth="full"
                    tabs={printingTabs()}
                >
                    <div className="grid gap-6 lg:grid-cols-3 lg:items-start">
                        <div className="lg:col-span-2">
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
                        </div>
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
                    </div>
                </TabLinks>
            </div>
        </>
    );
}

PrintingSettings.layout = (props: { tab: Tab }) => ({
    breadcrumbs: withTabCrumb(
        [{ title: 'Printing', href: general() }],
        printingTabs(),
        props.tab,
    ),
});
