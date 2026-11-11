import { router } from '@inertiajs/react';
import { ChevronRight, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    ID_GROUP,
    ID_TYPE_SELECT,
} from '@/components/pms/guest-identity-fields';
import {
    EMPTY_GUEST_DETAILS,
    GuestDetailsFields,
} from '@/components/pms/guests/guest-details-fields';
import type { GuestDetails } from '@/components/pms/guests/guest-details-fields';
import { PhoneInput } from '@/components/pms/phone-input';
import type { GuestRow } from '@/components/pms/portal/booking-guest-rows';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { update } from '@/routes/portal/bookings/guests';
import { store } from '@/routes/portal/bookings/units/guests';

type Booking = App.Data.Portal.PortalBookingData;
type Guest = App.Data.Portal.PortalGuestData;
type Errors = Record<string, string | undefined>;

type Values = GuestDetails & {
    full_name: string;
    phone: string;
    email: string;
    id_type: string;
    id_number: string;
};

/** The options of the guest fields, sent with the Guests tab. */
export type GuestOptions = {
    idTypes: EnumOption[];
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
};

const EMPTY_VALUES: Values = {
    ...EMPTY_GUEST_DETAILS,
    full_name: '',
    phone: '',
    email: '',
    id_type: '',
    id_number: '',
};

function valuesOf(guest: Guest): Values {
    return {
        full_name: guest.fullName,
        phone: guest.phone ?? '',
        email: guest.email ?? '',
        nationality_code: guest.nationalityCode ?? '',
        date_of_birth: guest.dateOfBirth ?? '',
        gender: guest.gender ?? '',
        preferred_locale: guest.preferredLocale ?? '',
        address_line1: guest.addressLine1 ?? '',
        address_line2: guest.addressLine2 ?? '',
        city: guest.city ?? '',
        state: guest.state ?? '',
        postal_code: guest.postalCode ?? '',
        country_code: guest.countryCode ?? '',
        id_type: guest.idType ?? '',
        id_number: '',
    };
}

/** The fields that sit in the main row. An error elsewhere opens the row. */
const MAIN_ROW_FIELDS = ['full_name', 'phone', 'unit'];

function differs(values: Values, base: Values): boolean {
    return (Object.keys(values) as (keyof Values)[]).some(
        (field) => values[field] !== base[field],
    );
}

/** Errors of the Edit guests endpoint come as `guests.0.{field}`, for the one guest sent. */
function unprefixed(found: Errors): Errors {
    return Object.fromEntries(
        Object.entries(found).map(([field, message]) => [
            field.replace(/^guests\.0\./, ''),
            message,
        ]),
    );
}

function Chevron({ name, open }: { name: string; open: boolean }) {
    return (
        <CollapsibleTrigger
            render={
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`${open ? 'Hide' : 'Show'} the details of ${name}`}
                    className="group/row"
                />
            }
        >
            <ChevronRight className="transition-transform group-data-[panel-open]/row:rotate-90" />
        </CollapsibleTrigger>
    );
}

/** Name and Phone of the main row. Their labels show only below `lg`, where the table header is hidden. */
function MainFields({
    idPrefix,
    values,
    errors,
    onChange,
}: {
    idPrefix: string;
    values: Values;
    errors: Errors;
    onChange: (patch: Partial<Values>) => void;
}) {
    return (
        <>
            <Field data-invalid={!!errors.full_name}>
                <FieldLabel htmlFor={`${idPrefix}-name`} className="lg:sr-only">
                    Full name
                </FieldLabel>
                <Input
                    id={`${idPrefix}-name`}
                    name="full_name"
                    placeholder="e.g. Tan Wei Ming"
                    maxLength={150}
                    value={values.full_name}
                    onChange={(event) =>
                        onChange({ full_name: event.target.value })
                    }
                    aria-invalid={!!errors.full_name}
                />
                <FieldError>{errors.full_name}</FieldError>
            </Field>
            <Field data-invalid={!!errors.phone}>
                <FieldLabel
                    htmlFor={`${idPrefix}-phone`}
                    className="lg:sr-only"
                >
                    Phone
                </FieldLabel>
                <PhoneInput
                    id={`${idPrefix}-phone`}
                    name="phone"
                    placeholder="e.g. 012-345 6789"
                    value={values.phone}
                    onChange={(value) => onChange({ phone: value })}
                    invalid={!!errors.phone}
                />
                <FieldError>{errors.phone}</FieldError>
            </Field>
        </>
    );
}

