import { Link, router } from '@inertiajs/react';
import {
    BookUser,
    ChevronRight,
    IdCard,
    MoreHorizontal,
    Receipt,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { DuplicateWarning } from '@/components/pms/guests/duplicate-warning';
import { GuestAutocompleteField } from '@/components/pms/guests/guest-autocomplete-field';
import { GuestDetailsFields } from '@/components/pms/guests/guest-details-fields';
import type { GuestDetails } from '@/components/pms/guests/guest-details-fields';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    emptyGuest,
    guestsPayload,
    linkGuest,
    unlinkGuest,
} from '@/components/pms/reservations/booking-guests';
import type { BookingGuest } from '@/components/pms/reservations/booking-guests';
import {
    ATTACHABLE_STATUSES,
    OPEN_STATUSES,
    profileChanged,
    profilePayload,
} from '@/components/pms/reservations/guest-rows';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import type { GuestMatchCriteria } from '@/hooks/use-guest-matches';
import { show as showGuest } from '@/routes/guests';
import { show as showCard } from '@/routes/registration';
import {
    store as storeGuest,
    update as updateGuest,
} from '@/routes/reservations/units/guests';

type Row = App.Data.Reservations.ReservationGuestData;
type Unit = App.Data.Reservations.ReservationUnitOptionData;
type Errors = Record<string, string | undefined>;

/** The columns of a row from `lg`: chevron, guest, phone, stays in, role and the menu. */
const COLUMNS =
    'lg:grid-cols-[2rem_minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1fr)_2rem]';

/** The fields of a draft row that sit in the main row. An error elsewhere opens the row. */
const MAIN_ROW_FIELDS = ['full_name', 'phone', 'stays_in', 'form', 'guest_id'];

export function GuestsHeader() {
    return (
        <div
            className={`hidden gap-3 rounded-t-lg border-b bg-muted px-3 py-2 text-xs font-medium text-muted-foreground lg:grid ${COLUMNS}`}
        >
            <span />
            <span>Guest</span>
            <span>Phone</span>
            <span>Stays in</span>
            <span>Role</span>
            <span />
        </div>
    );
}

function RoleBadge({ role }: { role: string }) {
    if (role === 'Booker') {
        return (
            <Badge variant="secondary" className="bg-info/10 text-info">
                <BookUser aria-hidden />
                Booker
            </Badge>
        );
    }

    return (
        <Badge variant={role === 'Primary' ? 'default' : 'secondary'}>
            {role}
        </Badge>
    );
}

function Roles({ roles }: { roles: string[] }) {
    return (
        <span className="flex flex-wrap gap-1">
            {roles.length === 0 ? (
                <Badge variant="outline">Guest</Badge>
            ) : (
                roles.map((role) => <RoleBadge key={role} role={role} />)
            )}
        </span>
    );
}

/** The unit of a saved row, with the status when the unit is not open. */
function StaysIn({ row }: { row: Row }) {
    return (
        <span className="flex flex-wrap items-center gap-2">
            {row.unitLabel ?? 'Not staying'}
            {row.unitStatus &&
                !OPEN_STATUSES.includes(row.unitStatus.value) && (
                    <StatusBadge status={row.unitStatus} />
                )}
        </span>
    );
}

