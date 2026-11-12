import { useForm } from '@inertiajs/react';
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
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store, update } from '@/routes/admin/webhooks';
import type { WebhookEndpoint } from './webhook-tabs';

export type WebhookEventOption = EnumOption & { group: string };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The endpoint being edited, or null to add one. */
    endpoint: WebhookEndpoint | null;
    properties: EnumOption[];
    events: WebhookEventOption[];
};

type FormData = {
    name: string;
    url: string;
    property_id: string;
    events: string[];
    is_active: boolean;
};

function WebhookEndpointForm({
    open,
    onOpenChange,
    endpoint,
    properties,
    events,
}: Props) {
    const form = useForm<FormData>({
        name: endpoint?.name ?? '',
        url: endpoint?.url ?? '',
        property_id: endpoint?.propertyId ? String(endpoint.propertyId) : '',
        events: endpoint?.events ?? [],
        is_active: endpoint?.isActive ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const groups = [...new Set(events.map((event) => event.group))];

    const toggle = (value: string, checked: boolean) =>
        form.setData(
            'events',
            checked
                ? [...form.data.events, value]
                : form.data.events.filter((event) => event !== value),
        );

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        form.transform((data) => ({
            ...data,
            property_id: data.property_id === '' ? null : data.property_id,
        }));

        if (endpoint) {
            form.put(update.url(endpoint.id), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <FormOverlay
            variant="sheet"
            size="lg"
            open={open}
            onOpenChange={onOpenChange}
            title={endpoint ? `Edit ${endpoint.name}` : 'Add endpoint'}
            description="Estatell posts signed JSON to this URL when an event it hears happens."
            errors={form.errors}
            dirty={form.isDirty}
            discardTitle="Discard this endpoint?"
            onSubmit={submit}
            submitLabel={endpoint ? 'Save endpoint' : 'Add endpoint'}
            processing={form.processing}
        >
            <FieldGroup>
                <Field data-invalid={!!errors.name}>
                    <FieldLabel htmlFor="webhook-name">Name</FieldLabel>
                    <Input
                        id="webhook-name"
                        maxLength={80}
                        placeholder="n8n – WhatsApp"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!errors.name}
                    />
                    <FieldError>{errors.name}</FieldError>
                </Field>

                <Field data-invalid={!!errors.url}>
                    <FieldLabel htmlFor="webhook-url">URL</FieldLabel>
                    <Input
                        id="webhook-url"
                        type="url"
                        inputMode="url"
                        placeholder="https://n8n.example.com/webhook/…"
                        value={form.data.url}
                        onChange={(event) =>
                            form.setData('url', event.target.value)
                        }
                        aria-invalid={!!errors.url}
                    />
                    <FieldDescription>
                        Use the production URL of the n8n Webhook node.
                    </FieldDescription>
                    <FieldError>{errors.url}</FieldError>
                </Field>

                <Field data-invalid={!!errors.property_id}>
                    <FieldLabel htmlFor="webhook-property">Property</FieldLabel>
                    <EnumSelect
                        id="webhook-property"
                        name="property_id"
                        options={properties}
                        value={form.data.property_id}
                        onValueChange={(value) =>
                            form.setData('property_id', value)
                        }
                        allLabel="All properties"
                        aria-label="Property"
                    />
                    <FieldError>{errors.property_id}</FieldError>
                </Field>

                <FieldSet data-invalid={!!errors.events}>
                    <div className="flex items-center justify-between gap-2">
                        <FieldLegend>Events</FieldLegend>
                        <div className="flex gap-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    form.setData(
                                        'events',
                                        events.map((event) => event.value),
                                    )
                                }
                            >
                                Select all
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => form.setData('events', [])}
                            >
                                None
                            </Button>
                        </div>
                    </div>
                    {groups.map((group) => (
                        <div key={group} className="flex flex-col gap-2">
                            <span className="text-sm font-medium text-muted-foreground">
                                {group}
                            </span>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {events
                                    .filter((event) => event.group === group)
                                    .map((event) => (
                                        <Field
                                            key={event.value}
                                            orientation="horizontal"
                                        >
                                            <Checkbox
                                                id={`webhook-event-${event.value}`}
                                                checked={form.data.events.includes(
                                                    event.value,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggle(event.value, checked)
                                                }
                                            />
                                            <FieldContent>
                                                <FieldLabel
                                                    htmlFor={`webhook-event-${event.value}`}
                                                >
                                                    {event.label}
                                                </FieldLabel>
                                                <FieldDescription className="font-mono text-xs">
                                                    {event.value}
                                                </FieldDescription>
                                            </FieldContent>
                                        </Field>
                                    ))}
                            </div>
                        </div>
                    ))}
                    <FieldError>{errors.events}</FieldError>
                </FieldSet>

                {!endpoint && (
                    <Field orientation="horizontal">
                        <Checkbox
                            id="webhook-active"
                            checked={form.data.is_active}
                            onCheckedChange={(checked) =>
                                form.setData('is_active', checked)
                            }
                        />
                        <FieldContent>
                            <FieldLabel htmlFor="webhook-active">
                                Active
                            </FieldLabel>
                            <FieldDescription>
                                Send events as soon as it is saved.
                            </FieldDescription>
                        </FieldContent>
                    </Field>
                )}
            </FieldGroup>
        </FormOverlay>
    );
}

/**
 * Adds or edits one webhook endpoint. Keyed on each opening, so it always starts from the endpoint it was opened for.
 */
export function WebhookEndpointSheet(props: Props) {
    return <WebhookEndpointForm key={useFreshOnOpen(props.open)} {...props} />;
}
