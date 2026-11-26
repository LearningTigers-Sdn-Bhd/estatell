import { Head, Link } from '@inertiajs/react';
import {
    Baby,
    BadgeCheck,
    Building2,
    Images,
    LifeBuoy,
    MoonStar,
    Save,
    SlidersHorizontal,
    Utensils,
} from 'lucide-react';
import PropertySettingsController from '@/actions/App/Http/Controllers/Property/PropertySettingsController';
import Heading from '@/components/heading';
import type { EnumOption } from '@/components/pms/enum-select';
import { OptionalBadge } from '@/components/pms/optional-badge';
import { PageHeader } from '@/components/pms/page-header';
import { AgeGroupsForm } from '@/components/pms/settings/age-groups-form';
import { BusinessLicenceForm } from '@/components/pms/settings/business-licence-form';
import { MealPeriodsForm } from '@/components/pms/settings/meal-periods-form';
import { PropertyLogoField } from '@/components/pms/settings/property-logo-field';
import { PropertyPhotos } from '@/components/pms/settings/property-photos';
import { SettingsSection } from '@/components/pms/settings-section';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Workspace } from '@/components/pms/workspace';
import {
    SettingsSwitch,
    SettingsInput,
    SettingsPhoneInput,
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
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { useIsImpersonating } from '@/hooks/use-is-impersonating';
import { edit as basicsEdit } from '@/routes/onboarding/basics';
import {
    ageGroups as ageGroupsTab,
    business as businessTab,
    edit as general,
    mealPeriods as mealPeriodsTab,
    operations,
    photos as photosTab,
    profile as profileTab,
    support,
} from '@/routes/settings';

type Section =
    | 'profile'
    | 'business'
    | 'photos'
    | 'general'
    | 'operations'
    | 'support'
    | 'age-groups'
    | 'meal-periods';

const form = (section: Exclude<Section, 'profile'>) =>
    PropertySettingsController.update.form({ section });

/** The Settings tabs, for the tab bar and the breadcrumbs. */
function settingsTabs(): TabLink[] {
    return [
        {
            value: 'profile',
            label: 'Profile',
            icon: Building2,
            href: profileTab(),
        },
        {
            value: 'business',
            label: 'Business licence',
            icon: BadgeCheck,
            href: businessTab(),
        },
        {
            value: 'photos',
            label: 'Photos',
            icon: Images,
            href: photosTab(),
        },
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
            value: 'age-groups',
            label: 'Age groups',
            icon: Baby,
            href: ageGroupsTab(),
        },
        {
            value: 'meal-periods',
            label: 'Meal periods',
            icon: Utensils,
            href: mealPeriodsTab(),
        },
        {
            value: 'support',
            label: 'Support access',
            icon: LifeBuoy,
            href: support(),
        },
    ];
}

/**
 * Settings › Property. Each tab is its own URL and loads only its own data. The tab content sits in a fixed-height
 * `Workspace` like Settings › Concierge: each panel scrolls on its own. Only Profile has a right rail, for the logo.
 */
