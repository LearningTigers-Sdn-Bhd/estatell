import { Form, Head } from '@inertiajs/react';
import {
    BedDouble,
    BedSingle,
    CircleCheck,
    Cloud,
    HardDrive,
    Settings,
    ShieldCheck,
    Waypoints,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import PlatformSettingsController from '@/actions/App/Http/Controllers/Admin/PlatformSettingsController';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks } from '@/components/pms/tab-links';
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
import { dashboard } from '@/routes/admin';
import { channels, edit, storage } from '@/routes/admin/settings';

type Option<T extends string> = {
    value: T;
    label: string;
    description: string;
    disabled: boolean;
};
type Tab = 'general' | 'channels' | 'storage';

const dormIcons: Record<App.Enums.DormStrategy, LucideIcon> = {
    bed_as_unit: BedSingle,
    native_dorm: BedDouble,
};
const storageIcons: Record<App.Enums.MediaDisk, LucideIcon> = {
    local: HardDrive,
    r2: Cloud,
};

export default function PlatformSettings({
    activeTab,
    settings,
    dormStrategies = [],
    mediaDisks = [],
}: {
    activeTab: Tab;
    settings: Partial<App.Data.Platform.PlatformSettingsData>;
    dormStrategies?: Option<App.Enums.DormStrategy>[];
    mediaDisks?: Option<App.Enums.MediaDisk>[];
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
    const section = sections[activeTab];

    return (
        <>
            <Head title="Platform settings" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Platform settings"
                    description="Settings that apply to every property"
                />

                <TabLinks
                    activeTab={activeTab}
                    scrollable
                    tabs={[
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
                    ]}
                >
                    <Form
                        key={activeTab}
                        {...section.form}
                        options={{ preserveScroll: true }}
                        className="flex max-w-xl flex-col gap-8"
                    >
                        {({ processing, errors }) => (
                            <>
                                <FieldSet>
                                    <FieldLegend id={`${section.name}-label`}>
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
                                                        errors[section.name],
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
                                                            {option.description}
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
                                    <FieldError id={`${section.name}-error`}>
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
                </TabLinks>
            </div>
        </>
    );
}

PlatformSettings.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Settings', href: edit() },
    ],
};
