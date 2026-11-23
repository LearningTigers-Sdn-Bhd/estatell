import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    EventFields,
    eventFieldPayload,
    payerMissing,
} from '@/components/pms/events/event-fields';
import type { EventFieldValues } from '@/components/pms/events/event-fields';
import { FormActions } from '@/components/pms/form-actions';
import { FormError } from '@/components/pms/form-error';
import { PageHeader } from '@/components/pms/page-header';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { create, index, store } from '@/routes/events';

type Props = {
    canAddGuest: boolean;
    canViewCompany: boolean;
    companies: App.Data.CityLedger.CompanyOptionData[];
    /** Tentative and Confirmed. */
    statuses: EnumOption[];
    /** Both dates start here, `Y-m-d`: the business date. */
    defaultDate: string;
};

/**
 * New event: a full page so facility booking lines can join it. Saving creates the event and its folio and opens the
 * event page. See docs/33-facilities/03-events-and-event-folios.md#new-event-page.
 */
export default function EventCreate({
    canAddGuest,
    canViewCompany,
    companies,
    statuses,
    defaultDate,
}: Props) {
    const form = useForm<EventFieldValues & { status: string; notes: string }>({
        name: '',
        payerType: 'guest',
        payer: null,
        newGuestName: '',
        company: '',
        startsOn: defaultDate,
        endsOn: defaultDate,
        status: 'confirmed',
        notes: '',
    });
    const { data } = form;
    const errors = form.errors as Partial<Record<string, string>>;
    const guard = useUnsavedChanges(form.isDirty && !form.processing);

    const canSubmit =
        data.name.trim() !== '' &&
        !payerMissing(data) &&
        data.startsOn !== '' &&
        data.endsOn !== '';

    const submit = () => {
        form.transform((values) => ({
            ...eventFieldPayload(values),
            status: values.status,
            notes: values.notes,
        }));
        form.post(store.url());
    };

    return (
        <>
            <Head title="New event" />

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
                className="flex min-h-0 flex-1 flex-col"
            >
                <div
                    scroll-region=""
                    className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 pb-12"
                >
                    <PageHeader
                        title="New event"
                        description="A bill for people who are not staying: a wedding dinner, a meeting or a walk-in lunch. It gets its own folio."
                        back={{ href: index(), label: 'Events' }}
                    />

                    <div className="flex w-full max-w-2xl flex-col gap-8">
                        <FormError errors={errors} />

                        <FieldSet>
                            <FieldLegend>Event</FieldLegend>
                            <FieldDescription>
                                Who pays and on which days. A one-day event has
                                the same date at both ends.
                            </FieldDescription>
                            <FieldGroup>
                                <EventFields
                                    values={data}
                                    onChange={(changes) =>
                                        form.setData((current) => ({
                                            ...current,
                                            ...changes,
                                        }))
                                    }
                                    errors={errors}
                                    isNew
                                    canAddGuest={canAddGuest}
                                    canViewCompany={canViewCompany}
                                    companies={companies}
                                />
                                <Field data-invalid={!!errors.status}>
                                    <FieldLabel htmlFor="event-status">
                                        Status
                                    </FieldLabel>
                                    <EnumSelect
                                        id="event-status"
                                        name="status"
                                        options={statuses}
                                        value={data.status}
                                        onValueChange={(value) =>
                                            form.setData('status', value)
                                        }
                                        aria-invalid={!!errors.status}
                                    />
                                    <FieldDescription>
                                        Tentative holds the dates before the
                                        deposit is paid.
                                    </FieldDescription>
                                    <FieldError>{errors.status}</FieldError>
                                </Field>
                                <Field data-invalid={!!errors.notes}>
                                    <FieldLabel htmlFor="event-notes">
                                        Notes
                                    </FieldLabel>
                                    <Textarea
                                        id="event-notes"
                                        name="notes"
                                        maxLength={2000}
                                        placeholder="e.g. Vegetarian menu for 20 guests"
                                        value={data.notes}
                                        onChange={(change) =>
                                            form.setData(
                                                'notes',
                                                change.target.value,
                                            )
                                        }
                                        aria-invalid={!!errors.notes}
                                    />
                                    <FieldError>{errors.notes}</FieldError>
                                </Field>
                            </FieldGroup>
                        </FieldSet>
                    </div>
                </div>

                <FormActions fixed>
                    <Link
                        href={index()}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        <X data-icon="inline-start" />
                        Cancel
                    </Link>
                    <Button
                        type="submit"
                        disabled={!canSubmit || form.processing}
                        focusableWhenDisabled
                    >
                        {form.processing ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <Plus data-icon="inline-start" />
                        )}
                        Create event
                    </Button>
                </FormActions>
            </form>

            <UnsavedChangesDialog
                guard={guard}
                name="this event"
                saving={form.processing}
                onSave={submit}
            />
        </>
    );
}

EventCreate.layout = (props: {
    property: App.Data.Property.PropertySummaryData;
}) => ({
    variant: 'fixed' as const,
    // The property code is passed in because this runs before AppLayout sets the URL defaults.
    breadcrumbs: [
        { title: 'Events', href: index(props.property.code) },
        { title: 'New event', href: create(props.property.code) },
    ],
});
