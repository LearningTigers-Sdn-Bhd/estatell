import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { GuestDetailsFields } from '@/components/pms/guests/guest-details-fields';
import { PhoneInput } from '@/components/pms/phone-input';
import { emptyGuest } from '@/components/pms/portal/book-lines';
import type { BookGuest, UnitSlot } from '@/components/pms/portal/book-lines';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';

type Errors = Record<string, string | undefined>;

/** The columns of a guest row from `lg`: chevron, name, phone and stays in. It follows the staff guests table. */
const GUEST_COLUMNS =
    'lg:grid-cols-[2rem_minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)]';

function GuestRow({
    index,
    slot,
    guest,
    idTypes,
    countries,
    genders,
    locales,
    errors,
    onChange,
}: {
    index: number;
    slot: UnitSlot;
    guest: BookGuest;
    idTypes: EnumOption[];
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
    errors: Errors;
    onChange: (patch: Partial<BookGuest>) => void;
}) {
    const error = (field: string) => errors[`guests.${index}.${field}`];
    const [open, setOpen] = useState(index === 0);
    const hiddenError = Object.keys(errors).some(
        (key) =>
            key.startsWith(`guests.${index}.`) &&
            !key.endsWith('.full_name') &&
            !key.endsWith('.phone'),
    );
    const label = guest.full_name.trim() || slot.label;

    return (
        <Collapsible
            open={open || hiddenError}
            onOpenChange={setOpen}
            className="flex flex-col"
        >
            <div className={`grid items-start gap-3 p-3 ${GUEST_COLUMNS}`}>
                <CollapsibleTrigger
                    render={
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-label={`${open || hiddenError ? 'Hide' : 'Show'} the details of ${label}`}
                            className="group/row max-lg:justify-self-start"
                        />
                    }
                >
                    <ChevronRight className="transition-transform group-data-[panel-open]/row:rotate-90" />
                </CollapsibleTrigger>
                <Field data-invalid={!!error('full_name')}>
                    <FieldLabel
                        htmlFor={`guest-${index}-full_name`}
                        className="lg:sr-only"
                    >
                        Name
                        {index === 0 && (
                            <Badge variant="secondary">Booker</Badge>
                        )}
                    </FieldLabel>
                    <div className="flex items-center gap-2">
                        <Input
                            id={`guest-${index}-full_name`}
                            placeholder="e.g. Tan Wei Ming"
                            maxLength={150}
                            autoComplete="off"
                            value={guest.full_name}
                            onChange={(event) =>
                                onChange({ full_name: event.target.value })
                            }
                            aria-invalid={!!error('full_name')}
                        />
                        {index === 0 && (
                            <Badge
                                variant="secondary"
                                className="max-lg:hidden"
                            >
                                Booker
                            </Badge>
                        )}
                    </div>
                    <FieldError>{error('full_name')}</FieldError>
                </Field>
                <Field data-invalid={!!error('phone')}>
                    <FieldLabel
                        htmlFor={`guest-${index}-phone`}
                        className="lg:sr-only"
                    >
                        Phone (optional)
                    </FieldLabel>
                    <PhoneInput
                        id={`guest-${index}-phone`}
                        placeholder="e.g. +60 12-345 6789"
                        value={guest.phone}
                        onChange={(value) => onChange({ phone: value })}
                        invalid={!!error('phone')}
                    />
                    <FieldError>{error('phone')}</FieldError>
                </Field>
                <span className="text-sm lg:pt-2">
                    <span className="text-muted-foreground lg:sr-only">
                        Stays in{' '}
                    </span>
                    {slot.label}
                </span>
            </div>

            <CollapsibleContent>
                <div className="flex flex-col gap-4 px-3 pb-3 lg:px-14">
                    <GuestDetailsFields
                        columns={3}
                        idPrefix={`guest-${index}-`}
                        errorPrefix={`guests.${index}.`}
                        values={guest}
                        errors={errors}
                        onChange={(field, value) =>
                            onChange({ [field]: value })
                        }
                        countries={countries}
                        genders={genders}
                        locales={locales}
                        leading={
                            <Field data-invalid={!!error('email')}>
                                <FieldLabel htmlFor={`guest-${index}-email`}>
                                    Email (optional)
                                </FieldLabel>
                                <Input
                                    id={`guest-${index}-email`}
                                    type="email"
                                    placeholder="e.g. tan@example.com"
                                    maxLength={255}
                                    autoComplete="off"
                                    value={guest.email}
                                    onChange={(event) =>
                                        onChange({ email: event.target.value })
                                    }
                                    aria-invalid={!!error('email')}
                                />
                                <FieldError>{error('email')}</FieldError>
                            </Field>
                        }
                    />
                    <div className="grid gap-3 sm:grid-cols-[12rem_minmax(0,16rem)]">
                        <Field data-invalid={!!error('id_type')}>
                            <FieldLabel htmlFor={`guest-${index}-id_type`}>
                                ID (optional)
                            </FieldLabel>
                            <EnumSelect
                                id={`guest-${index}-id_type`}
                                placeholder="Choose a kind of ID"
                                options={idTypes}
                                value={guest.id_type}
                                onValueChange={(value) =>
                                    onChange({ id_type: value })
                                }
                                aria-invalid={!!error('id_type')}
                            />
                            <FieldError>{error('id_type')}</FieldError>
                        </Field>
                        <Field data-invalid={!!error('id_number')}>
                            <FieldLabel htmlFor={`guest-${index}-id_number`}>
                                ID number
                            </FieldLabel>
                            <Input
                                id={`guest-${index}-id_number`}
                                placeholder={
                                    guest.id_type === 'mykad'
                                        ? 'e.g. 900101-14-5678'
                                        : 'e.g. A12345678'
                                }
                                maxLength={40}
                                autoComplete="off"
                                value={guest.id_number}
                                onChange={(event) =>
                                    onChange({ id_number: event.target.value })
                                }
                                aria-invalid={!!error('id_number')}
                            />
                            <FieldError>{error('id_number')}</FieldError>
                        </Field>
                    </div>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

/**
 * The Guests table of the book page: one collapsible row for each unit (each room, each dorm bed), in line order, like
 * the staff guests table. Name and Phone are plain inputs: the portal never searches existing guests. The expanded
 * part takes an optional ID. The booker (first row) starts open, and a row with an ID error opens itself.
 */
export function BookGuestsTable({
    slots,
    guests,
    idTypes,
    countries,
    genders,
    locales,
    errors,
    onChange,
}: {
    slots: UnitSlot[];
    guests: Record<string, BookGuest>;
    idTypes: EnumOption[];
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
    errors: Errors;
    onChange: (key: string, patch: Partial<BookGuest>) => void;
}) {
    const unnamed = slots.filter(
        (slot) => (guests[slot.key]?.full_name ?? '').trim() === '',
    ).length;

    return (
        <div className="rounded-lg border">
            <div
                className={`hidden gap-3 rounded-t-lg border-b bg-muted px-3 py-2 text-xs font-medium text-muted-foreground lg:grid ${GUEST_COLUMNS}`}
            >
                <span />
                <span>Name</span>
                <span>Phone (optional)</span>
                <span>Stays in</span>
            </div>
            <div className="divide-y">
                {slots.map((slot, index) => (
                    <GuestRow
                        key={slot.key}
                        index={index}
                        slot={slot}
                        guest={guests[slot.key] ?? emptyGuest()}
                        idTypes={idTypes}
                        countries={countries}
                        genders={genders}
                        locales={locales}
                        errors={errors}
                        onChange={(patch) => onChange(slot.key, patch)}
                    />
                ))}
            </div>
            <div className="rounded-b-lg border-t bg-muted/50 px-3 py-2">
                <FieldDescription>
                    {slots.length === 0
                        ? 'Pick a room type first.'
                        : unnamed === 0
                          ? 'Every room has a name.'
                          : `${unnamed} ${unnamed === 1 ? 'room' : 'rooms'} without a name`}
                </FieldDescription>
            </div>
        </div>
    );
}
