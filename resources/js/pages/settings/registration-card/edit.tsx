import { Head } from '@inertiajs/react';
import { FileText, SlidersHorizontal, TableProperties } from 'lucide-react';
import Heading from '@/components/heading';
import type { EnumOption } from '@/components/pms/enum-select';
import { RegistrationCardGeneralForm } from '@/components/pms/registration-card/registration-card-general-form';
import { RegistrationCardLayoutForm } from '@/components/pms/registration-card/registration-card-layout-form';
import { RegistrationCardTermsForm } from '@/components/pms/registration-card/registration-card-terms-form';
import { TabLinks } from '@/components/pms/tab-links';
import { edit as settingsEdit } from '@/routes/settings';
import { general, layout, terms } from '@/routes/settings/registration-card';

type Tab = 'general' | 'layout' | 'terms';

export default function RegistrationCard({
    settings,
    propertyName,
    tab,
    canManageTerms,
    registrationCardScopes = [],
    chargeItems = [],
    fields = [],
    termsDocuments = [],
    termsEditor = null,
    termsPresets = [],
    defaultTermsPreset = '',
}: {
    settings: App.Data.Property.PropertySettingsData;
    propertyName: string;
    tab: Tab;
    canManageTerms: boolean;
    registrationCardScopes?: EnumOption[];
    chargeItems?: App.Data.Reservations.ChargeItemOptionData[];
    fields?: App.Data.Registration.RegistrationCardFieldData[];
    termsDocuments?: App.Data.Registration.TermsDocumentData[];
    termsEditor?: App.Data.Registration.TermsEditorData | null;
    termsPresets?: EnumOption[];
    defaultTermsPreset?: string;
}) {
    return (
        <>
            <Head title="Registration card" />
            <div className="flex flex-col gap-4 p-4">
                <Heading
                    title="Registration card"
                    description="What the card asks for, what it shows, and when a guest must complete it."
                    className="mb-0"
                />
                <TabLinks
                    activeTab={tab}
                    className="flex flex-col gap-4"
                    panelWidth={tab === 'general' ? 'default' : 'full'}
                    tabs={[
                        {
                            value: 'general',
                            label: 'General',
                            icon: SlidersHorizontal,
                            href: general(),
                        },
                        {
                            value: 'layout',
                            label: 'Layout',
                            icon: TableProperties,
                            href: layout(),
                        },
                        ...(canManageTerms
                            ? [
                                  {
                                      value: 'terms',
                                      label: 'Terms',
                                      icon: FileText,
                                      href: terms(),
                                  },
                              ]
                            : []),
                    ]}
                >
                    {tab === 'general' && (
                        <RegistrationCardGeneralForm
                            settings={settings}
                            scopes={registrationCardScopes}
                            chargeItems={chargeItems}
                        />
                    )}
                    {tab === 'layout' && (
                        <RegistrationCardLayoutForm
                            fields={fields}
                            propertyName={propertyName}
                        />
                    )}
                    {tab === 'terms' && (
                        <RegistrationCardTermsForm
                            documents={termsDocuments}
                            editor={termsEditor}
                            presets={termsPresets}
                            defaultPreset={defaultTermsPreset}
                        />
                    )}
                </TabLinks>
            </div>
        </>
    );
}

RegistrationCard.layout = {
    breadcrumbs: [
        { title: 'Settings', href: settingsEdit() },
        { title: 'Registration card', href: general() },
    ],
};
