import { Form, Head } from '@inertiajs/react';
import PlatformSettingsController from '@/actions/App/Http/Controllers/Admin/PlatformSettingsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes/admin';
import { edit } from '@/routes/admin/settings';

type Option = { value: string; label: string; disabled?: boolean };

export default function PlatformSettings({
    settings,
    dormStrategies,
    mediaDisks,
}: {
    settings: App.Data.Platform.PlatformSettingsData;
    dormStrategies: Option[];
    mediaDisks: Option[];
}) {
    return (
        <>
            <Head title="Platform settings" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Platform settings"
                    description="Switches that apply to every property"
                />

                <Form
                    {...PlatformSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="flex max-w-xl flex-col gap-8"
                >
                    {({ processing, errors }) => (
                        <>
                            <FieldSet>
                                <FieldLegend>Go-live approval</FieldLegend>
                                <Field orientation="horizontal">
                                    <Checkbox
                                        id="require_go_live_approval"
                                        name="require_go_live_approval"
                                        defaultChecked={
                                            settings.requireGoLiveApproval
                                        }
                                    />
                                    <FieldLabel htmlFor="require_go_live_approval">
                                        Require admin approval before a property
                                        goes live
                                    </FieldLabel>
                                </Field>
                                <FieldError>
                                    {errors.require_go_live_approval}
                                </FieldError>
                            </FieldSet>

                            <FieldSet>
                                <FieldLegend>Channel manager</FieldLegend>
                                <Field>
                                    <FieldLabel htmlFor="default_dorm_strategy">
                                        Default dorm strategy
                                    </FieldLabel>
                                    <Select
                                        name="default_dorm_strategy"
                                        items={dormStrategies}
                                        defaultValue={
                                            settings.defaultDormStrategy
                                        }
                                    >
                                        <SelectTrigger
                                            id="default_dorm_strategy"
                                            className="w-64"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {dormStrategies.map(
                                                    (option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    <FieldDescription>
                                        Used for new channel connections.
                                    </FieldDescription>
                                    <FieldError>
                                        {errors.default_dorm_strategy}
                                    </FieldError>
                                </Field>
                            </FieldSet>

                            <FieldSet>
                                <FieldLegend>File storage</FieldLegend>
                                <FieldDescription>
                                    New uploads go here. Existing files stay
                                    where they are.
                                </FieldDescription>
                                <RadioGroup
                                    name="media_disk"
                                    defaultValue={settings.mediaDisk}
                                >
                                    <FieldGroup className="gap-3">
                                        {mediaDisks.map((option) => (
                                            <Field
                                                key={option.value}
                                                orientation="horizontal"
                                                data-disabled={option.disabled}
                                            >
                                                <RadioGroupItem
                                                    id={`media_disk_${option.value}`}
                                                    value={option.value}
                                                    disabled={option.disabled}
                                                />
                                                <FieldContent>
                                                    <FieldLabel
                                                        htmlFor={`media_disk_${option.value}`}
                                                    >
                                                        {option.label}
                                                        {option.disabled &&
                                                            ' (not set up)'}
                                                    </FieldLabel>
                                                </FieldContent>
                                            </Field>
                                        ))}
                                    </FieldGroup>
                                </RadioGroup>
                                <FieldError>{errors.media_disk}</FieldError>
                            </FieldSet>

                            <div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && <Spinner />}
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
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