export default function PropertySettings({
    settings,
    tab,
    profile,
    business,
    maxLicences = 20,
    photos = [],
    photoLimit = 20,
    photoMaxKb = 5120,
    currencies = [],
    locales = [],
    missedArrivalPolicies = [],
    overdueDeparturePolicies = [],
    weekendNightsOptions = [],
    ageGroups = [],
    maxAge = 17,
    mealPeriods = [],
    maxMealPeriods = 12,
    partnerName = null,
}: {
    settings: App.Data.Property.PropertySettingsData | null;
    tab: Section;
    profile?: App.Data.Property.PropertyProfileData;
    business?: App.Data.Property.PropertyBusinessData;
    maxLicences?: number;
    photos?: App.Data.Property.PropertyPhotoData[];
    photoLimit?: number;
    photoMaxKb?: number;
    currencies?: EnumOption[];
    locales?: EnumOption[];
    missedArrivalPolicies?: EnumOption[];
    overdueDeparturePolicies?: EnumOption[];
    weekendNightsOptions?: EnumOption[];
    ageGroups?: App.Data.Property.PropertyAgeGroupData[];
    maxAge?: number;
    mealPeriods?: App.Data.Meals.MealPeriodData[];
    maxMealPeriods?: number;
    /** The property's partner, on the Support tab. The access switch only governs the partner. */
    partnerName?: string | null;
}) {
    const impersonating = useIsImpersonating();
    if (!settings) {
        return (
            <>
                <Head title="Property settings" />
                <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4">
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
    const showLogo = tab === 'profile' && profile !== undefined;

    return (
        <>
            <Head title="Property settings" />
            <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 lg:overflow-hidden">
                <PageHeader
                    title="Property settings"
                    description="Name, address, rules and access for this property. Each tab is saved on its own."
                />
                <TabLinks
                    activeTab={tab}
                    tabs={settingsTabs()}
                    triggerClassName="md:min-w-0"
                    panelWidth="full"
                    className="flex flex-col gap-4 lg:min-h-0 lg:flex-1"
                    panelClassName="flex flex-col lg:min-h-0 lg:flex-1"
                >
                    <Workspace
                        aside={
                            showLogo ? (
                                <PropertyLogoField logoUrl={profile.logoUrl} />
                            ) : undefined
                        }
                        asideLabel="Logo"
                        hideAside={!showLogo}
                        asideWidth="wide"
                    >
                        {tab === 'profile' && profile && (
                            <SettingsSection
                                key={tab}
                                guardUnsavedChanges
                                saveBar
                                form={PropertySettingsController.updateProfile.form()}
                                title="Profile"
                                description="The name, address and contact details printed on receipts, invoices and reports."
                                headerClassName="ps-3 pt-2"
                                submitLabel="Save"
                                submitIcon={Save}
                                contentClassName="flex flex-col gap-6"
                            >
                                {(errors) => (
                                    <>
                                        <FieldSet>
                                            <FieldLegend>Property</FieldLegend>
                                            <FieldGroup>
                                                <SettingsInput
                                                    name="name"
                                                    label="Property name"
                                                    value={profile.name}
                                                    error={errors.name}
                                                />
                                                <Field
                                                    data-invalid={
                                                        !!errors.about
                                                    }
                                                >
                                                    <FieldLabel htmlFor="about">
                                                        About the property{' '}
                                                        <OptionalBadge />
                                                    </FieldLabel>
                                                    <Textarea
                                                        id="about"
                                                        name="about"
                                                        rows={5}
                                                        maxLength={3000}
                                                        defaultValue={
                                                            profile.about ?? ''
                                                        }
                                                        placeholder="e.g. A 40-room boutique hotel in the old town, five minutes from the night market."
                                                        aria-invalid={
                                                            !!errors.about
                                                        }
                                                    />
                                                    <FieldDescription>
                                                        Guests read this on your
                                                        booking website and in
                                                        the Concierge guide. Up
                                                        to 3000 characters.
                                                    </FieldDescription>
                                                    <FieldError>
                                                        {errors.about}
                                                    </FieldError>
                                                </Field>
                                                <FieldGroup className="grid md:grid-cols-2">
                                                    <SettingsInput
                                                        name="property_type"
                                                        label="Property type"
                                                        value={
                                                            profile.typeLabel
                                                        }
                                                        disabled
                                                    />
                                                    <SettingsInput
                                                        name="country"
                                                        label="Country"
                                                        value={
                                                            profile.countryName
                                                        }
                                                        disabled
                                                    />
                                                </FieldGroup>
                                            </FieldGroup>
                                        </FieldSet>
                                        <FieldSet>
                                            <FieldLegend>Address</FieldLegend>
                                            <FieldGroup>
                                                <SettingsInput
                                                    name="address_line1"
                                                    label="Address line 1"
                                                    value={profile.addressLine1}
                                                    error={errors.address_line1}
                                                />
                                                <SettingsInput
                                                    name="address_line2"
                                                    label={
                                                        <>
                                                            Address line 2{' '}
                                                            <OptionalBadge />
                                                        </>
                                                    }
                                                    value={profile.addressLine2}
                                                    error={errors.address_line2}
                                                />
                                                <FieldGroup className="grid md:grid-cols-3">
                                                    <SettingsInput
                                                        name="postal_code"
                                                        label={
                                                            <>
                                                                Postcode{' '}
                                                                <OptionalBadge />
                                                            </>
                                                        }
                                                        value={
                                                            profile.postalCode
                                                        }
                                                        error={
                                                            errors.postal_code
                                                        }
                                                    />
                                                    <SettingsInput
                                                        name="city"
                                                        label="City"
                                                        value={profile.city}
                                                        error={errors.city}
                                                    />
                                                    <SettingsInput
                                                        name="state_code"
                                                        label={
                                                            <>
                                                                State{' '}
                                                                <OptionalBadge />
                                                            </>
                                                        }
                                                        value={
                                                            profile.stateCode
                                                        }
                                                        error={
                                                            errors.state_code
                                                        }
                                                    />
                                                </FieldGroup>
                                            </FieldGroup>
                                        </FieldSet>
                                        <FieldSet>
                                            <FieldLegend>Contact</FieldLegend>
                                            <FieldGroup className="grid md:grid-cols-2">
                                                <SettingsPhoneInput
                                                    name="contact_phone"
                                                    label={
                                                        <>
                                                            Phone{' '}
                                                            <OptionalBadge />
                                                        </>
                                                    }
                                                    value={profile.contactPhone}
                                                    error={errors.contact_phone}
                                                />
                                                <SettingsInput
                                                    name="contact_email"
                                                    label={
                                                        <>
                                                            Email{' '}
                                                            <OptionalBadge />
                                                        </>
                                                    }
                                                    type="email"
                                                    value={profile.contactEmail}
                                                    error={errors.contact_email}
                                                />
                                            </FieldGroup>
                                        </FieldSet>
                                    </>
                                )}
                            </SettingsSection>
                        )}
                        {tab === 'business' && business && (
                            <BusinessLicenceForm
                                key={JSON.stringify(business)}
                                business={business}
                                maxLicences={maxLicences}
                            />
                        )}
                        {tab === 'general' && (
                            <SettingsSection
                                key={tab}
                                guardUnsavedChanges
                                saveBar
                                form={form('general')}
                                title="General"
                                description="Currency, local time, arrivals, and room assignment."
                                headerClassName="ps-3 pt-2"
                                submitLabel="Save"
                                submitIcon={Save}
                            >
                                {(errors) => (
                                    <>
                                        <SettingsSelect
                                            name="currency"
                                            label="Currency"
                                            value={settings.currency.code}
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
                                            description="On: new bookings and OTA bookings get a free room. Check-in fills any still missing. Off: staff assign rooms by hand."
                                            checked={settings.autoAssignRooms}
                                            error={errors.auto_assign_rooms}
                                        />
                                    </>
                                )}
                            </SettingsSection>
                        )}
                        {tab === 'operations' && (
                            <SettingsSection
                                key={tab}
                                guardUnsavedChanges
                                saveBar
                                form={form('operations')}
                                title="Operations"
                                description="Night audit and reservation exception rules."
                                headerClassName="ps-3 pt-2"
                                submitLabel="Save"
                                submitIcon={Save}
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
                                            value={
                                                settings.overdueDeparturePolicy
                                            }
                                            options={overdueDeparturePolicies}
                                            error={
                                                errors.overdue_departure_policy
                                            }
                                        />
                                        <SettingsSelect
                                            name="weekend_nights"
                                            label="Weekend nights"
                                            value={settings.weekendNights}
                                            options={weekendNightsOptions}
                                            error={errors.weekend_nights}
                                        />
                                        <SettingsInput
                                            name="migration_window_days"
                                            label="Migration window (days)"
                                            type="number"
                                            min={0}
                                            max={365}
                                            step="1"
                                            placeholder="e.g. 60"
                                            value={settings.migrationWindowDays}
                                            description="How long after go-live you can bring in stays, deposits and balances from your old system."
                                            error={errors.migration_window_days}
                                        />
                                        <SettingsSwitch
                                            name="auto_audit_enabled"
                                            label="Run night audit automatically"
                                            description="On: the night audit runs by itself after the audit time. Off: staff start it by hand."
                                            checked={settings.autoAuditEnabled}
                                            error={errors.auto_audit_enabled}
                                        />
                                        <SettingsSwitch
                                            name="auto_clean_on_check_out"
                                            label="Mark rooms clean at check-out"
                                            description="On: a room turns Clean when its last guest checks out, so it can be sold at once. Off: it turns Dirty and housekeeping marks it clean."
                                            checked={
                                                settings.autoCleanOnCheckOut
                                            }
                                            error={
                                                errors.auto_clean_on_check_out
                                            }
                                        />
                                    </>
                                )}
                            </SettingsSection>
                        )}
                        {tab === 'photos' && (
                            <PropertyPhotos
                                photos={photos}
                                photoLimit={photoLimit}
                                photoMaxKb={photoMaxKb}
                            />
                        )}
                        {tab === 'age-groups' && (
                            <AgeGroupsForm
                                key={JSON.stringify(ageGroups)}
                                ageGroups={ageGroups}
                                maxAge={maxAge}
                            />
                        )}
                        {tab === 'meal-periods' && (
                            <MealPeriodsForm
                                key={JSON.stringify(mealPeriods)}
                                mealPeriods={mealPeriods}
                                maxPeriods={maxMealPeriods}
                            />
                        )}
                        {tab === 'support' &&
                            (impersonating ? (
                                <p className="ps-3 pt-2 text-sm text-muted-foreground">
                                    Not available during support access.
                                </p>
                            ) : (
                                <SettingsSection
                                    key={tab}
                                    guardUnsavedChanges
                                    saveBar
                                    form={form('support')}
                                    title="Support access"
                                    description="estatell Support can always view this property as its staff to help you."
                                    headerClassName="ps-3 pt-2"
                                    submitLabel="Save"
                                    submitIcon={Save}
                                    contentClassName="flex flex-col gap-5"
                                >
                                    {(errors) => (
                                        <>
                                            {partnerName ? (
                                                <SettingsSwitch
                                                    name="allow_support_access"
                                                    label={`Allow ${partnerName} to view the property as its staff`}
                                                    description="On: your partner can view this property as its staff to help you. Off: your partner cannot open this property."
                                                    checked={
                                                        settings.allowSupportAccess
                                                    }
                                                    error={
                                                        errors.allow_support_access
                                                    }
                                                />
                                            ) : (
                                                <input
                                                    type="hidden"
                                                    name="allow_support_access"
                                                    value={
                                                        settings.allowSupportAccess
                                                            ? '1'
                                                            : '0'
                                                    }
                                                />
                                            )}
                                            <SettingsSwitch
                                                name="allow_support_money_corrections"
                                                label="Allow support to refund, void and issue credit notes"
                                                description="On: during a support session, platform support can refund, void and issue credit notes, and check out with a deposit refund. Off: those are blocked."
                                                checked={
                                                    settings.allowSupportMoneyCorrections
                                                }
                                                error={
                                                    errors.allow_support_money_corrections
                                                }
                                            />
                                        </>
                                    )}
                                </SettingsSection>
                            ))}
                    </Workspace>
                </TabLinks>
            </div>
        </>
    );
}

PropertySettings.layout = (props: { tab: Section }) => ({
    variant: 'fixed',
    breadcrumbs: withTabCrumb(
        [{ title: 'Settings', href: profileTab() }],
        settingsTabs(),
        props.tab,
    ),
});