function RowMenu({ row, onBill }: { row: Row; onBill: (row: Row) => void }) {
    const open =
        row.unitStatus !== null && OPEN_STATUSES.includes(row.unitStatus.value);
    const hasCard = row.cardUnitGuestId !== null && open;
    const hasBill = row.canBill && row.guestId !== null;

    if (!hasCard && !hasBill) {
        return <span />;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`Actions for ${row.name}`}
                    />
                }
            >
                <MoreHorizontal />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {hasCard && row.cardUnitGuestId !== null && (
                    <DropdownMenuItem
                        render={
                            <Link
                                href={showCard.url({
                                    unitGuest: row.cardUnitGuestId,
                                })}
                            />
                        }
                    >
                        <IdCard />
                        Registration card
                    </DropdownMenuItem>
                )}
                {hasBill && (
                    <DropdownMenuItem onClick={() => onBill(row)}>
                        <Receipt />
                        Bill to this guest
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * A guest already on a unit, a unit with no guest, or the booker who does not stay. With `canEdit` the row opens to the
 * profile of the guest; the unit link never changes here. Save and Cancel show only after an edit.
 */
export function SavedGuestRow({
    rowId,
    reservationId,
    row,
    canAdd,
    canEdit,
    countries,
    genders,
    locales,
    onAdd,
    onBill,
    onDirtyChange,
}: {
    rowId: string;
    reservationId: number;
    row: Row;
    canAdd: boolean;
    canEdit: boolean;
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
    onAdd: (unitId: number) => void;
    onBill: (row: Row) => void;
    onDirtyChange: (key: string, dirty: boolean) => void;
}) {
    const [base, setBase] = useState<BookingGuest | null>(() =>
        canEdit && row.guest !== null
            ? linkGuest(emptyGuest(), row.guest)
            : null,
    );
    const [values, setValues] = useState<BookingGuest | null>(base);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const [open, setOpen] = useState(false);
    const dirty =
        values !== null && base !== null && profileChanged(values, base);
    const hiddenError = Object.keys(errors).some(
        (field) => !['full_name', 'phone'].includes(field),
    );

    useEffect(() => {
        onDirtyChange(rowId, dirty);
    }, [dirty, onDirtyChange, rowId]);

    useEffect(() => () => onDirtyChange(rowId, false), [onDirtyChange, rowId]);

    // A unit with no guest.
    if (row.name === null) {
        const canAddHere =
            canAdd &&
            row.unitId !== null &&
            row.unitStatus !== null &&
            ATTACHABLE_STATUSES.includes(row.unitStatus.value);

        return (
            <div
                className={`grid items-center gap-3 p-3 text-muted-foreground ${COLUMNS}`}
            >
                <span />
                <span>No guest yet</span>
                <span />
                <span className="text-foreground">
                    <StaysIn row={row} />
                </span>
                <span />
                <span className="flex justify-end">
                    {canAddHere && row.unitId !== null && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => onAdd(row.unitId as number)}
                        >
                            Add
                        </Button>
                    )}
                </span>
            </div>
        );
    }

    const billing = row.billing !== null && (
        <span className="block text-xs text-muted-foreground">
            {row.billing}
        </span>
    );

    // Read only: no guests.manage, no guest data, or the booker who does not stay.
    if (!canEdit || values === null || base === null) {
        return (
            <div className={`grid items-start gap-3 p-3 ${COLUMNS}`}>
                <span />
                <span>
                    {row.guestId !== null ? (
                        <Link
                            href={showGuest.url({ guest: row.guestId })}
                            className="font-medium underline-offset-4 hover:underline"
                        >
                            {row.name}
                        </Link>
                    ) : (
                        <span className="font-medium">{row.name}</span>
                    )}
                    {billing}
                </span>
                <span className="tabular-nums">{row.phone ?? ''}</span>
                <StaysIn row={row} />
                <Roles roles={row.roles} />
                <RowMenu row={row} onBill={onBill} />
            </div>
        );
    }

    const save = () => {
        if (row.unitId === null || row.guestId === null) {
            return;
        }

        router.put(
            updateGuest.url({
                reservation: reservationId,
                unit: row.unitId,
                guestId: row.guestId,
            }),
            profilePayload(values),
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (found) => setErrors(found),
                onSuccess: () => {
                    setErrors({});
                    setBase(values);
                },
            },
        );
    };

    const cancel = () => {
        setValues(base);
        setErrors({});
    };

    const idPrefix = `guest-${rowId}`;

    return (
        <GuestAutocompleteField
            idPrefix={idPrefix}
            values={values}
            linked={values.guest}
            lockLink
            labelClassName={{ full_name: 'lg:sr-only', phone: 'lg:sr-only' }}
            nameBadges={
                <>
                    {values.guest?.isVip && <Badge>VIP</Badge>}
                    {values.guest?.isBlacklisted && (
                        <TriangleAlert
                            className="text-destructive"
                            role="img"
                            aria-label="Blacklisted"
                        />
                    )}
                </>
            }
            errors={{
                full_name: errors.full_name,
                phone: errors.phone,
                email: errors.email,
            }}
            onChange={(field, value) =>
                setValues((current) =>
                    current === null ? current : { ...current, [field]: value },
                )
            }
            onPick={() => undefined}
            onUnlink={() => undefined}
        >
            {(parts) => (
                <Collapsible
                    open={open || hiddenError}
                    onOpenChange={setOpen}
                    className="flex flex-col"
                >
                    <div className={`grid items-start gap-3 p-3 ${COLUMNS}`}>
                        <CollapsibleTrigger
                            render={
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={`${open || hiddenError ? 'Hide' : 'Show'} the details of ${row.name}`}
                                    className="group/row max-lg:justify-self-start"
                                />
                            }
                        >
                            <ChevronRight className="transition-transform group-data-[panel-open]/row:rotate-90" />
                        </CollapsibleTrigger>
                        <div className="flex flex-col gap-1">
                            {parts.name}
                            {billing}
                        </div>
                        {parts.phone}
                        <span className="lg:pt-1.5">
                            <StaysIn row={row} />
                        </span>
                        <span className="lg:pt-1">
                            <Roles roles={row.roles} />
                        </span>
                        <RowMenu row={row} onBill={onBill} />
                    </div>

                    <CollapsibleContent>
                        <div className="flex flex-col gap-4 px-3 pb-3 lg:px-14">
                            {parts.linked}
                            {parts.email}
                            <GuestDetailsFields
                                columns={3}
                                idPrefix={`${idPrefix}-`}
                                values={values}
                                errors={errors}
                                onChange={(
                                    field: keyof GuestDetails,
                                    value: string,
                                ) =>
                                    setValues((current) =>
                                        current === null
                                            ? current
                                            : { ...current, [field]: value },
                                    )
                                }
                                countries={countries}
                                genders={genders}
                                locales={locales}
                            />
                            {dirty && (
                                <div className="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={cancel}
                                        disabled={processing}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={save}
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing && <Spinner />}
                                        Save
                                    </Button>
                                </div>
                            )}
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            )}
        </GuestAutocompleteField>
    );
}

