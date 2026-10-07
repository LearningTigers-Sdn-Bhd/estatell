import { Head } from '@inertiajs/react';
import { PanelTop, Type } from 'lucide-react';
import Heading from '@/components/heading';
import type { EnumOption } from '@/components/pms/enum-select';
import { AutoSaveStatus } from '@/components/pms/settings/auto-save-status';
import type { FontStyleOption } from '@/components/pms/settings/print-general-form';
import { PrintGeneralForm } from '@/components/pms/settings/print-general-form';
import { PrintHeaderForm } from '@/components/pms/settings/print-header-form';
import { PrintPreview } from '@/components/pms/settings/print-preview';
import { TabLinks } from '@/components/pms/tab-links';
import { useAutoSave } from '@/hooks/use-auto-save';
import { general, header, update } from '@/routes/settings/printing';
import { update as updateHeader } from '@/routes/settings/printing/header';

type Settings = App.Data.Property.PrintSettingsData;
type Tab = 'general' | 'header';

const request = (tab: Tab) => (value: Settings) =>
    tab === 'general'
        ? {
              url: update.url(),
              data: {
                  font: value.font,
                  font_size: value.fontSize,
                  footer_text: value.footerText,
              },
          }
        : {
              url: updateHeader.url(),
              data: {
                  show_logo: value.showLogo,
                  show_address: value.showAddress,
                  show_contact: value.showContact,
                  show_registration_number: value.showRegistrationNumber,
                  show_tin: value.showTin,
                  tax_ids: value.taxIds,
              },
          };

export default function PrintingSettings({
    tab,
    settings,
    profile,
    taxes,
    fonts = [],
    fontSizes = [],
}: {
    tab: Tab;
    settings: Settings;
    profile: App.Data.Property.PropertyProfileData;
    taxes: App.Data.Property.PrintTaxData[];
    fonts?: FontStyleOption[];
    fontSizes?: EnumOption[];
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
                    tabs={[
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
                    ]}
                >
                    <div className="grid gap-6 lg:grid-cols-3 lg:items-start">
                        <div className="lg:col-span-2">
                            {tab === 'general' ? (
                                <PrintGeneralForm
                                    value={form.value}
                                    propertyName={profile.name}
                                    fonts={fonts}
                                    fontSizes={fontSizes}
                                    onChange={form.change}
                                    onDraft={form.draft}
                                />
                            ) : (
                                <PrintHeaderForm
                                    value={form.value}
                                    profile={profile}
                                    taxes={taxes}
                                    onChange={form.change}
                                />
                            )}
                        </div>
                        <PrintPreview
                            settings={form.value}
                            profile={profile}
                            taxes={taxes}
                            fonts={fonts}
                        />
                    </div>
                </TabLinks>
            </div>
        </>
    );
}

PrintingSettings.layout = {
    breadcrumbs: [{ title: 'Printing', href: general() }],
};
