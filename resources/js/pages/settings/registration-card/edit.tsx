import { Head } from '@inertiajs/react';
import { SlidersHorizontal, TableProperties } from 'lucide-react';
import RegistrationCardController from '@/actions/App/Http/Controllers/Registration/RegistrationCardController';
import Heading from '@/components/heading';
import type { EnumOption } from '@/components/pms/enum-select';
import { RegistrationCardLayoutForm } from '@/components/pms/registration-card/registration-card-layout-form';
import { SettingsSection } from '@/components/pms/settings-section';
import {
    SettingsInput,
    SettingsSelect,
    SettingsSwitch,
} from '@/components/pms/settings/form-fields';
import { TabLinks } from '@/components/pms/tab-links';
import { edit as settingsEdit } from '@/routes/settings';
import { general, layout } from '@/routes/settings/registration-card';

type Tab = 'general' | 'layout';

export default function RegistrationCard({
    settings,
    propertyName,
    tab,
    registrationCardScopes = [],
    fields = [],
}: {
    settings: App.Data.Property.PropertySettingsData;
    propertyName: string;
    tab: Tab;
    registrationCardScopes?: EnumOption[];
    fields?: App.Data.Registration.RegistrationCardFieldData[];
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
                    panelWidth={tab === 'layout' ? 'full' : 'default'}
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
                    ]}
                >
                    {tab === 'general' && (
                        <SettingsSection
                            form={RegistrationCardController.update.form()}
                            title="General"
                            description="Guest signature, ID document, and deposit rules."
                        >
                            {(errors) => (
                                <>
                                    <SettingsSelect
                                        name="registration_card_scope"
                                        label="Who completes a card"
                                        value={settings.registrationCardScope}
                                        options={registrationCardScopes}
                                        error={errors.registration_card_scope}
                                    />
                                    <SettingsInput
                                        name="id_document_retention_days"
                                        label="ID document retention (days)"
                                        type="number"
                                        min={1}
                                        max={3650}
                                        value={settings.idDocumentRetentionDays}
                                        error={
                                            errors.id_document_retention_days
                                        }
                                        description="ID scans older than this many days are deleted every night. The number stays. Leave blank to keep scans."
                                    />
                                    <SettingsInput
                                        name="security_deposit_amount"
                                        label="Security deposit amount"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={settings.securityDepositAmount}
                                        error={errors.security_deposit_amount}
                                    />
                                    <SettingsSwitch
                                        name="card_required_for_check_in"
                                        label="Require card before check-in"
                                        description="On: a guest cannot check in until the registration card is complete. Off: staff can check guests in without a card."
                                        checked={
                                            settings.cardRequiredForCheckIn
                                        }
                                        error={
                                            errors.card_required_for_check_in
                                        }
                                    />
                                </>
                            )}
                        </SettingsSection>
                    )}
                    {tab === 'layout' && (
                        <RegistrationCardLayoutForm
                            fields={fields}
                            propertyName={propertyName}
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
