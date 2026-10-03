import { ChevronRight, Plus, TriangleAlert, X } from 'lucide-react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { EnumSelect } from '@/components/pms/enum-select';
import { DuplicateWarning } from '@/components/pms/guests/duplicate-warning';
import { GuestAutocompleteField } from '@/components/pms/guests/guest-autocomplete-field';
import { GuestDetailsFields } from '@/components/pms/guests/guest-details-fields';
import type { GuestDetails } from '@/components/pms/guests/guest-details-fields';
import {
    emptyGuest,
    guestLabel,
    linkGuest,
    takenBedUnits,
    unlinkGuest,
    unitsWithoutGuest,
} from '@/components/pms/reservations/booking-guests';
import type { BookingGuest } from '@/components/pms/reservations/booking-guests';
import type { UnitOption } from '@/components/pms/reservations/booking-lines';
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
import { Switch } from '@/components/ui/switch';
import type { GuestMatchCriteria } from '@/hooks/use-guest-matches';

type Errors = Record<string, string | undefined>;

const NOT_STAYING = '';

/** The columns of a guest row from `lg`: chevron, name, phone, stays in and remove. */
const GUEST_COLUMNS =
    'lg:grid-cols-[2rem_minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1.2fr)_2rem]';

/** The fields of a guest row that sit in the main row. An error elsewhere opens the row. */
const MAIN_ROW_FIELDS = ['full_name', 'phone', 'stays_in'];

function GuestsHeader() {
    return (
        <div
            className={`hidden gap-3 rounded-t-lg border-b bg-muted px-3 py-2 text-xs font-medium text-muted-foreground lg:grid ${GUEST_COLUMNS}`}
        >
            <span />
            <span>Guest</span>
            <span>Phone</span>
            <span>Stays in</span>
            <span />
        </div>
    );
}

function GuestRow({
    index,
    guest,
    guests,
    units,
    canManageGuests,
    countries,
    genders,
    locales,
    errors,
    onChange,
    onRemove,
}: {
    index: number;
    guest: BookingGuest;
    guests: BookingGuest[];
    units: UnitOption[];
    canManageGuests: boolean;
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
    errors: Errors;
    onChange: (patch: Partial<BookingGuest> | BookingGuest) => void;
    onRemove: () => void;
}) {
    const prefix = `guests.${index}.`;
    const error = (field: string) => errors[`${prefix}${field}`];
    const [checked, setChecked] = useState<GuestMatchCriteria>({});
    const [dismissed, setDismissed] = useState('');
    const [open, setOpen] = useState(true);
    const taken = takenBedUnits(guests, units, index);
    const criteria = JSON.stringify(checked);
    const hiddenError = Object.keys(errors).some(
        (key) =>
            key.startsWith(prefix) &&
            !MAIN_ROW_FIELDS.includes(key.slice(prefix.length)),
    );
    const label = guestLabel(guest, index);

    const checkForDuplicates = () => {
        if (guest.guest === null) {
            setChecked({
                name: guest.full_name,
                email: guest.email,
                phone: guest.phone,
                date_of_birth: guest.date_of_birth,
            });
        }
    };

    return (
        <GuestAutocompleteField
            idPrefix={`guest-${index}`}
            values={guest}
            linked={guest.guest}
            existingOnly={!canManageGuests}
            labelClassName={{ full_name: 'lg:sr-only', phone: 'lg:sr-only' }}
            nameBadges={
                <>
                    {index === 0 && <Badge variant="secondary">Booker</Badge>}
                    {guest.guest !== null && (
                        <Badge variant="outline">Existing</Badge>
                    )}
                    {guest.guest === null && guest.full_name.trim() !== '' && (
                        <Badge variant="outline">New</Badge>
                    )}
                    {guest.guest?.isVip && <Badge>VIP</Badge>}
                    {guest.guest?.isBlacklisted && (
                        <TriangleAlert
                            className="text-destructive"
                            role="img"
                            aria-label="Blacklisted"
                        />
                    )}
                </>
            }
            errors={{
                full_name: error('full_name'),
                phone: error('phone'),
                email: error('email'),
                guest_id: error('guest_id'),
            }}
            onChange={(field, value) => onChange({ [field]: value })}
            onPick={(picked) => {
                onChange(linkGuest(guest, picked));
            }}
            onUnlink={() => onChange(unlinkGuest(guest))}
            onLeave={checkForDuplicates}
        >
            {(parts) => (
                <Collapsible
                    open={open || hiddenError}
                    onOpenChange={setOpen}
                    className="flex flex-col"
                >
                    <div className={`grid gap-3 p-3 ${GUEST_COLUMNS}`}>
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
                        {parts.name}
                        {parts.phone}
                        <Field data-invalid={!!error('stays_in')}>
                            <FieldLabel
                                htmlFor={`guest-${index}-stays_in`}
                                className="lg:sr-only"
                            >
                                Stays in
                            </FieldLabel>
                            <EnumSelect
                                id={`guest-${index}-stays_in`}
                                placeholder={
                                    units.length === 0
                                        ? 'Add a room or bed first'
                                        : 'Select a unit'
                                }
                                options={[
                                    ...(index === 0
                                        ? [
                                              {
                                                  value: NOT_STAYING,
                                                  label: 'Not staying',
                                              },
                                          ]
                                        : []),
                                    ...units.map((unit) => ({
                                        value: unit.value,
                                        label: unit.label,
                                        disabled:
                                            unit.isBed && taken.has(unit.value),
                                    })),
                                ]}
                                value={guest.stays_in}
                                onValueChange={(value) =>
                                    onChange({ stays_in: value })
                                }
                                aria-invalid={!!error('stays_in')}
                            />
                            <FieldError>{error('stays_in')}</FieldError>
                        </Field>
                        <div className="flex items-start justify-end">
                            {index > 0 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={`Remove ${label}`}
                                    onClick={onRemove}
                                >
                                    <X />
                                </Button>
                            )}
                        </div>
                    </div>

                    <CollapsibleContent>
                        <div className="flex flex-col gap-4 px-3 pb-3 lg:px-14">
                            {parts.linked}
                            {parts.notes}

                            {guest.guest !== null && canManageGuests && (
                                <Field orientation="horizontal">
                                    <Switch
                                        id={`guest-${index}-update`}
                                        checked={guest.update_profile}
                                        onCheckedChange={(checked) =>
                                            onChange({
                                                update_profile: checked,
                                            })
                                        }
                                    />
                                    <FieldLabel
                                        htmlFor={`guest-${index}-update`}
                                    >
                                        Update guest profile with my changes
                                    </FieldLabel>
                                </Field>
                            )}
                            <FieldError>{error('update_profile')}</FieldError>

                            {!canManageGuests && (
                                <div className="max-w-sm">{parts.email}</div>
                            )}
                            {canManageGuests && (
                                <GuestDetailsFields
                                    columns={3}
                                    leading={parts.email}
                                    idPrefix={`guest-${index}-`}
                                    errorPrefix={prefix}
                                    values={guest}
                                    errors={errors}
                                    onChange={(
                                        field: keyof GuestDetails,
                                        value: string,
                                    ) => onChange({ [field]: value })}
                                    countries={countries}
                                    genders={genders}
                                    locales={locales}
                                />
                            )}

                            {guest.guest === null &&
                                canManageGuests &&
                                criteria !== dismissed && (
                                    <DuplicateWarning
                                        criteria={checked}
                                        onUse={(match) => {
                                            onChange(linkGuest(guest, match));
                                        }}
                                        onDismiss={() => setDismissed(criteria)}
                                    />
                                )}
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            )}
        </GuestAutocompleteField>
    );
}