/**
 * A guest being added. It opens expanded. Save creates the guest or links the picked one (with the profile update when
 * it is on) and attaches it to the unit in one request; a refusal shows inline and writes nothing.
 */
export function DraftGuestRow({
    reservationId,
    draft,
    units,
    taken,
    canManageGuests,
    countries,
    genders,
    locales,
    onChange,
    onRemove,
}: {
    reservationId: number;
    draft: BookingGuest;
    units: Unit[];
    taken: Set<number>;
    canManageGuests: boolean;
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
    onChange: (patch: Partial<BookingGuest> | BookingGuest) => void;
    onRemove: () => void;
}) {
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const [checked, setChecked] = useState<GuestMatchCriteria>({});
    const [dismissed, setDismissed] = useState('');
    const [open, setOpen] = useState(true);
    const criteria = JSON.stringify(checked);
    const hiddenError = Object.keys(errors).some(
        (field) => !MAIN_ROW_FIELDS.includes(field),
    );
    const picked = units.find((unit) => String(unit.id) === draft.stays_in);
    const label = draft.full_name.trim() || 'new guest';

    const checkForDuplicates = () => {
        if (draft.guest === null) {
            setChecked({
                name: draft.full_name,
                email: draft.email,
                phone: draft.phone,
                date_of_birth: draft.date_of_birth,
            });
        }
    };

    const save = () => {
        if (draft.stays_in === '') {
            setErrors({ stays_in: 'Choose the unit this guest stays in.' });

            return;
        }

        const {
            stays_in: _unit,
            guest_id: _id,
            ...body
        } = guestsPayload([draft])[0] as Record<string, unknown>;

        router.post(
            storeGuest.url({
                reservation: reservationId,
                unit: Number(draft.stays_in),
            }),
            { ...body, guest_id: draft.guest?.id ?? null },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (found) => setErrors(found),
                onSuccess: onRemove,
            },
        );
    };

    return (
        <GuestAutocompleteField
            idPrefix={`draft-${draft.key}`}
            values={draft}
            linked={draft.guest}
            existingOnly={!canManageGuests}
            labelClassName={{ full_name: 'lg:sr-only', phone: 'lg:sr-only' }}
            nameBadges={
                <>
                    {draft.guest !== null && (
                        <Badge variant="outline">Existing</Badge>
                    )}
                    {draft.guest === null && draft.full_name.trim() !== '' && (
                        <Badge variant="outline">New</Badge>
                    )}
                    {draft.guest?.isVip && <Badge>VIP</Badge>}
                    {draft.guest?.isBlacklisted && (
                        <TriangleAlert
                            className="text-destructive"
                            role="img"
                            aria-label="Blacklisted"
                        />
                    )}
                </>
            }
            errors={{
                full_name: errors.full_name,
                phone: errors.phone,
                email: errors.email,
                guest_id: errors.guest_id,
            }}
            onChange={(field, value) => onChange({ [field]: value })}
            onPick={(guest) => onChange(linkGuest(draft, guest))}
            onUnlink={() => onChange(unlinkGuest(draft))}
            onLeave={checkForDuplicates}
        >
            {(parts) => (
                <Collapsible
                    open={open || hiddenError}
                    onOpenChange={setOpen}
                    className="flex flex-col bg-muted/20"
                >
                    <div className={`grid items-start gap-3 p-3 ${COLUMNS}`}>
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
                        <Field
                            data-invalid={!!(errors.stays_in || errors.form)}
                        >
                            <FieldLabel
                                htmlFor={`draft-${draft.key}-stays_in`}
                                className="lg:sr-only"
                            >
                                Stays in
                            </FieldLabel>
                            <EnumSelect
                                id={`draft-${draft.key}-stays_in`}
                                placeholder="Select a unit"
                                options={units.map((unit) => ({
                                    value: String(unit.id),
                                    label:
                                        unit.status.value === 'checked_out'
                                            ? `${unit.label} · Checked out`
                                            : unit.label,
                                    disabled: unit.isBed && taken.has(unit.id),
                                }))}
                                value={draft.stays_in}
                                onValueChange={(value) =>
                                    onChange({ stays_in: value })
                                }
                                aria-invalid={
                                    !!(errors.stays_in || errors.form)
                                }
                            />
                            <FieldError>
                                {errors.stays_in ?? errors.form}
                            </FieldError>
                            {picked?.status.value === 'checked_out' && (
                                <FieldDescription>
                                    This stay has ended. Adding a guest changes
                                    no charges.
                                </FieldDescription>
                            )}
                        </Field>
                        <span />
                        <div className="flex items-start justify-end">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={`Discard ${label}`}
                                onClick={onRemove}
                            >
                                <X />
                            </Button>
                        </div>
                    </div>

                    <CollapsibleContent>
                        <div className="flex flex-col gap-4 px-3 pb-3 lg:px-14">
                            {parts.linked}
                            {parts.notes}

                            {draft.guest !== null && canManageGuests && (
                                <Field orientation="horizontal">
                                    <Switch
                                        id={`draft-${draft.key}-update`}
                                        checked={draft.update_profile}
                                        onCheckedChange={(checked) =>
                                            onChange({
                                                update_profile: checked,
                                            })
                                        }
                                    />
                                    <FieldLabel
                                        htmlFor={`draft-${draft.key}-update`}
                                    >
                                        Update guest profile with my changes
                                    </FieldLabel>
                                </Field>
                            )}
                            <FieldError>{errors.update_profile}</FieldError>

                            {!canManageGuests && (
                                <div className="max-w-sm">{parts.email}</div>
                            )}
                            {canManageGuests && (
                                <GuestDetailsFields
                                    columns={3}
                                    leading={parts.email}
                                    idPrefix={`draft-${draft.key}-`}
                                    values={draft}
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

                            {draft.guest === null &&
                                canManageGuests &&
                                criteria !== dismissed && (
                                    <DuplicateWarning
                                        criteria={checked}
                                        onUse={(match) =>
                                            onChange(linkGuest(draft, match))
                                        }
                                        onDismiss={() => setDismissed(criteria)}
                                    />
                                )}

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onRemove}
                                    disabled={processing}
                                >
                                    Discard
                                </Button>
                                <Button
                                    type="button"
                                    onClick={save}
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && <Spinner />}
                                    Save
                                </Button>
                            </div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            )}
        </GuestAutocompleteField>
    );
}
