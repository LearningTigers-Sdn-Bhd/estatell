import { Plus } from 'lucide-react';
import { useCallback, useState } from 'react';
import {
    BookingDraftGuestRow,
    BookingGuestRow,
} from '@/components/pms/portal/booking-guest-row';
import type { GuestOptions } from '@/components/pms/portal/booking-guest-row';
import {
    NOT_STAYING_KEY,
    guestRows,
    unitLabels,
} from '@/components/pms/portal/booking-guest-rows';
import { SummaryToggle } from '@/components/pms/summary-toggle';
import type { ViewChoice } from '@/components/pms/use-view-choice';
import { ViewChoiceToggle } from '@/components/pms/view-choice-toggle';
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
import { cn } from '@/lib/utils';

type Booking = App.Data.Portal.PortalBookingData;

/** The columns of a row from `lg`: chevron, guest, phone, stays in and the ✕ of a new guest. */
const GUEST_COLUMNS =
    'lg:grid-cols-[2rem_minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1.2fr)_2rem]';

/** The same without Stays in, for the Grouped view where the rail names the unit. */
const GUEST_COLUMNS_NO_UNIT =
    'lg:grid-cols-[2rem_minmax(0,1.4fr)_minmax(0,1fr)_2rem]';

/** New guests open at once, up to this many. */
const MAX_DRAFTS = 10;

type Draft = { key: string; unitId: string; home: string };

/**
 * The Guests tab of a portal booking, in the layout of the staff Guests tab: one table of guests, edited in place.
 * The main row holds the name and phone; the row opens for the email, ID, personal details and address. Each row saves
 * on its own through the Edit guests endpoint, with only that guest. **+ Add guest** adds a sharer row for a unit with
 * room (the server sends `canAddGuest`); in Grouped view it goes to the picked unit. With 2 or more units the All /
 * Grouped toggle shows, and Grouped puts the units in the rail. Read only when the booking can no longer change. See
 * docs/23-company-portal/01-company-portal.md#bookings.
 */
export function BookingGuestsTab({
    booking,
    propertyCode,
    options,
    view,
    onViewChange,
    activeGroup,
    summaryHidden,
    onToggleSummary,
}: {
    booking: Booking;
    propertyCode: string;
    options: GuestOptions;
    /** All, or Grouped with the unit rail. Grouped needs 2 or more units. */
    view: ViewChoice;
    onViewChange: (view: ViewChoice) => void;
    /** The unit picked in the rail (`none` for Not staying). */
    activeGroup: string;
    summaryHidden: boolean;
    onToggleSummary: () => void;
}) {
    const [dirtyRows, setDirtyRows] = useState<Set<string>>(new Set());
    const guard = useUnsavedChanges(dirtyRows.size > 0);
    const grouped = view === 'grouped' && booking.units.length > 1;
    const showUnit = !grouped || activeGroup === NOT_STAYING_KEY;
    const rows = guestRows(booking).filter(
        (row) => !grouped || row.group === activeGroup,
    );
    const columns = showUnit ? GUEST_COLUMNS : GUEST_COLUMNS_NO_UNIT;
    const [drafts, setDrafts] = useState<Draft[]>([]);
    const labels = unitLabels(booking);
    const addable = booking.units.filter((unit) => unit.canAddGuest);
    const unitOptions = addable.map((unit) => ({
        value: String(unit.id),
        label: labels.get(unit.id) ?? unit.roomType,
    }));
    const activeUnit = grouped
        ? booking.units.find((unit) => String(unit.id) === activeGroup)
        : undefined;
    const canAdd =
        booking.canEditGuests &&
        drafts.length < MAX_DRAFTS &&
        (grouped ? activeUnit?.canAddGuest === true : addable.length > 0);
    const shownDrafts = grouped
        ? drafts.filter((draft) => draft.home === activeGroup)
        : drafts;
    const inUnit = grouped ? rows.length : 0;

    const addDraft = () => {
        const unitId = grouped
            ? activeGroup
            : addable.length === 1
              ? String(addable[0].id)
              : '';

        setDrafts((current) => [
            ...current,
            {
                key: `${Date.now()}-${current.length}`,
                unitId,
                home: grouped ? activeGroup : 'all',
            },
        ]);
    };

    const patchDraft = (key: string, unitId: string) =>
        setDrafts((current) =>
            current.map((draft) =>
                draft.key === key ? { ...draft, unitId } : draft,
            ),
        );

    const removeDraft = (key: string) =>
        setDrafts((current) => current.filter((draft) => draft.key !== key));

    const markDirty = useCallback((key: string, isDirty: boolean) => {
        setDirtyRows((current) => {
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
    }, []);

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between gap-3">
                {booking.units.length > 1 ? (
                    <ViewChoiceToggle
                        view={view}
                        onViewChange={onViewChange}
                        allLabel="All guests"
                    />
                ) : (
                    <span />
                )}
                <SummaryToggle
                    hidden={summaryHidden}
                    onToggle={onToggleSummary}
                />
            </div>

            <div className="rounded-lg border text-sm">
                <div
                    className={cn(
                        'hidden gap-3 rounded-t-lg border-b bg-muted px-3 py-2 text-xs font-medium text-muted-foreground lg:grid',
                        columns,
                    )}
                >
                    <span />
                    <span>Guest</span>
                    <span>Phone</span>
                    {showUnit && <span>Stays in</span>}
                    <span />
                </div>
                <div className="divide-y">
                    {rows.length === 0 && shownDrafts.length === 0 && (
                        <div
                            className={cn(
                                'grid items-center gap-3 p-3 text-muted-foreground',
                                columns,
                            )}
                        >
                            <span />
                            <span>No guest yet</span>
                        </div>
                    )}
                    {rows.map((row) => (
                        <BookingGuestRow
                            key={row.key}
                            rowKey={row.key}
                            row={row}
                            columns={columns}
                            showUnit={showUnit}
                            canEdit={booking.canEditGuests}
                            propertyCode={propertyCode}
                            reservationId={booking.id}
                            options={options}
                            onDirtyChange={markDirty}
                        />
                    ))}
                    {shownDrafts.map((draft) => (
                        <BookingDraftGuestRow
                            key={draft.key}
                            draftKey={draft.key}
                            unitId={draft.unitId}
                            units={unitOptions}
                            guestCount={
                                booking.units.find(
                                    (unit) => String(unit.id) === draft.unitId,
                                )?.guestIds.length ?? 0
                            }
                            columns={columns}
                            showUnit={showUnit}
                            propertyCode={propertyCode}
                            reservationId={booking.id}
                            options={options}
                            onUnitChange={(unitId) =>
                                patchDraft(draft.key, unitId)
                            }
                            onRemove={() => removeDraft(draft.key)}
                            onSaved={() => removeDraft(draft.key)}
                            onDirtyChange={markDirty}
                        />
                    ))}
                </div>
                <div className="flex items-center justify-between gap-3 rounded-b-lg border-t bg-muted/50 px-3 py-2">
                    <FieldDescription>
                        {grouped && activeGroup !== NOT_STAYING_KEY
                            ? `${inUnit} ${inUnit === 1 ? 'guest' : 'guests'} in this unit. `
                            : ''}
                        Changes to dates or rooms go through the property.
                    </FieldDescription>
                    {canAdd && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={addDraft}
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
