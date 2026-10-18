import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PhoneInput } from '@/components/pms/phone-input';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { update } from '@/routes/portal/bookings/guests';

type Guest = App.Data.Portal.PortalGuestData;

/**
 * Edit the name, phone and ID of the guests of a booking. The ID number on file is never sent to the browser: leave it
 * empty to keep it, or type a new number to replace it.
 */
export function BookingGuestsSheet({
    open,
    onOpenChange,
    propertyCode,
    reservationId,
    guests,
    idTypes,
    onSaved,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    propertyCode: string;
    reservationId: number;
    guests: Guest[];
    idTypes: EnumOption[];
    /** Called after a save, so the page can open a fresh form with the new values. */
    onSaved: () => void;
}) {
    const form = useForm({
        guests: guests.map((guest) => ({
            guest_id: guest.guestId,
            full_name: guest.fullName,
            phone: guest.phone ?? '',
            id_type: guest.idType ?? '',
            id_number: '',
        })),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const setGuest = (
        index: number,
        values: Partial<(typeof form.data.guests)[number]>,
    ) => {
        form.setData(
            'guests',
            form.data.guests.map((guest, position) =>
                position === index ? { ...guest, ...values } : guest,
            ),
        );
    };

    const submit = () => {
        form.put(
            update.url({ property: propertyCode, reservation: reservationId }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    onSaved();
                },
            },
        );
    };

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={onOpenChange}
            title="Edit guests"
            description="Names, phone numbers and IDs. Dates, rooms and prices change through the property."
            errors={errors}
            dirty={form.isDirty}
            discardTitle="Discard the guest changes?"
            onSubmit={submit}
            submitLabel="Save guests"
            processing={form.processing}
        >
            {form.data.guests.map((guest, index) => (
                <FieldSet key={guest.guest_id}>
                    <FieldLegend variant="label">Guest {index + 1}</FieldLegend>
                    <FieldGroup>
                        <Field
                            data-invalid={!!errors[`guests.${index}.full_name`]}
                        >
                            <FieldLabel htmlFor={`guest-${index}-name`}>
                                Full name
                            </FieldLabel>
                            <Input
                                id={`guest-${index}-name`}
                                name={`guests.${index}.full_name`}
                                placeholder="e.g. Tan Wei Ming"
                                maxLength={150}
                                value={guest.full_name}
                                onChange={(event) =>
                                    setGuest(index, {
                                        full_name: event.target.value,
                                    })
                                }
                                aria-invalid={
                                    !!errors[`guests.${index}.full_name`]
                                }
                            />
                            <FieldError>
                                {errors[`guests.${index}.full_name`]}
                            </FieldError>
                        </Field>
                        <Field data-invalid={!!errors[`guests.${index}.phone`]}>
                            <FieldLabel htmlFor={`guest-${index}-phone`}>
                                Phone
                            </FieldLabel>
                            <PhoneInput
                                id={`guest-${index}-phone`}
                                name={`guests.${index}.phone`}
                                placeholder="e.g. +60 12-345 6789"
                                value={guest.phone}
                                onChange={(value) =>
                                    setGuest(index, { phone: value })
                                }
                                invalid={!!errors[`guests.${index}.phone`]}
                            />
                            <FieldError>
                                {errors[`guests.${index}.phone`]}
                            </FieldError>
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                data-invalid={
                                    !!errors[`guests.${index}.id_type`]
                                }
                            >
                                <FieldLabel htmlFor={`guest-${index}-id-type`}>
                                    ID type
                                </FieldLabel>
                                <EnumSelect
                                    id={`guest-${index}-id-type`}
                                    name={`guests.${index}.id_type`}
                                    options={idTypes}
                                    allLabel="No ID"
                                    value={guest.id_type}
                                    onValueChange={(value) =>
                                        setGuest(index, { id_type: value })
                                    }
                                    aria-invalid={
                                        !!errors[`guests.${index}.id_type`]
                                    }
                                />
                                <FieldError>
                                    {errors[`guests.${index}.id_type`]}
                                </FieldError>
                            </Field>
                            <Field
                                data-invalid={
                                    !!errors[`guests.${index}.id_number`]
                                }
                            >
                                <FieldLabel
                                    htmlFor={`guest-${index}-id-number`}
                                >
                                    ID number
                                </FieldLabel>
                                <Input
                                    id={`guest-${index}-id-number`}
                                    name={`guests.${index}.id_number`}
                                    placeholder={
                                        guests[index].idType
                                            ? 'On file. Type a new number to replace it'
                                            : 'e.g. 900101-14-1234'
                                    }
                                    maxLength={40}
                                    autoComplete="off"
                                    value={guest.id_number}
                                    onChange={(event) =>
                                        setGuest(index, {
                                            id_number: event.target.value,
                                        })
                                    }
                                    aria-invalid={
                                        !!errors[`guests.${index}.id_number`]
                                    }
                                />
                                <FieldError>
                                    {errors[`guests.${index}.id_number`]}
                                </FieldError>
                            </Field>
                        </div>
                    </FieldGroup>
                </FieldSet>
            ))}
        </FormOverlay>
    );
}
