import { Head } from '@inertiajs/react';
import { FileText, SlidersHorizontal, TableProperties } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { RegistrationCardGeneralForm } from '@/components/pms/registration-card/registration-card-general-form';
import { RegistrationCardLayoutForm } from '@/components/pms/registration-card/registration-card-layout-form';
import { RegistrationCardTermsList } from '@/components/pms/registration-card/registration-card-terms-list';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Workspace } from '@/components/pms/workspace';
import { edit as settingsEdit } from '@/routes/settings';
import { general, layout, terms } from '@/routes/settings/registration-card';

type Tab = 'general' | 'layout' | 'terms';

/** The Registration card tabs. Terms shows only for staff who can manage the terms. */
function registrationCardTabs(canManageTerms: boolean): TabLink[] {
    return [
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
    ];
}

/**
 * Settings › Registration card. Each tab is its own URL. The tab content sits in a fixed-height `Workspace` like
 * Settings › Property: each panel scrolls on its own. Layout shows its preview in a right rail. Terms lists the documents; each opens its own editor page.
 */
export default function RegistrationCard({
    settings,
    propertyName,
    tab,
    canManageTerms,
    registrationCardScopes = [],
    fields = [],
    termsDocuments = [],
    termsPresets = [],
    defaultTermsPreset = '',
}: {
    settings: App.Data.Property.PropertySettingsData;
    propertyName: string;
    tab: Tab;
    canManageTerms: boolean;
    registrationCardScopes?: EnumOption[];
    fields?: App.Data.Registration.RegistrationCardFieldData[];
    termsDocuments?: App.Data.Registration.TermsDocumentData[];
    termsPresets?: EnumOption[];
    defaultTermsPreset?: string;
}) {
    return (
        <>
            <Head title="Registration card" />
            <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 lg:overflow-hidden">
                <PageHeader
                    title="Registration card"
                    description="What the card asks for, what it shows, and when a guest must complete it."
                />
                <TabLinks
                    activeTab={tab}
                    tabs={registrationCardTabs(canManageTerms)}
                    triggerClassName="md:min-w-0"
                    panelWidth="full"
                    className="flex flex-col gap-4 lg:min-h-0 lg:flex-1"
                    panelClassName="flex flex-col lg:min-h-0 lg:flex-1"
                >
                    {tab === 'general' && (
                        <Workspace hideAside>
                            <RegistrationCardGeneralForm
                                settings={settings}
                                scopes={registrationCardScopes}
                            />
                        </Workspace>
                    )}
                    {tab === 'layout' && (
                        <RegistrationCardLayoutForm
                            fields={fields}
                            propertyName={propertyName}
                        />
                    )}
                    {tab === 'terms' && (
                        <RegistrationCardTermsList
                            documents={termsDocuments}
                            presets={termsPresets}
                            defaultPreset={defaultTermsPreset}
                        />
                    )}
                </TabLinks>
            </div>
        </>
    );
}

RegistrationCard.layout = (props: { tab: Tab; canManageTerms: boolean }) => ({
    variant: 'fixed',
    breadcrumbs: withTabCrumb(
        [
            { title: 'Settings', href: settingsEdit() },
            { title: 'Registration card', href: general() },
        ],
        registrationCardTabs(props.canManageTerms),
        props.tab,
    ),
});