/**
 * The Guests section: a table with one collapsible row for each guest. The booker is the first row. Name, Phone and
 * the unit the guest stays in are in the row; Email, the guest card, the profile update, the duplicate warning and the
 * guest details are in the expanded part. The footer counts the units without a guest and adds a guest. Units
 * without a guest are allowed.
 */
export function BookingGuestsSection({
    guests,
    units,
    canManageGuests,
    countries,
    genders,
    locales,
    errors,
    onChange,
}: {
    guests: BookingGuest[];
    units: UnitOption[];
    canManageGuests: boolean;
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
    errors: Errors;
    onChange: (guests: BookingGuest[]) => void;
}) {
    const empty = unitsWithoutGuest(guests, units.length);

    return (
        <div className="rounded-lg border">
            <GuestsHeader />
            <div className="divide-y">
                {guests.map((guest, index) => (
                    <GuestRow
                        key={guest.key}
                        index={index}
                        guest={guest}
                        guests={guests}
                        units={units}
                        canManageGuests={canManageGuests}
                        countries={countries}
                        genders={genders}
                        locales={locales}
                        errors={errors}
                        onChange={(patch) =>
                            onChange(
                                guests.map((one) =>
                                    one.key === guest.key
                                        ? { ...one, ...patch }
                                        : one,
                                ),
                            )
                        }
                        onRemove={() =>
                            onChange(
                                guests.filter((one) => one.key !== guest.key),
                            )
                        }
                    />
                ))}
            </div>
            <div className="flex items-center justify-between gap-3 rounded-b-lg border-t bg-muted/50 px-3 py-2">
                <FieldDescription>
                    {units.length === 0
                        ? 'Add a room or bed line first.'
                        : empty === 0
                          ? 'Every unit has a guest.'
                          : `${empty} ${empty === 1 ? 'unit' : 'units'} without a guest`}
                </FieldDescription>
                <Button
                    type="button"
                    variant="outline"
                    disabled={guests.length >= 20}
                    onClick={() => onChange([...guests, emptyGuest()])}
                >
                    <Plus data-icon="inline-start" />
                    Add guest
                </Button>
            </div>
        </div>
    );
}
