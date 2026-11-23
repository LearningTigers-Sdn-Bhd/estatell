import { Building2, User } from 'lucide-react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { CompanySelect } from '@/components/pms/company-select';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { GuestPicker } from '@/components/pms/guest-picker';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

type Guest = App.Data.Guests.GuestOptionData;
type Company = App.Data.CityLedger.CompanyOptionData;

export type PayerType = 'guest' | 'company';

/** The fields of the Edit event Dialog. */
export type EventFieldValues = {
    name: string;
    payerType: PayerType;
    payer: Guest | null;
    company: string;
    startsOn: string;
    endsOn: string;
};

/** True while the chosen payer has no guest, name or company yet. */
export function payerMissing(values: EventFieldValues): boolean {
    return values.payerType === 'company'
        ? values.company === ''
        : values.payer === null;
}

/** The shared fields in the shape `EventRequest` takes. */
export function eventFieldPayload(values: EventFieldValues) {
    return {
        name: values.name,
        payer_type: values.payerType,
        payer_guest_id: values.payer?.id ?? null,
        payer_company_id: values.company || null,
        starts_on: values.startsOn,
        ends_on: values.endsOn,
    };
}

type Props = {
    values: EventFieldValues;
    onChange: (changes: Partial<EventFieldValues>) => void;
    errors: Partial<Record<string, string>>;
    canViewCompany: boolean;
    companies?: Company[];
};

/**
 * Event name, Payer and Dates of the Edit event Dialog. A one-day event has the same date at both ends. The New event
 * page lays out its own fields. See docs/33-facilities/03-events-and-event-folios.md#edit-event-dialog.
 */
export function EventFields({
    values,
    onChange,
    errors,
    canViewCompany,
    companies,
}: Props) {
    const payerChoices = [
        {
            value: 'guest',
            label: 'Guest',
            description: 'A guest already in the guest list.',
            icon: User,
        },
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

    return (
        <>
            <Field data-invalid={!!errors.name}>
                <FieldLabel htmlFor="event-name">Event name</FieldLabel>
                <Input
                    id="event-name"
                    name="name"
                    maxLength={150}
                    placeholder="e.g. Lim & Tan wedding dinner"
                    value={values.name}
                    onChange={(change) =>
                        onChange({ name: change.target.value })
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
                    value={values.payerType}
                    onValueChange={(value) =>
                        onChange({ payerType: value as PayerType })
                    }
                />
            )}
            {values.payerType === 'company' ? (
                <Field data-invalid={!!errors.payer_company_id}>
                    <FieldLabel htmlFor="event-company">Company</FieldLabel>
                    <CompanySelect
                        id="event-company"
                        value={values.company}
                        onValueChange={(value) => onChange({ company: value })}
                        companies={companies ?? []}
                        invalid={!!errors.payer_company_id}
                    />
                    <FieldError>{errors.payer_company_id}</FieldError>
                </Field>
            ) : (
                <Field data-invalid={!!errors.payer_guest_id}>
                    <FieldLabel htmlFor="event-guest">Guest</FieldLabel>
                    <GuestPicker
                        id="event-guest"
                        value={values.payer}
                        onChange={(guest) => onChange({ payer: guest })}
                        invalid={!!errors.payer_guest_id}
                    />
                    <FieldError>{errors.payer_guest_id}</FieldError>
                </Field>
            )}
            <Field data-invalid={!!errors.starts_on || !!errors.ends_on}>
                <FieldLabel htmlFor="event-dates">Dates</FieldLabel>
                <DateRangePicker
                    id="event-dates"
                    from={values.startsOn}
                    to={values.endsOn}
                    onChange={(from, to) =>
                        onChange({ startsOn: from, endsOn: to || from })
                    }
                    placeholder="e.g. 11/12/2026 → 12/12/2026"
                    aria-invalid={!!errors.starts_on || !!errors.ends_on}
                />
                <FieldError>{errors.starts_on ?? errors.ends_on}</FieldError>
            </Field>
        </>
    );
}
