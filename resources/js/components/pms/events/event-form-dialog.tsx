import { useForm } from '@inertiajs/react';
import { Building2, User, UserPlus } from 'lucide-react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { CompanySelect } from '@/components/pms/company-select';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { GuestPicker } from '@/components/pms/guest-picker';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/events';

type Event = App.Data.Events.EventData;
type Guest = App.Data.Guests.GuestOptionData;
type Company = App.Data.CityLedger.CompanyOptionData;
type PayerType = 'guest' | 'new_guest' | 'company';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The event to edit. Without it the Dialog creates one. */
    event?: Event;
    canAddGuest: boolean;
    canViewCompany: boolean;
    companies?: Company[];
    /** Tentative and Confirmed, for a new event. */
    statuses: EnumOption[];
    /** Both dates of a new event start here, `Y-m-d`. */
    eventDefaultDate: string;
};

function EventForm({
    open,
    onOpenChange,
    event,
    canAddGuest,
    canViewCompany,
    companies,
    statuses,
    eventDefaultDate,
}: Props) {
    useFormOptions(
        open && canViewCompany,
        companies !== undefined,
        'companies',
    );

    const isNew = event === undefined;
    const form = useForm<{
        name: string;
        payerType: PayerType;
        payer: Guest | null;
        newGuestName: string;
        company: string;
        startsOn: string;
        endsOn: string;
        status: string;
        notes: string;
    }>({
        name: event?.name ?? '',
        payerType: event?.payerCompanyId != null ? 'company' : 'guest',
        payer: event?.payerGuest ?? null,
        newGuestName: '',
        company:
            event?.payerCompanyId != null ? String(event.payerCompanyId) : '',
        startsOn: event?.startsOn ?? eventDefaultDate,
        endsOn: event?.endsOn ?? eventDefaultDate,
        status: 'confirmed',
        notes: event?.notes ?? '',
    });
    const { data } = form;
    const errors = form.errors as Partial<Record<string, string>>;

    const payerChoices = [
        {
            value: 'guest',
            label: 'Guest',
            description: 'A guest already in the guest list.',
            icon: User,
        },
        ...(isNew && canAddGuest
            ? [
                  {
                      value: 'new_guest',
                      label: 'New guest',
                      description: 'A walk-in. Adds them to the guest list.',
                      icon: UserPlus,
                  },
              ]
            : []),
        ...(canViewCompany
            ? [
                  {
                      value: 'company',
                      label: 'Company',
                      description: 'Can settle to the city ledger.',
                      icon: Building2,
                  },
              ]
            : []),
    ];

    const payerMissing =
        data.payerType === 'company'
            ? data.company === ''
            : data.payerType === 'new_guest'
              ? data.newGuestName.trim() === ''
              : data.payer === null;

    const submit = () => {
        form.transform((values) => ({
            name: values.name,
            payer_type: values.payerType,
            payer_guest_id: values.payer?.id ?? null,
            new_guest_name: values.newGuestName,
            payer_company_id: values.company || null,
            starts_on: values.startsOn,
            ends_on: values.endsOn,
            ...(isNew ? { status: values.status } : { notes: values.notes }),
        }));

        const options = {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        };

        if (isNew) {
            form.post(store.url(), options);
        } else {
            form.put(update.url({ event: event.id }), options);
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={isNew ? 'New event' : 'Edit event'}
            description={
                isNew
                    ? 'A bill for people who are not staying: a wedding dinner, a meeting or a walk-in lunch. It gets its own folio.'
                    : undefined
            }
            errors={errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel={isNew ? 'Create event' : 'Save event'}
            processing={form.processing}
            submitDisabled={
                data.name.trim() === '' ||
                payerMissing ||
                data.startsOn === '' ||
                data.endsOn === ''
            }
        >
            <Field data-invalid={!!errors.name}>
                <FieldLabel htmlFor="event-name">Event name</FieldLabel>
                <Input
                    id="event-name"
                    name="name"
                    maxLength={150}
                    placeholder="e.g. Lim & Tan wedding dinner"
                    value={data.name}
                    onChange={(change) =>
                        form.setData('name', change.target.value)
                    }
                    aria-invalid={!!errors.name}
                />
                <FieldError>{errors.name}</FieldError>
            </Field>
            {payerChoices.length > 1 && (
                <ChoiceCards
                    id="event-payer-type"
                    legend="Payer"
                    choices={payerChoices}
                    value={data.payerType}
                    onValueChange={(value) =>
                        form.setData('payerType', value as PayerType)
                    }
                />
            )}
            {data.payerType === 'company' ? (
                <Field data-invalid={!!errors.payer_company_id}>
                    <FieldLabel htmlFor="event-company">Company</FieldLabel>
                    <CompanySelect
                        id="event-company"
                        value={data.company}
                        onValueChange={(value) =>
                            form.setData('company', value)
                        }
                        companies={companies ?? []}
                        invalid={!!errors.payer_company_id}
                    />
                    <FieldError>{errors.payer_company_id}</FieldError>
                </Field>
            ) : data.payerType === 'new_guest' ? (
                <Field data-invalid={!!errors.new_guest_name}>
                    <FieldLabel htmlFor="event-new-guest">
                        Guest name
                    </FieldLabel>
                    <Input
                        id="event-new-guest"
                        name="new_guest_name"
                        maxLength={150}
                        placeholder="e.g. Lim Wei Jie"
                        value={data.newGuestName}
                        onChange={(change) =>
                            form.setData('newGuestName', change.target.value)
                        }
                        aria-invalid={!!errors.new_guest_name}
                    />
                    <FieldError>{errors.new_guest_name}</FieldError>
                </Field>
            ) : (
                <Field data-invalid={!!errors.payer_guest_id}>
                    <FieldLabel htmlFor="event-guest">Guest</FieldLabel>
                    <GuestPicker
                        id="event-guest"
                        value={data.payer}
                        onChange={(guest) => form.setData('payer', guest)}
                        invalid={!!errors.payer_guest_id}
                    />
                    <FieldError>{errors.payer_guest_id}</FieldError>
                </Field>
            )}
            <Field data-invalid={!!errors.starts_on || !!errors.ends_on}>
                <FieldLabel htmlFor="event-dates">Dates</FieldLabel>
                <DateRangePicker
                    id="event-dates"
                    from={data.startsOn}
                    to={data.endsOn}
                    onChange={(from, to) =>
                        form.setData((current) => ({
                            ...current,
                            startsOn: from,
                            endsOn: to || from,
                        }))
                    }
                    placeholder="e.g. 11/12/2026 → 12/12/2026"
                    aria-invalid={!!errors.starts_on || !!errors.ends_on}
                />
                <FieldError>{errors.starts_on ?? errors.ends_on}</FieldError>
            </Field>
            {isNew ? (
                <Field data-invalid={!!errors.status}>
                    <FieldLabel htmlFor="event-status">Status</FieldLabel>
                    <EnumSelect
                        id="event-status"
                        name="status"
                        options={statuses}
                        value={data.status}
                        onValueChange={(value) => form.setData('status', value)}
                        aria-invalid={!!errors.status}
                    />
                    <FieldError>{errors.status}</FieldError>
                </Field>
            ) : (
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
            )}
        </FormOverlay>
    );
}

/**
 * New event and Edit event. A one-day event has the same date at both ends. A walk-in is a New guest with today's
 * date. See docs/33-facilities/03-events-and-event-folios.md#new-event-dialog.
 */
export function EventFormDialog(props: Props) {
    return <EventForm key={useFreshOnOpen(props.open)} {...props} />;
}
