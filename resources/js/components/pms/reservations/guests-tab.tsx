import { router } from '@inertiajs/react';
import {
    List,
    ListTree,
    PanelRightClose,
    PanelRightOpen,
    Plus,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { emptyGuest } from '@/components/pms/reservations/booking-guests';
import type { BookingGuest } from '@/components/pms/reservations/booking-guests';
import {
    ATTACHABLE_STATUSES,
    draftHasContent,
    groupKey,
    MAX_DRAFTS,
    NOT_STAYING_KEY,
    takenBedUnits,
    unitsWithoutGuest,
} from '@/components/pms/reservations/guest-rows';
import { MoveGuestDialog } from '@/components/pms/reservations/move-guest-dialog';
import type { MovingGuest } from '@/components/pms/reservations/move-guest-dialog';
import type { GuestsView } from '@/components/pms/reservations/use-guests-view';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { billToGuest } from '@/routes/reservations/units';
import { destroy as removeGuest } from '@/routes/reservations/units/guests';

type Row = App.Data.Reservations.ReservationGuestData;
type Unit = App.Data.Reservations.ReservationUnitOptionData;

type Replacement = {
    rowKey: string;
    unitId: number;
    guestId: number;
    name: string;
    unitLabel: string;
};

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
    view,
    onViewChange,
    activeGroup,
    summaryHidden,
    onToggleSummary,
}: {
    reservationId: number;
    guests: Row[];
    units: Unit[];
    canManage: boolean;
    canManageGuests: boolean;
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
    /** All, or Grouped with the unit rail. Grouped needs 2 or more units. */
    view: GuestsView;
    onViewChange: (view: GuestsView) => void;
    /** The unit picked in the rail (`none` for Not staying). */
    activeGroup: string;
    summaryHidden: boolean;
    onToggleSummary: () => void;
}) {
    const [drafts, setDrafts] = useState<BookingGuest[]>([]);
    /** The rail entry each draft was added in, so it stays there while it is typed. */
    const [homes, setHomes] = useState<Record<string, string>>({});
    /** Drafts that replace a saved guest, by draft key. The saved row is shown as that draft. */
    const [replacements, setReplacements] = useState<
        Record<string, Replacement>
    >({});
    const [removing, setRemoving] = useState<Row | null>(null);
    const [moving, setMoving] = useState<MovingGuest | null>(null);
    const [dirtySaved, setDirtySaved] = useState<Set<string>>(new Set());
    const openUnits = units.filter((unit) =>
        ATTACHABLE_STATUSES.includes(unit.status.value),
    );
    const grouped = view === 'grouped' && units.length > 1;
    const inNotStaying = grouped && activeGroup === NOT_STAYING_KEY;
    const activeUnit = grouped
        ? units.find((unit) => String(unit.id) === activeGroup)
        : undefined;
    const canAdd =
        canManage &&
        openUnits.length > 0 &&
        (!grouped ||
            inNotStaying ||
            (activeUnit !== undefined &&
                ATTACHABLE_STATUSES.includes(activeUnit.status.value)));
    const showUnit = !grouped || inNotStaying;
    const dirty = drafts.some(draftHasContent) || dirtySaved.size > 0;
    const guard = useUnsavedChanges(dirty);
    const shownRows = grouped
        ? guests.filter((row) => groupKey(row) === activeGroup)
        : guests;
    const adding = drafts.filter((draft) => !(draft.key in replacements));
    const shownDrafts = grouped
        ? adding.filter(
              (draft) => (homes[draft.key] ?? draft.stays_in) === activeGroup,
          )
        : adding;
    const empty = unitsWithoutGuest(guests, drafts);
    const inUnit = shownRows.filter((row) => row.name !== null).length;
    const summaryLabel = summaryHidden ? 'Show summary' : 'Hide summary';

    const addDraft = (unitId: number | null = null) => {
        if (drafts.length >= MAX_DRAFTS) {
            return;
        }

        const inGroup =
            grouped && activeGroup !== NOT_STAYING_KEY
                ? Number(activeGroup)
                : null;
        const preset =
            unitId ??
            inGroup ??
            (openUnits.length === 1 ? openUnits[0].id : null);

        const draft = emptyGuest(preset === null ? '' : String(preset));

        setDrafts((current) => [...current, draft]);
        if (grouped) {
            setHomes((current) => ({ ...current, [draft.key]: activeGroup }));
        }
    };

    const startReplace = (row: Row, rowKeyValue: string) => {
        if (row.unitId === null || row.guestId === null || row.name === null) {
            return;
        }

        const draft = emptyGuest(String(row.unitId));

        setDrafts((current) => [...current, draft]);
        setReplacements((current) => ({
            ...current,
            [draft.key]: {
                rowKey: rowKeyValue,
                unitId: row.unitId as number,
                guestId: row.guestId as number,
                name: row.name as string,
                unitLabel: row.unitLabel ?? '',
            },
        }));
    };

    const patchDraft = (key: string, patch: Partial<BookingGuest>) =>
        setDrafts((current) =>
            current.map((draft) =>
                draft.key === key ? { ...draft, ...patch } : draft,
            ),
        );

    const removeDraft = (key: string) => {
        setDrafts((current) => current.filter((draft) => draft.key !== key));
        setReplacements((current) => {
            const { [key]: _gone, ...rest } = current;

            return rest;
        });
    };

    const confirmRemove = () => {
        if (
            removing === null ||
            removing.unitId === null ||
            removing.guestId === null
        ) {
            return;
        }

        router.delete(
            removeGuest.url({
                reservation: reservationId,
                unit: removing.unitId,
                guestId: removing.guestId,
            }),
            {
                preserveScroll: true,
                onFinish: () => setRemoving(null),
            },
        );
    };

    const startMove = (row: Row) => {
        if (row.guestId === null || row.name === null) {
            return;
        }

        setMoving({
            unitId: row.unitId,
            guestId: row.guestId,
            name: row.name,
            unitLabel: row.unitLabel ?? '',
        });
    };

    const markDirty = useCallback((key: string, isDirty: boolean) => {
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
    }, []);

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
            <div className="flex items-center justify-between gap-3">
                {units.length > 1 ? (
                    <ToggleGroup
                        variant="outline"
                        spacing={0}
                        aria-label="View"
                        value={[view]}
                        onValueChange={(values) => {
                            const next = values[0];

                            if (next === 'all' || next === 'grouped') {
                                onViewChange(next);
                            }
                        }}
                    >
                        <ToggleGroupItem
                            value="all"
                            aria-label="All guests"
                            title="All guests"
                        >
                            <List />
                            All
                        </ToggleGroupItem>
                        <ToggleGroupItem
                            value="grouped"
                            aria-label="Grouped by unit"
                            title="Grouped by unit"
                        >
                            <ListTree />
                            Grouped
                        </ToggleGroupItem>
                    </ToggleGroup>
                ) : (
                    <span />
                )}
                <Tooltip>
                    <TooltipTrigger
                        render={
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label={summaryLabel}
                                aria-pressed={summaryHidden}
                                onClick={onToggleSummary}
                            />
                        }
                    >
                        {summaryHidden ? (
                            <PanelRightOpen />
                        ) : (
                            <PanelRightClose />
                        )}
                    </TooltipTrigger>
                    <TooltipContent>{summaryLabel}</TooltipContent>
                </Tooltip>
            </div>
            <div className="rounded-lg border text-sm">
                <GuestsHeader showUnit={showUnit} />
                <div className="divide-y">
                    {shownRows.map((row, index) => {
                        const key = rowKey(row, index);
                        const replacing = drafts.find(
                            (draft) => replacements[draft.key]?.rowKey === key,
                        );

                        if (replacing !== undefined) {
                            return (
                                <DraftGuestRow
                                    key={replacing.key}
                                    reservationId={reservationId}
                                    draft={replacing}
                                    units={openUnits}
                                    taken={new Set()}
                                    showUnit={false}
                                    replacing={replacements[replacing.key]}
                                    canManageGuests={canManageGuests}
                                    countries={countries}
                                    genders={genders}
                                    locales={locales}
                                    onChange={(patch) =>
                                        patchDraft(replacing.key, patch)
                                    }
                                    onRemove={() => removeDraft(replacing.key)}
                                />
                            );
                        }

                        return (
                            <SavedGuestRow
                                key={key}
                                rowId={key}
                                reservationId={reservationId}
                                row={row}
                                showUnit={showUnit}
                                canAdd={canAdd}
                                canEdit={
                                    canManage &&
                                    canManageGuests &&
                                    row.guest !== null &&
                                    row.guestId !== null
                                }
                                canChange={
                                    canManage &&
                                    row.guestId !== null &&
                                    row.unitId !== null &&
                                    row.unitStatus !== null &&
                                    ATTACHABLE_STATUSES.includes(
                                        row.unitStatus.value,
                                    )
                                }
                                canMove={
                                    canManage &&
                                    row.guestId !== null &&
                                    (row.unitId === null
                                        ? openUnits.length > 0
                                        : row.unitStatus !== null &&
                                          ATTACHABLE_STATUSES.includes(
                                              row.unitStatus.value,
                                          ) &&
                                          openUnits.some(
                                              (unit) => unit.id !== row.unitId,
                                          ))
                                }
                                countries={countries}
                                genders={genders}
                                locales={locales}
                                onAdd={addDraft}
                                onBill={bill}
                                onReplace={(one) => startReplace(one, key)}
                                onMove={startMove}
                                onRemove={setRemoving}
                                onDirtyChange={markDirty}
                            />
                        );
                    })}
                    {shownDrafts.map((draft) => (
                        <DraftGuestRow
                            key={draft.key}
                            reservationId={reservationId}
                            draft={draft}
                            showUnit={showUnit}
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
                        {inNotStaying
                            ? 'The booker does not stay.'
                            : grouped
                              ? `${inUnit} ${inUnit === 1 ? 'guest' : 'guests'} in this unit`
                              : empty === 0
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

            <MoveGuestDialog
                open={moving !== null}
                onOpenChange={(open) => !open && setMoving(null)}
                reservationId={reservationId}
                guest={moving}
                units={units}
            />

            <AlertDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Remove {removing?.name} from {removing?.unitLabel}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            No charges change. If this is the only guest, the
                            unit will have no guest.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep guest</AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            onClick={confirmRemove}
                        >
                            Remove from unit
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

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
