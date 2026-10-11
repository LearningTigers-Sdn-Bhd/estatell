import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { emptyGuest } from '@/components/pms/reservations/booking-guests';
import type { BookingGuest } from '@/components/pms/reservations/booking-guests';
import {
    draftHasContent,
    MAX_DRAFTS,
    takenBedUnits,
    unitsWithoutGuest,
    ATTACHABLE_STATUSES,
} from '@/components/pms/reservations/guest-rows';
import {
    DraftGuestRow,
    GuestsHeader,
    SavedGuestRow,
} from '@/components/pms/reservations/guest-table-row';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { FieldDescription } from '@/components/ui/field';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { billToGuest } from '@/routes/reservations/units';

type Row = App.Data.Reservations.ReservationGuestData;
type Unit = App.Data.Reservations.ReservationUnitOptionData;

/** A saved row is keyed by its unit and guest. The booker who does not stay has no unit. */
function rowKey(row: Row, index: number): string {
    return `${row.unitId ?? 'none'}-${row.guestId ?? 'x'}-${row.name === null ? index : ''}`;
}

/**
 * The Guests tab: one table of guests, edited in place. A saved row shows Name, Phone, the unit and the roles, and opens
 * to edit the guest's profile. **+ Add guest** in the footer adds a draft row with the same autocomplete as the booking
 * form; Save creates or picks the guest and attaches it to the unit. Units with no guest show a muted row with Add.
 * Moving or removing a guest comes later. See docs/12-reservations/06-reservation-page.md.
 */
export function GuestsTab({
    reservationId,
    guests,
    units,
    canManage,
    canManageGuests,
    countries,
    genders,
    locales,
}: {
    reservationId: number;
    guests: Row[];
    units: Unit[];
    canManage: boolean;
    canManageGuests: boolean;
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
}) {
    const [drafts, setDrafts] = useState<BookingGuest[]>([]);
    const [dirtySaved, setDirtySaved] = useState<Set<string>>(new Set());
    const openUnits = units.filter((unit) =>
        ATTACHABLE_STATUSES.includes(unit.status.value),
    );
    const canAdd = canManage && openUnits.length > 0;
    const dirty = drafts.some(draftHasContent) || dirtySaved.size > 0;
    const guard = useUnsavedChanges(dirty);
    const empty = unitsWithoutGuest(guests, drafts);

    useEffect(() => {
        setDirtySaved(new Set());
    }, [reservationId]);

    const addDraft = (unitId: number | null = null) => {
        if (drafts.length >= MAX_DRAFTS) {
            return;
        }

        const preset =
            unitId ?? (openUnits.length === 1 ? openUnits[0].id : null);

        setDrafts((current) => [
            ...current,
            emptyGuest(preset === null ? '' : String(preset)),
        ]);
    };

    const patchDraft = (key: string, patch: Partial<BookingGuest>) =>
        setDrafts((current) =>
            current.map((draft) =>
                draft.key === key ? { ...draft, ...patch } : draft,
            ),
        );

    const removeDraft = (key: string) =>
        setDrafts((current) => current.filter((draft) => draft.key !== key));

    const markDirty = (key: string, isDirty: boolean) =>
        setDirtySaved((current) => {
            if (current.has(key) === isDirty) {
                return current;
            }

            const next = new Set(current);

            if (isDirty) {
                next.add(key);
            } else {
                next.delete(key);
            }

            return next;
        });

    const bill = (row: Row) => {
        if (row.unitId === null || row.guestId === null) {
            return;
        }

        router.post(
            billToGuest.url({ reservation: reservationId, unit: row.unitId }),
            { guest_id: row.guestId },
            { preserveScroll: true },
        );
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="rounded-lg border">
                <GuestsHeader />
                <div className="divide-y">
                    {guests.map((row, index) => {
                        const key = rowKey(row, index);

                        return (
                            <SavedGuestRow
                                key={key}
                                rowId={key}
                                reservationId={reservationId}
                                row={row}
                                canAdd={canAdd}
                                canEdit={
                                    canManage &&
                                    canManageGuests &&
                                    row.guest !== null &&
                                    row.unitId !== null &&
                                    row.guestId !== null
                                }
                                countries={countries}
                                genders={genders}
                                locales={locales}
                                onAdd={addDraft}
                                onBill={bill}
                                onDirtyChange={markDirty}
                            />
                        );
                    })}
                    {drafts.map((draft) => (
                        <DraftGuestRow
                            key={draft.key}
                            reservationId={reservationId}
                            draft={draft}
                            units={openUnits}
                            taken={takenBedUnits(
                                guests,
                                drafts,
                                units,
                                draft.key,
                            )}
                            canManageGuests={canManageGuests}
                            countries={countries}
                            genders={genders}
                            locales={locales}
                            onChange={(patch) => patchDraft(draft.key, patch)}
                            onRemove={() => removeDraft(draft.key)}
                        />
                    ))}
                </div>
                <div className="flex items-center justify-between gap-3 rounded-b-lg border-t bg-muted/50 px-3 py-2">
                    <FieldDescription>
                        {empty === 0
                            ? 'Every unit has a guest.'
                            : `${empty} ${empty === 1 ? 'unit' : 'units'} without a guest`}
                    </FieldDescription>
                    {canAdd && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={drafts.length >= MAX_DRAFTS}
                            onClick={() => addDraft()}
                        >
                            <Plus data-icon="inline-start" />
                            Add guest
                        </Button>
                    )}
                </div>
            </div>

            <AlertDialog
                open={guard.target !== null}
                onOpenChange={(open) => !open && guard.stay()}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Leave with unsaved guests?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Some guest rows are not saved. Leaving discards what
                            you typed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep editing</AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            onClick={guard.proceed}
                        >
                            Discard and leave
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
