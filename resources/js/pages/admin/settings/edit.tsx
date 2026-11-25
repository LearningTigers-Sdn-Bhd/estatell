import { Form, Head } from '@inertiajs/react';
import {
    BedDouble,
    BedSingle,
    Bot,
    CircleCheck,
    Cloud,
    HardDrive,
    Percent,
    Settings,
    ShieldCheck,
    Waypoints,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import PlatformSettingsController from '@/actions/App/Http/Controllers/Admin/PlatformSettingsController';
import { CommissionSettings } from '@/components/pms/commissions/commission-settings';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
    FieldTitle,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { dashboard } from '@/routes/admin';
import {
    ai,
    channels,
    commissions,
    edit,
    storage,
} from '@/routes/admin/settings';

type Option<T extends string> = {
    value: T;
    label: string;
    description: string;
    disabled: boolean;
};
type Tab = 'general' | 'channels' | 'storage' | 'ai' | 'commissions';

/** The switches for the experimental AI assistant places. */
const aiPlaceSwitches: {
    name: 'ai_booking_website_enabled' | 'ai_staff_helper_enabled';
    key: 'aiBookingWebsiteEnabled' | 'aiStaffHelperEnabled';
    label: string;
    description: string;
}[] = [
    {
        name: 'ai_booking_website_enabled',
        key: 'aiBookingWebsiteEnabled',
        label: 'Booking website chat',
        description:
            'Visitors chat on the booking microsite. Admins can then allow it per property. Work in progress.',
    },
    {
        name: 'ai_staff_helper_enabled',
        key: 'aiStaffHelperEnabled',
        label: 'Staff helper (Ask Estatell)',
        description:
            "Staff ask how-to questions and today's numbers. Admins can then allow it per property. Work in progress.",
    },
];

const dormIcons: Record<App.Enums.DormStrategy, LucideIcon> = {
    bed_as_unit: BedSingle,
    native_dorm: BedDouble,
};
const storageIcons: Record<App.Enums.MediaDisk, LucideIcon> = {
    local: HardDrive,
    r2: Cloud,
};

/** The platform Settings tabs, for the tab bar and the breadcrumbs. */
function platformSettingsTabs(): TabLink[] {
    return [
        {
            value: 'general',
            label: 'General',
            icon: Settings,
            href: edit(),
        },
        {
            value: 'channels',
            label: 'Channel manager',
            icon: Waypoints,
            href: channels(),
        },
        {
            value: 'storage',
            label: 'File storage',
            icon: HardDrive,
            href: storage(),
        },
        {
            value: 'ai',
            label: 'AI assistant',
            icon: Bot,
            href: ai(),
        },
        {
            value: 'commissions',
            label: 'Commissions',
            icon: Percent,
            href: commissions(),
        },
    ];
}

export default function PlatformSettings({
    activeTab,
    settings,
    dormStrategies = [],
    mediaDisks = [],
    commissionRates = [],
    commissionServices = [],
}: {
    activeTab: Tab;
    settings: Partial<App.Data.Platform.PlatformSettingsData>;
    dormStrategies?: Option<App.Enums.DormStrategy>[];
    mediaDisks?: Option<App.Enums.MediaDisk>[];
    commissionRates?: App.Data.Commissions.CommissionRateData[];
    commissionServices?: { value: string; label: string }[];
}) {
    const sections = {
        general: {
            title: 'Go-live approval',
            description:
                'Choose how properties go live after completing setup. Turning approval off does not approve requests already waiting for review.',
            name: 'require_go_live_approval',
            value: settings.requireGoLiveApproval ? '1' : '0',
            form: PlatformSettingsController.update.form(),
            options: [
                {
                    value: '0',
                    label: 'Automatic',
                    description: 'Properties go live after completing setup.',
                    icon: CircleCheck,
                    disabled: false,
                    disabledReason: '',
                },
                {
                    value: '1',
                    label: 'Admin approval',
                    description:
                        'Owners send their setup for review. Approve it or send it back from Properties, under Waiting for approval.',
                    icon: ShieldCheck,
                    disabled: false,
                    disabledReason: '',
                },
            ],
        },
        channels: {
            title: 'Default dorm strategy',
            description:
                'Used for new channel connections. Existing connections keep their current strategy.',
            name: 'default_dorm_strategy',
            value: settings.defaultDormStrategy,
            form: PlatformSettingsController.updateChannels.form(),
            options: dormStrategies.map((option) => ({
                ...option,
                icon: dormIcons[option.value],
                disabledReason: 'Pending verification',
            })),
        },
        storage: {
            title: 'File storage',
            description:
                'Choose where new uploads go. Existing files stay where they are.',
            name: 'media_disk',
            value: settings.mediaDisk,
            form: PlatformSettingsController.updateStorage.form(),
            options: mediaDisks.map((option) => ({
                ...option,
                icon: storageIcons[option.value],
                disabledReason: 'Not set up',
            })),
        },
    };
    const section =
        activeTab === 'commissions' || activeTab === 'ai'
            ? null
            : sections[activeTab];

    return (
        <>
            <Head title="Platform settings" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Platform settings"
                    description="Settings that apply to every property"
                />

                <TabLinks activeTab={activeTab} tabs={platformSettingsTabs()}>
                    {activeTab === 'ai' ? (
                        <Form
                            {...PlatformSettingsController.updateAi.form()}
                            options={{ preserveScroll: true }}
                            className="flex max-w-xl flex-col gap-8"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <FieldSet>
                                        <FieldLegend>
                                            Experimental AI places
                                        </FieldLegend>
                                        <FieldDescription>
                                            Places still being built. While a
                                            switch is off, admins cannot allow
                                            the place and it stays off for every
                                            property. Turning it off keeps each
                                            property's choices.
                                        </FieldDescription>
                                        <div className="flex flex-col gap-4">
                                            {aiPlaceSwitches.map((option) => (
                                                <Field
                                                    key={option.name}
                                                    orientation="horizontal"
                                                    className="items-start"
                                                    data-invalid={Boolean(
                                                        errors[option.name],
                                                    )}
                                                >
                                                    <Switch
                                                        id={option.name}
                                                        name={option.name}
                                                        value="1"
                                                        uncheckedValue="0"
                                                        defaultChecked={
                                                            settings[
                                                                option.key
                                                            ] ?? false
                                                        }
                                                        aria-describedby={`${option.name}-description`}
                                                        aria-invalid={Boolean(
                                                            errors[option.name],
                                                        )}
                                                        className="mt-0.5"
                                                    />
                                                    <FieldContent>
                                                        <FieldLabel
                                                            htmlFor={
                                                                option.name
                                                            }
                                                        >
                                                            {option.label}
                                                        </FieldLabel>
                                                        <FieldDescription
                                                            id={`${option.name}-description`}
                                                        >
                                                            {option.description}
                                                        </FieldDescription>
                                                        <FieldError>
                                                            {
                                                                errors[
                                                                    option.name
                                                                ]
                                                            }
                                                        </FieldError>
                                                    </FieldContent>
                                                </Field>
                                            ))}
                                        </div>
                                    </FieldSet>

                                    <div className="flex justify-end">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && <Spinner />}
                                            Save changes
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    ) : section === null ? (
                        <CommissionSettings
                            rates={commissionRates}
                            services={commissionServices}
                        />
                    ) : (
                        <Form
                            key={activeTab}
                            {...section.form}
                            options={{ preserveScroll: true }}
                            className="flex max-w-xl flex-col gap-8"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <FieldSet>
                                        <FieldLegend
                                            id={`${section.name}-label`}
                                        >
                                            {section.title}
                                        </FieldLegend>
                                        <FieldDescription
                                            id={`${section.name}-description`}
                                        >
                                            {section.description}
                                        </FieldDescription>
                                        <RadioGroup
                                            name={section.name}
                                            defaultValue={section.value}
                                            aria-labelledby={`${section.name}-label`}
                                            aria-describedby={`${section.name}-description${errors[section.name] ? ` ${section.name}-error` : ''}`}
                                            aria-invalid={Boolean(
                                                errors[section.name],
                                            )}
                                        >
                                            {section.options.map((option) => (
                                                <FieldLabel
                                                    key={option.value}
                                                    htmlFor={`${section.name}-${option.value}`}
                                                >
                                                    <Field
                                                        orientation="horizontal"
                                                        data-disabled={
                                                            option.disabled
                                                        }
                                                        data-invalid={Boolean(
                                                            errors[
                                                                section.name
                                                            ],
                                                        )}
                                                    >
                                                        <option.icon
                                                            aria-hidden
                                                            className="mt-0.5 size-5 shrink-0"
                                                        />
                                                        <FieldContent className="min-w-0">
                                                            <FieldTitle>
                                                                {option.label}
                                                            </FieldTitle>
                                                            <FieldDescription
                                                                id={`${section.name}-${option.value}-description`}
                                                            >
                                                                {
                                                                    option.description
                                                                }
                                                            </FieldDescription>
                                                            {option.disabled && (
                                                                <Badge variant="secondary">
                                                                    {
                                                                        option.disabledReason
                                                                    }
                                                                </Badge>
                                                            )}
                                                        </FieldContent>
                                                        <RadioGroupItem
                                                            id={`${section.name}-${option.value}`}
                                                            value={option.value}
                                                            disabled={
                                                                option.disabled
                                                            }
                                                            aria-describedby={`${section.name}-${option.value}-description`}
                                                            aria-invalid={Boolean(
                                                                errors[
                                                                    section.name
                                                                ],
                                                            )}
                                                        />
                                                    </Field>
                                                </FieldLabel>
                                            ))}
                                        </RadioGroup>
                                        <FieldError
                                            id={`${section.name}-error`}
                                        >
                                            {errors[section.name]}
                                        </FieldError>
                                    </FieldSet>

                                    <div className="flex justify-end">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && <Spinner />}
                                            Save changes
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </TabLinks>
            </div>
        </>
    );
}

PlatformSettings.layout = (props: { activeTab: Tab }) => ({
    breadcrumbs: withTabCrumb(
        [
            { title: 'Admin', href: dashboard() },
            { title: 'Settings', href: edit() },
        ],
        platformSettingsTabs(),
        props.activeTab,
    ),
});