/**
 * The opened part of a guest row: Email and IC / Passport, then the personal details and the address, the same fields
 * as the portal booking form.
 */
function DetailFields({
    idPrefix,
    values,
    errors,
    hasIdOnFile,
    options,
    onChange,
}: {
    idPrefix: string;
    values: Values;
    errors: Errors;
    hasIdOnFile: boolean;
    options: GuestOptions;
    onChange: (patch: Partial<Values>) => void;
}) {
    const idError = errors.id_number ?? errors.id_type;

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field data-invalid={!!errors.email}>
                    <FieldLabel htmlFor={`${idPrefix}-email`}>Email</FieldLabel>
                    <Input
                        id={`${idPrefix}-email`}
                        name="email"
                        type="email"
                        placeholder="e.g. name@example.com"
                        maxLength={255}
                        value={values.email}
                        onChange={(event) =>
                            onChange({ email: event.target.value })
                        }
                        aria-invalid={!!errors.email}
                    />
                    <FieldError>{errors.email}</FieldError>
                </Field>
                <Field data-invalid={!!idError}>
                    <FieldLabel htmlFor={`${idPrefix}-id-number`}>
                        IC / Passport number
                    </FieldLabel>
                    <InputGroup className={ID_GROUP}>
                        <InputGroupAddon className="border-r p-0">
                            <EnumSelect
                                id={`${idPrefix}-id-type`}
                                name="id_type"
                                aria-label="Identity document type"
                                options={options.idTypes}
                                allLabel="No ID"
                                value={values.id_type}
                                onValueChange={(value) =>
                                    onChange({ id_type: value })
                                }
                                className={ID_TYPE_SELECT}
                            />
                        </InputGroupAddon>
                        <InputGroupInput
                            id={`${idPrefix}-id-number`}
                            name="id_number"
                            placeholder={
                                hasIdOnFile
                                    ? 'On file. Type a new number to replace it'
                                    : 'e.g. 900101-14-1234'
                            }
                            maxLength={40}
                            autoComplete="off"
                            value={values.id_number}
                            onChange={(event) =>
                                onChange({ id_number: event.target.value })
                            }
                            aria-invalid={!!idError}
                        />
                    </InputGroup>
                    <FieldError>{idError}</FieldError>
                </Field>
            </div>
            <GuestDetailsFields
                columns={2}
                idPrefix={`${idPrefix}-`}
                values={values}
                errors={errors}
                onChange={(field, value) => onChange({ [field]: value })}
                countries={options.countries}
                genders={options.genders}
                locales={options.locales}
            />
        </>
    );
}

function SaveBar({
    processing,
    cancelLabel,
    saveLabel,
    onCancel,
    onSave,
}: {
    processing: boolean;
    cancelLabel: string;
    saveLabel: string;
    onCancel: () => void;
    onSave: () => void;
}) {
    return (
        <div className="flex justify-end gap-2">
            <Button
                type="button"
                variant="outline"
                onClick={onCancel}
                disabled={processing}
            >
                {cancelLabel}
            </Button>
            <Button
                type="button"
                onClick={onSave}
                disabled={processing}
                focusableWhenDisabled
            >
                {processing && <Spinner />}
                {saveLabel}
            </Button>
        </div>
    );
}

/**
 * One guest of a portal booking. The main row holds the name and phone, and the row opens for the email, ID, personal
 * details and address. Once something changed the row stays open and shows Save and Cancel; Save sends only this
 * guest, and an emptied field is cleared (an empty nationality keeps the saved one). The ID number on file is never
 * sent to the browser: leave it empty to keep it, or type a new number to replace it. Read only when the booking can no
 * longer change.
 */
