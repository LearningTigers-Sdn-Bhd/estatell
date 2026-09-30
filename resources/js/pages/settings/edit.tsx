import { Head, Link } from '@inertiajs/react';
import {
    Baby,
    IdCard,
    LifeBuoy,
    MoonStar,
    SlidersHorizontal,
} from 'lucide-react';
import PropertySettingsController from '@/actions/App/Http/Controllers/Property/PropertySettingsController';
import Heading from '@/components/heading';
import type { EnumOption } from '@/components/pms/enum-select';
import { AgeGroupsForm } from '@/components/pms/settings/age-groups-form';
import { SettingsSection } from '@/components/pms/settings-section';
import { TabLinks } from '@/components/pms/tab-links';
import {
    SettingsSwitch,
    SettingsInput,
    SettingsSelect,
} from '@/components/pms/settings/form-fields';
import { buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { edit as basicsEdit } from '@/routes/onboarding/basics';
import {
    ageGroups as ageGroupsTab,
    edit as general,
    operations,
    registration,
    support,
} from '@/routes/settings';

type Section =
    | 'general'
    | 'operations'
    | 'registration'
    | 'support'
    | 'age-groups';

const form = (section: Section) =>
    PropertySettingsController.update.form({ section });

export default function PropertySettings({
    settings,
    tab,
    currencies = [],
    locales = [],
    missedArrivalPolicies = [],
    overdueDeparturePolicies = [],
    noShowCharges = [],
    registrationCardScopes = [],
    ageGroups = [],
    maxAge = 17,
}: {
    settings: App.Data.Property.PropertySettingsData | null;
    tab: Section;
    currencies?: EnumOption[];
    locales?: EnumOption[];
    missedArrivalPolicies?: EnumOption[];
    overdueDeparturePolicies?: EnumOption[];
    noShowCharges?: EnumOption[];
    registrationCardScopes?: EnumOption[];
    ageGroups?: App.Data.Property.PropertyAgeGroupData[];
    maxAge?: number;
}) {
    if (!settings) {
        return (
            <>
                <Head title="Property settings" />
                <div className="flex flex-col gap-6 p-4">
                    <Heading
                        title="Property settings"
                        description="Finish the property basics to set up operating settings."
                    />
                    <Card>
                        <CardHeader>
                            <CardTitle>Complete property basics</CardTitle>
                            <CardDescription>
                                Choose a property type and add its address.
                                Malaysia defaults will then be ready to review.
                            </CardDescription>
                        </CardHeader>
                        <CardFooter>
                            <Link
                                href={basicsEdit()}
                                className={buttonVariants()}
                            >
                                Set up basics
                            </Link>
                        </CardFooter>
                    </Card>
                </div>
            </>
        );
    }

    const lockedDescription = settings.currencyAndTimeZoneLocked
        ? 'Fixed after the property goes live.'
        : undefined;

    return (
        <>
            <Head title="Property settings" />
            <div className="flex flex-col gap-4 p-4">
                <Heading
                    title="Property settings"
                    description="Review and save each section separately."
                    className="mb-0"
                />
                <TabLinks
                    activeTab={tab}
                    className="flex flex-col gap-4"
                    tabs={[
                        {
                            value: 'general',
                            label: 'General',
                            icon: SlidersHorizontal,
                            href: general(),
                        },
                        {
                            value: 'operations',
                            label: 'Operations',
                            icon: MoonStar,
                            href: operations(),
                        },
                        {
                            value: 'registration',
                            label: 'Registration card',
                            icon: IdCard,
                            href: registration(),
                        },
                        {
                            value: 'age-groups',
                            label: 'Age groups',
                            icon: Baby,
                            href: ageGroupsTab(),
                        },
                        {
                            value: 'support',
                            label: 'Support access',
                            icon: LifeBuoy,
                            href: support(),
                        },
                    ]}
                >
                    {tab === 'general' && (
                        <SettingsSection
                            form={form('general')}
                            title="General"
                            description="Currency, local time, arrivals, and room assignment."
                        >
                            {(errors) => (
                                <>
                                    <SettingsSelect
                                        name="currency"
                                        label="Currency"
                                        value={settings.currency}
                                        options={currencies}
                                        disabled={
                                            settings.currencyAndTimeZoneLocked
                                        }
                                        description={lockedDescription}
                                        error={errors.currency}
                                    />
                                    <SettingsInput
                                        name="time_zone"
                                        label="Time zone"
                                        value={settings.timeZone}
                                        disabled={
                                            settings.currencyAndTimeZoneLocked
                                        }
                                        error={errors.time_zone}
                                        description={
                                            lockedDescription ??
                                            'IANA time zone, for example Asia/Kuala_Lumpur.'
                                        }
                                    />
                                    <SettingsSelect
                                        name="default_locale"
                                        label="Default language"
                                        value={settings.defaultLocale}
                                        options={locales}
                                        error={errors.default_locale}
                                    />
                                    <SettingsInput
                                        name="arrival_grace_minutes"
                                        label="Arrival grace period (minutes)"
                                        type="number"
                                        min={0}
                                        max={1440}
                                        value={settings.arrivalGraceMinutes}
                                        error={errors.arrival_grace_minutes}
                                    />
                                    <SettingsInput
                                        name="check_in_time"
                                        label="Check-in time"
                                        type="time"
                                        value={settings.checkInTime}
                                        error={errors.check_in_time}
                                    />
                                    <SettingsInput
                                        name="check_out_time"
                                        label="Check-out time"
                                        type="time"
                                        value={settings.checkOutTime}
                                        error={errors.check_out_time}
                                    />
                                    <SettingsSwitch
                                        name="auto_assign_rooms"
                                        label="Assign rooms automatically"
                                        description="On: the system assigns a free, clean room to each arrival. Off: staff assign rooms by hand."
                                        checked={settings.autoAssignRooms}
                                        error={errors.auto_assign_rooms}
                                    />
                                </>
                            )}
                        </SettingsSection>
                    )}
                    {tab === 'operations' && (
                        <SettingsSection
                            form={form('operations')}
                            title="Operations"
                            description="Night audit and reservation exception rules."
                        >
                            {(errors) => (
                                <>
                                    <SettingsInput
                                        name="audit_time"
                                        label="Night audit time"
                                        type="time"
                                        value={settings.auditTime}
                                        error={errors.audit_time}
                                    />
                                    <SettingsSelect
                                        name="missed_arrival_policy"
                                        label="Missed arrival"
                                        value={settings.missedArrivalPolicy}
                                        options={missedArrivalPolicies}
                                        error={errors.missed_arrival_policy}
                                    />
                                    <SettingsSelect
                                        name="overdue_departure_policy"
                                        label="Overdue departure"
                                        value={settings.overdueDeparturePolicy}
                                        options={overdueDeparturePolicies}
                                        error={errors.overdue_departure_policy}
                                    />
                                    <SettingsSelect
                                        name="no_show_charge"
                                        label="No-show charge"
                                        value={settings.noShowCharge}
                                        options={noShowCharges}
                                        error={errors.no_show_charge}
                                    />
                                    <SettingsSwitch
                                        name="auto_audit_enabled"
                                        label="Run night audit automatically"
                                        description="On: the night audit runs by itself after the audit time. Off: staff start it by hand."
                                        checked={settings.autoAuditEnabled}
                                        error={errors.auto_audit_enabled}
                                    />
                                </>
                            )}
                        </SettingsSection>
                    )}
                    {tab === 'registration' && (
                        <SettingsSection
                            form={form('registration')}
                            title="Registration card"
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
                                        min={0}
                                        max={3650}
                                        value={settings.idDocumentRetentionDays}
                                        error={
                                            errors.id_document_retention_days
                                        }
                                        description="Leave blank until document capture is configured."
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
                    {tab === 'age-groups' && (
                        <AgeGroupsForm ageGroups={ageGroups} maxAge={maxAge} />
                    )}
                    {tab === 'support' && (
                        <SettingsSection
                            form={form('support')}
                            title="Support access"
                            description="Control whether platform support may view this property when that feature is available."
                            contentClassName=""
                        >
                            {(errors) => (
                                <SettingsSwitch
                                    name="allow_support_access"
                                    label="Allow platform support access"
                                    description="On: platform support can view this property as its owner to help you. Off: support cannot open this property."
                                    checked={settings.allowSupportAccess}
                                    error={errors.allow_support_access}
                                />
                            )}
                        </SettingsSection>
                    )}
                </TabLinks>
            </div>
        </>
    );
}

PropertySettings.layout = {
    breadcrumbs: [{ title: 'Settings', href: general() }],
};
