import { useForm } from '@inertiajs/react';
import {
    EventFields,
    eventFieldPayload,
    payerMissing,
} from '@/components/pms/events/event-fields';
import type { EventFieldValues } from '@/components/pms/events/event-fields';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/events';

type Event = App.Data.Events.EventData;
type Company = App.Data.CityLedger.CompanyOptionData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    event: Event;
    canViewCompany: boolean;
    companies?: Company[];
};

function EventForm({
    open,
    onOpenChange,
    event,
    canViewCompany,
    companies,
}: Props) {
    useFormOptions(
        open && canViewCompany,
        companies !== undefined,
        'companies',
    );

    const form = useForm<EventFieldValues & { notes: string }>({
        name: event.name,
        payerType: event.payerCompanyId != null ? 'company' : 'guest',
        payer: event.payerGuest ?? null,
        newGuestName: '',
        company:
            event.payerCompanyId != null ? String(event.payerCompanyId) : '',
        startsOn: event.startsOn,
        endsOn: event.endsOn,
        notes: event.notes ?? '',
    });
    const { data } = form;
    const errors = form.errors as Partial<Record<string, string>>;

    const submit = () => {
        form.transform((values) => ({
            ...eventFieldPayload(values),
            notes: values.notes,
        }));
        form.put(update.url({ event: event.id }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Edit event"
            errors={errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel="Save event"
            processing={form.processing}
            submitDisabled={
                data.name.trim() === '' ||
                payerMissing(data) ||
                data.startsOn === '' ||
                data.endsOn === ''
            }
        >
            <EventFields
                values={data}
                onChange={(changes) =>
                    form.setData((current) => ({ ...current, ...changes }))
                }
                errors={errors}
                isNew={false}
                canAddGuest={false}
                canViewCompany={canViewCompany}
                companies={companies}
            />
            <Field data-invalid={!!errors.notes}>
                <FieldLabel htmlFor="event-notes">Notes</FieldLabel>
                <Textarea
                    id="event-notes"
                    name="notes"
                    maxLength={2000}
                    placeholder="e.g. Vegetarian menu for 20 guests"
                    value={data.notes}
                    onChange={(change) =>
                        form.setData('notes', change.target.value)
                    }
                    aria-invalid={!!errors.notes}
                />
                <FieldError>{errors.notes}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * Edit event. New event is a page (`pages/events/create.tsx`). See
 * docs/33-facilities/03-events-and-event-folios.md#edit-event-dialog.
 */
export function EventFormDialog(props: Props) {
    return <EventForm key={useFreshOnOpen(props.open)} {...props} />;
}