export function BookingGuestRow({
    rowKey,
    row,
    columns,
    showUnit,
    canEdit,
    propertyCode,
    reservationId,
    options,
    onDirtyChange,
}: {
    rowKey: string;
    row: GuestRow;
    /** The grid columns from `lg`, shared with the header. */
    columns: string;
    /** False in the Grouped view, where the rail names the unit. */
    showUnit: boolean;
    canEdit: boolean;
    propertyCode: string;
    reservationId: number;
    options: GuestOptions;
    onDirtyChange: (key: string, dirty: boolean) => void;
}) {
    const { guest } = row;
    const [base, setBase] = useState<Values>(() => valuesOf(guest));
    const [values, setValues] = useState<Values>(base);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const [open, setOpen] = useState(false);
    const dirty = canEdit && differs(values, base);
    const hiddenError = Object.keys(errors).some(
        (field) => !MAIN_ROW_FIELDS.includes(field),
    );
    const expanded = open || hiddenError || dirty;
    const idPrefix = `guest-${rowKey}`;
    const label = (list: EnumOption[], value: string | null) =>
        value
            ? (list.find((option) => option.value === value)?.label ?? value)
            : null;
    const set = (patch: Partial<Values>) =>
        setValues((current) => ({ ...current, ...patch }));

    useEffect(() => {
        onDirtyChange(rowKey, dirty);
    }, [dirty, onDirtyChange, rowKey]);

    useEffect(
        () => () => onDirtyChange(rowKey, false),
        [onDirtyChange, rowKey],
    );

    const save = () => {
        router.put(
            update.url({ property: propertyCode, reservation: reservationId }),
            { guests: [{ guest_id: guest.guestId, ...values }] },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (found) => setErrors(unprefixed(found)),
                onSuccess: () => {
                    const saved = { ...values, id_number: '' };

                    setErrors({});
                    setBase(saved);
                    setValues(saved);
                },
            },
        );
    };

    const cancel = () => {
        setValues(base);
        setErrors({});
    };

    const stays = showUnit && (
        <span
            className={cn(
                'lg:pt-1.5',
                row.unit === null && 'text-muted-foreground',
            )}
        >
            {row.unit ?? 'Booker, not staying'}
        </span>
    );

    if (!canEdit) {
        const details: [string, string | null][] = [
            ['Email', guest.email],
            ['Nationality', label(options.countries, guest.nationalityCode)],
            ['ID', guest.idTypeLabel && `${guest.idTypeLabel} on file`],
            ['Date of birth', guest.dateOfBirth],
            ['Gender', label(options.genders, guest.gender)],
            [
                'Preferred language',
                label(options.locales, guest.preferredLocale),
            ],
            [
                'Address',
                [
                    guest.addressLine1,
                    guest.addressLine2,
                    guest.postalCode,
                    guest.city,
                    guest.state,
                    label(options.countries, guest.countryCode),
                ]
                    .filter(Boolean)
                    .join(', ') || null,
            ],
        ];

        return (
            <Collapsible
                open={open}
                onOpenChange={setOpen}
                className="flex flex-col"
            >
                <div className={cn('grid items-start gap-3 p-3', columns)}>
                    <Chevron name={guest.fullName} open={open} />
                    <span className="truncate font-medium lg:pt-1.5">
                        {guest.fullName}
                    </span>
                    <span className="tabular-nums lg:pt-1.5">
                        {guest.phone ?? ''}
                    </span>
                    {stays}
                </div>
                <CollapsibleContent>
                    <dl className="grid gap-4 px-3 pb-3 sm:grid-cols-2 lg:px-14">
                        {details.map(([term, value]) => (
                            <div key={term} className="flex flex-col gap-1">
                                <dt className="text-muted-foreground">
                                    {term}
                                </dt>
                                <dd>{value || '—'}</dd>
                            </div>
                        ))}
                    </dl>
                </CollapsibleContent>
            </Collapsible>
        );
    }

    return (
        <Collapsible
            open={expanded}
            onOpenChange={setOpen}
            className="flex flex-col"
        >
            <div className={cn('grid items-start gap-3 p-3', columns)}>
                <Chevron name={guest.fullName} open={expanded} />
                <MainFields
                    idPrefix={idPrefix}
                    values={values}
                    errors={errors}
                    onChange={set}
                />
                {stays}
            </div>

            <CollapsibleContent>
                <div className="flex flex-col gap-4 px-3 pb-3 lg:px-14">
                    <DetailFields
                        idPrefix={idPrefix}
                        values={values}
                        errors={errors}
                        hasIdOnFile={guest.idType !== null}
                        options={options}
                        onChange={set}
                    />
                    {dirty && (
                        <SaveBar
                            processing={processing}
                            cancelLabel="Cancel"
                            saveLabel="Save"
                            onCancel={cancel}
                            onSave={save}
                        />
                    )}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

/**
 * A sharer being added to a unit. It opens expanded. Save creates a new guest and puts them on the unit; a refusal
 * (the unit filled up, the booking can no longer change) shows a toast and keeps what was typed. The ✕ drops the row.
 */
export function BookingDraftGuestRow({
    draftKey,
    unitId,
    units,
    guestCount,
    columns,
    showUnit,
    propertyCode,
    reservationId,
    options,
    onUnitChange,
    onRemove,
    onSaved,
    onDirtyChange,
}: {
    draftKey: string;
    /** The unit the sharer goes to, empty until one is picked. */
    unitId: string;
    /** The units that take another guest, for Stays in. */
    units: EnumOption[];
    /** The guests of the picked unit now, to tell a save from a refusal. */
    guestCount: number;
    columns: string;
    showUnit: boolean;
    propertyCode: string;
    reservationId: number;
    options: GuestOptions;
    onUnitChange: (unitId: string) => void;
    onRemove: () => void;
    onSaved: () => void;
    onDirtyChange: (key: string, dirty: boolean) => void;
}) {
    const [values, setValues] = useState<Values>(EMPTY_VALUES);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const idPrefix = `draft-${draftKey}`;
    const dirty = differs(values, EMPTY_VALUES);
    const set = (patch: Partial<Values>) =>
        setValues((current) => ({ ...current, ...patch }));

    useEffect(() => {
        onDirtyChange(draftKey, dirty);
    }, [dirty, onDirtyChange, draftKey]);

    useEffect(
        () => () => onDirtyChange(draftKey, false),
        [onDirtyChange, draftKey],
    );

    const save = () => {
        if (unitId === '') {
            setErrors({ unit: 'Choose the room this guest stays in.' });

            return;
        }

        router.post(
            store.url({
                property: propertyCode,
                reservation: reservationId,
                unit: Number(unitId),
            }),
            values,
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (found) => setErrors(found),
                onSuccess: (page) => {
                    const booking = page.props.booking as Booking;
                    const unit = booking.units.find(
                        (one) => one.id === Number(unitId),
                    );

                    setErrors({});
                    if (
                        unit !== undefined &&
                        unit.guestIds.length > guestCount
                    ) {
                        onSaved();
                    }
                },
            },
        );
    };

    return (
        <Collapsible open className="flex flex-col">
            <div className={cn('grid items-start gap-3 p-3', columns)}>
                <span />
                <MainFields
                    idPrefix={idPrefix}
                    values={values}
                    errors={errors}
                    onChange={set}
                />
                {showUnit && (
                    <Field data-invalid={!!errors.unit}>
                        <FieldLabel
                            htmlFor={`${idPrefix}-unit`}
                            className="lg:sr-only"
                        >
                            Stays in
                        </FieldLabel>
                        <EnumSelect
                            id={`${idPrefix}-unit`}
                            name="unit"
                            options={units}
                            placeholder="Choose room"
                            value={unitId}
                            onValueChange={(value) => {
                                onUnitChange(value);
                                setErrors(({ unit: _gone, ...rest }) => rest);
                            }}
                            aria-invalid={!!errors.unit}
                        />
                        <FieldError>{errors.unit}</FieldError>
                    </Field>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Remove this new guest"
                    onClick={onRemove}
                    disabled={processing}
                >
                    <X />
                </Button>
            </div>

            <CollapsibleContent>
                <div className="flex flex-col gap-4 px-3 pb-3 lg:px-14">
                    <DetailFields
                        idPrefix={idPrefix}
                        values={values}
                        errors={errors}
                        hasIdOnFile={false}
                        options={options}
                        onChange={set}
                    />
                    <SaveBar
                        processing={processing}
                        cancelLabel="Remove"
                        saveLabel="Add guest"
                        onCancel={onRemove}
                        onSave={save}
                    />
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
