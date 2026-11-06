import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store, update } from '@/routes/admin/site-templates';

type Template = App.Data.BookingEngine.SiteTemplateData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The template being edited, or null to add one. */
    template: Template | null;
    typeOptions: EnumOption[];
    properties: EnumOption[];
};

type SlotValues = { key: string; label: string; required: boolean };

type FormData = {
    key: string;
    name: string;
    type: string;
    property_id: string;
    preview_url: string;
    allows_main_color: boolean;
    slots: SlotValues[];
};

function toFormData(template: Template | null): FormData {
    if (template === null) {
        return {
            key: '',
            name: '',
            type: 'standard',
            property_id: '',
            preview_url: '',
            allows_main_color: true,
            slots: [{ key: 'hero', label: 'Hero image', required: true }],
        };
    }

    return {
        key: template.key,
        name: template.name,
        type: template.type,
        property_id:
            template.propertyId === null ? '' : String(template.propertyId),
        preview_url: template.previewUrl ?? '',
        allows_main_color: template.allowsMainColor,
        slots: template.slots.map((slot) => ({ ...slot })),
    };
}

function SiteTemplateForm({
    open,
    onOpenChange,
    template,
    typeOptions,
    properties,
}: Props) {
    const form = useForm<FormData>(toFormData(template));
    const errors = form.errors as Record<string, string | undefined>;
    const isNew = template === null;

    const setSlot = (index: number, values: Partial<SlotValues>) => {
        form.setData(
            'slots',
            form.data.slots.map((slot, position) =>
                position === index ? { ...slot, ...values } : slot,
            ),
        );
    };

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (template) {
            form.transform((data) => ({
                name: data.name,
                preview_url: data.preview_url,
                allows_main_color: data.allows_main_color,
                slots: data.slots,
            }));
            form.put(update.url(template.id), options);
        } else {
            form.transform((data) => ({
                ...data,
                property_id:
                    data.type === 'custom' ? data.property_id : undefined,
            }));
            form.post(store.url(), options);
        }
    };

    return (
        <FormOverlay
            variant="sheet"
            size="lg"
            open={open}
            onOpenChange={onOpenChange}
            title={template ? `Edit ${template.name}` : 'Add template'}
            description="The code lives in the microsite repo. This row tells estatell who can pick it and which images it needs."
            errors={form.errors}
            dirty={form.isDirty}
            discardTitle="Discard this template?"
            onSubmit={submit}
            submitLabel={template ? 'Save template' : 'Add template'}
            processing={form.processing}
        >
            <FieldGroup>
                <FieldGroup>
                    <Field data-invalid={!!errors.name}>
                        <FieldLabel htmlFor="template-name">Name</FieldLabel>
                        <Input
                            id="template-name"
                            value={form.data.name}
                            placeholder="e.g. Island resort"
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            aria-invalid={!!errors.name}
                        />
                        <FieldError>{errors.name}</FieldError>
                    </Field>
                </FieldGroup>
                <FieldGroup>
                    <Field data-invalid={!!errors.key}>
                        <FieldLabel htmlFor="template-key">Key</FieldLabel>
                        <Input
                            id="template-key"
                            value={form.data.key}
                            placeholder="e.g. island-resort"
                            maxLength={50}
                            disabled={!isNew}
                            onChange={(event) =>
                                form.setData('key', event.target.value)
                            }
                            aria-invalid={!!errors.key}
                        />
                        <FieldDescription>
                            {isNew
                                ? 'Must match the template folder in the microsite repo.'
                                : 'Set when the template is added.'}
                        </FieldDescription>
                        <FieldError>{errors.key}</FieldError>
                    </Field>
                </FieldGroup>

                <div
                    className={
                        form.data.type === 'custom'
                            ? 'grid gap-4 sm:grid-cols-2'
                            : 'grid gap-4'
                    }
                >
                    <Field data-invalid={!!errors.type}>
                        <FieldLabel htmlFor="template-type">Type</FieldLabel>
                        <EnumSelect
                            id="template-type"
                            options={typeOptions}
                            value={form.data.type}
                            disabled={!isNew}
                            onValueChange={(value) =>
                                form.setData('type', value)
                            }
                            aria-invalid={!!errors.type}
                        />
                        <FieldError>{errors.type}</FieldError>
                    </Field>
                    {form.data.type === 'custom' && (
                        <Field data-invalid={!!errors.property_id}>
                            <FieldLabel htmlFor="template-property">
                                Property
                            </FieldLabel>
                            <EnumSelect
                                id="template-property"
                                options={properties}
                                value={form.data.property_id}
                                disabled={!isNew}
                                placeholder="Choose a property"
                                onValueChange={(value) =>
                                    form.setData('property_id', value)
                                }
                                aria-invalid={!!errors.property_id}
                            />
                            <FieldError>{errors.property_id}</FieldError>
                        </Field>
                    )}
                </div>

                <Field data-invalid={!!errors.preview_url}>
                    <FieldLabel htmlFor="template-preview">
                        Preview link
                    </FieldLabel>
                    <Input
                        id="template-preview"
                        type="url"
                        value={form.data.preview_url}
                        placeholder="https://booking.estatell.com/demo-island-resort"
                        onChange={(event) =>
                            form.setData('preview_url', event.target.value)
                        }
                        aria-invalid={!!errors.preview_url}
                    />
                    <FieldDescription>
                        Where admins check a draft before making it available.
                    </FieldDescription>
                    <FieldError>{errors.preview_url}</FieldError>
                </Field>

                <Field orientation="horizontal">
                    <Checkbox
                        id="template-main-color"
                        checked={form.data.allows_main_color}
                        onCheckedChange={(checked) =>
                            form.setData('allows_main_color', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="template-main-color">
                            Main colour
                        </FieldLabel>
                        <FieldDescription>
                            Show the main colour field on the owner's Design
                            tab. Turn it off if this template has a fixed colour
                            scheme.
                        </FieldDescription>
                    </FieldContent>
                </Field>

                <section className="flex flex-col gap-3">
                    <div className="space-y-1">
                        <h3 className="text-sm font-medium">Image slots</h3>
                        <p className="text-sm text-muted-foreground">
                            Owners add one image per slot on their Design tab.
                        </p>
                    </div>
                    {form.data.slots.map((slot, index) => (
                        <div
                            key={index}
                            className="flex flex-col gap-2 rounded-lg border p-3"
                        >
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field
                                    data-invalid={
                                        !!errors[`slots.${index}.label`]
                                    }
                                >
                                    <FieldLabel htmlFor={`slot-label-${index}`}>
                                        Label
                                    </FieldLabel>
                                    <Input
                                        id={`slot-label-${index}`}
                                        value={slot.label}
                                        placeholder="e.g. Hero image"
                                        onChange={(event) =>
                                            setSlot(index, {
                                                label: event.target.value,
                                            })
                                        }
                                    />
                                    <FieldError>
                                        {errors[`slots.${index}.label`]}
                                    </FieldError>
                                </Field>
                                <Field
                                    data-invalid={
                                        !!errors[`slots.${index}.key`]
                                    }
                                >
                                    <FieldLabel htmlFor={`slot-key-${index}`}>
                                        Key
                                    </FieldLabel>
                                    <Input
                                        id={`slot-key-${index}`}
                                        value={slot.key}
                                        placeholder="e.g. hero"
                                        maxLength={50}
                                        onChange={(event) =>
                                            setSlot(index, {
                                                key: event.target.value,
                                            })
                                        }
                                    />
                                    <FieldError>
                                        {errors[`slots.${index}.key`]}
                                    </FieldError>
                                </Field>
                            </div>
                            <div className="flex items-center justify-between">
                                <Field orientation="horizontal">
                                    <Checkbox
                                        id={`slot-required-${index}`}
                                        checked={slot.required}
                                        onCheckedChange={(checked) =>
                                            setSlot(index, {
                                                required: checked,
                                            })
                                        }
                                    />
                                    <FieldLabel
                                        htmlFor={`slot-required-${index}`}
                                    >
                                        Required to publish
                                    </FieldLabel>
                                </Field>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={`Remove slot ${slot.label || index + 1}`}
                                    onClick={() =>
                                        form.setData(
                                            'slots',
                                            form.data.slots.filter(
                                                (_, position) =>
                                                    position !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                    ))}
                    <FieldError>{errors.slots}</FieldError>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="self-start"
                        onClick={() =>
                            form.setData('slots', [
                                ...form.data.slots,
                                { key: '', label: '', required: false },
                            ])
                        }
                    >
                        <Plus data-icon="inline-start" />
                        Add slot
                    </Button>
                </section>
            </FieldGroup>
        </FormOverlay>
    );
}

/**
 * Adds or edits one template. Keyed on each opening, so it always starts from the template it was opened for.
 */
export function SiteTemplateSheet(props: Props) {
    return <SiteTemplateForm key={useFreshOnOpen(props.open)} {...props} />;
}
