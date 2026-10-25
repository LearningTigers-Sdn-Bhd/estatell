import { PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import type {
    DragStartEvent,
    Modifier,
    SensorDescriptor,
    SensorOptions,
} from '@dnd-kit/core';
import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useIsMobile } from '@/hooks/use-mobile';
import { toast } from '@/components/ui/toast';
import { movePreview, moveStayPreview } from '@/routes/reservations/units';
import { columnAt, dropAt, movableNights } from './drop-target';
import type { Drop, MoveNights } from './drop-target';
import { canShiftDates, clampShift, shiftedDates } from './resize-target';
import type { EdgeDates } from './resize-target';
import { RESTRICTED_MESSAGE } from './use-edge-drag';

type Stay = App.Data.StayView.StayData;
type Target = App.Data.Reservations.MoveTargetData;
type Preview = App.Data.Reservations.UnitMovePreviewData;
type StayPreview = App.Data.Reservations.UnitMoveStayPreviewData;

/**
 * A drag in progress. `targets` is null until the preview answers; rows are not greyed out before that.
 * A Confirmed bar also moves sideways: `shift` is the whole columns the pointer moved since the drag began (kept inside
 * the allowed range), and with a shift `drop` is null and `shifted` is the row and the new dates. `verticalOnly` bars
 * (Checked-in, Package plan, cut by the window) keep the shift at 0.
 */
export type TimelineDrag = {
    stay: Stay;
    nights: MoveNights;
    targets: ReadonlyMap<string, Target> | null;
    drop: Drop | null;
    verticalOnly: boolean;
    startColumn: number;
    shift: number;
    shifted: StayShift | null;
};

/** The row under the pointer and the new dates of a stay dragged sideways. */
export type StayShift = { rowKey: string } & EdgeDates;

/** A shifted drop that the server accepted, waiting for the confirm Dialog. */
export type TimelineStayMove = StayShift & {
    stay: Stay;
    preview: StayPreview;
};

/** A drop waiting for the confirm Dialog. */
export type TimelineMove = {
    stay: Stay;
    nights: MoveNights;
    drop: Drop;
};

/** The data a stay bar gives its draggable. */
export type StayDragData = { kind: 'move'; stay: Stay };

/** A bar that cannot change its dates moves up and down only. */
export const lockToVerticalAxis: Modifier = ({ transform }) => ({
    ...transform,
    x: 0,
});

/** How far the pointer moves before a press on a bar becomes a drag, so a click still opens the peek sheet. */
const DRAG_DISTANCE = 6;

/** A click this soon after a drop is the end of the drag, not a click on the bar. */
const CLICK_AFTER_DROP_MS = 250;

/** A row of a room or a bed, not a group heading or an Unassigned lane. */
function isPlaceRow(rowKey: string): boolean {
    return /^(room|bed)-\d+$/.test(rowKey);
}

function longDate(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * The drag of a stay bar to another room or bed, and for a Confirmed bar to other dates. The preview is read once
 * when the drag starts: every row the unit can move to and the night it is free from, so the rows grey out and the drop
 * is checked without another request. The row under the pointer comes from the grid's `data-row-key`. Without a column
 * shift the unit moves to that row (a drop waits in `move` for the confirm Dialog). With a shift (a Confirmed bar
 * only) the whole stay moves by those columns to that row: on a drop `move-stay-preview` is read once and a drop the
 * server would refuse shows as a toast, otherwise `stayMove` waits for its Dialog. Nothing is saved here. No drag on a
 * phone or without `reservations.manage`.
 */
export function useTimelineDrag({
    enabled,
    canOverrideRestrictions,
    businessDate,
    dates,
    labelWidth,
    columnWidth,
    placeName,
}: {
    enabled: boolean;
    canOverrideRestrictions: boolean;
    businessDate: string;
    dates: string[];
    labelWidth: number;
    columnWidth: number;
    /** The name of a row for a toast, for example "Room 204". */
    placeName: (rowKey: string) => string;
}) {
    const isMobile = useIsMobile();
    const canUse = enabled && !isMobile;
    const pointerSensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: { distance: DRAG_DISTANCE },
        }),
    );
    const sensors: SensorDescriptor<SensorOptions>[] = canUse
        ? pointerSensors
        : [];
    const http = useHttp<Record<string, string | boolean>, Preview>(
        'post',
        '',
        {},
    );
    const [drag, setDrag] = useState<TimelineDrag | null>(null);
    const stayHttp = useHttp<Record<string, string>, StayPreview>(
        'post',
        '',
        {},
    );
    const [move, setMove] = useState<TimelineMove | null>(null);
    const [checking, setChecking] = useState<
        (StayShift & { stay: Stay }) | null
    >(null);
    const [stayMove, setStayMove] = useState<TimelineStayMove | null>(null);
    const pointer = useRef<{ x: number; y: number } | null>(null);
    const droppedAt = useRef(0);
    const activeUnit = drag?.stay.unitId ?? null;
    const targets = drag?.targets ?? null;

    useEffect(() => {
        if (activeUnit === null) {
            return;
        }

        const place = () => {
            if (pointer.current === null) {
                return;
            }

            const { x, y } = pointer.current;
            const row = document
                .elementsFromPoint(x, y)
                .map((element) =>
                    element.closest<HTMLElement>('[data-row-key]'),
                )
                .find((element) => element !== null);
            const rowKey = row?.dataset.rowKey;
            const column =
                row === undefined || row === null
                    ? null
                    : columnAt(
                          x,
                          row.getBoundingClientRect().left,
                          labelWidth,
                          columnWidth,
                          dates.length,
                      );

            setDrag((current) => {
                if (current === null || column === null || !rowKey) {
                    return current;
                }

                const shift = current.verticalOnly
                    ? 0
                    : clampShift({
                          stay: current.stay,
                          columns: column - current.startColumn,
                          businessDate,
                          firstDate: dates[0],
                          lastDate: dates[dates.length - 1],
                      });
                const shifted =
                    shift !== 0 && isPlaceRow(rowKey)
                        ? { rowKey, ...shiftedDates(current.stay, shift) }
                        : null;
                const drop =
                    shift !== 0 || current.targets === null
                        ? null
                        : dropAt({
                              stay: current.stay,
                              nights: current.nights,
                              rowKey,
                              date: dates[column],
                              targets: current.targets,
                          });

                // A drop is known only once the targets are in, so keep the old one until then.
                if (shift === 0 && current.targets === null) {
                    return current.shift === 0 && current.shifted === null
                        ? current
                        : { ...current, shift, shifted: null };
                }

                return current.shift === shift &&
                    current.shifted?.rowKey === shifted?.rowKey &&
                    current.shifted?.arrivalDate === shifted?.arrivalDate &&
                    current.drop?.rowKey === drop?.rowKey &&
                    current.drop?.fromNight === drop?.fromNight
                    ? current
                    : { ...current, shift, shifted, drop };
            });
        };
        const onPointerMove = (event: PointerEvent) => {
            pointer.current = { x: event.clientX, y: event.clientY };
            place();
        };

        // The targets may arrive after the pointer stopped moving.
        place();
        window.addEventListener('pointermove', onPointerMove);
        // The grid scrolls under a still pointer while dragging near its edge.
        window.addEventListener('scroll', place, true);

        return () => {
            window.removeEventListener('pointermove', onPointerMove);
            window.removeEventListener('scroll', place, true);
        };
    }, [activeUnit, targets, dates, businessDate, labelWidth, columnWidth]);

    const onDragStart = ({ active, activatorEvent }: DragStartEvent) => {
        const data = active.data.current as StayDragData | undefined;
        const stay = data?.kind === 'move' ? data.stay : undefined;
        const nights = stay ? movableNights(stay, businessDate) : null;

        if (!stay || !nights) {
            return;
        }

        let startColumn: number | null = null;

        if (activatorEvent instanceof PointerEvent) {
            pointer.current = {
                x: activatorEvent.clientX,
                y: activatorEvent.clientY,
            };

            const row = document
                .elementsFromPoint(pointer.current.x, pointer.current.y)
                .map((element) =>
                    element.closest<HTMLElement>('[data-row-key]'),
                )
                .find((element) => element !== null);

            startColumn = row
                ? columnAt(
                      pointer.current.x,
                      row.getBoundingClientRect().left,
                      labelWidth,
                      columnWidth,
                      dates.length,
                  )
                : null;
        }

        setDrag({
            stay,
            nights,
            targets: null,
            drop: null,
            verticalOnly:
                startColumn === null ||
                !canShiftDates(stay, dates[0], dates[dates.length - 1]),
            startColumn: startColumn ?? 0,
            shift: 0,
            shifted: null,
        });
        http.transform(() => ({ from_night: nights.first, targets: true }));
        http.post(
            movePreview.url({
                reservation: stay.reservationId,
                unit: stay.unitId,
            }),
        )
            .then((preview) =>
                setDrag((current) =>
                    current?.stay.unitId === stay.unitId
                        ? {
                              ...current,
                              targets: new Map(
                                  preview.targets.map((target) => [
                                      target.rowKey,
                                      target,
                                  ]),
                              ),
                          }
                        : current,
                ),
            )
            .catch(() => setDrag(null));
    };

    const onDragEnd = () => {
        droppedAt.current = performance.now();

        if (drag?.drop) {
            setMove({ stay: drag.stay, nights: drag.nights, drop: drag.drop });
        } else if (drag?.shifted) {
            checkShift(drag.stay, drag.shifted);
        }

        setDrag(null);
        pointer.current = null;
    };

    const checkShift = (stay: Stay, shifted: StayShift) => {
        const isBed = shifted.rowKey.startsWith('bed-');
        const name = placeName(shifted.rowKey);

        setChecking({ stay, ...shifted });
        stayHttp.transform(() => ({
            arrival_date: shifted.arrivalDate,
            departure_date: shifted.departureDate,
            [isBed ? 'bed_id' : 'room_id']: shifted.rowKey.split('-')[1],
        }));
        stayHttp
            .post(
                moveStayPreview.url({
                    reservation: stay.reservationId,
                    unit: stay.unitId,
                }),
            )
            .then((preview) => {
                if (preview.error !== null) {
                    toast.add({ title: preview.error, type: 'error' });
                } else if (
                    preview.dates?.restricted &&
                    !canOverrideRestrictions
                ) {
                    toast.add({ title: RESTRICTED_MESSAGE, type: 'error' });
                } else if (!preview.roomTypeAllowed) {
                    toast.add({
                        title: `${name} cannot take this stay. Its room type is not sold on the rate plan.`,
                        type: 'error',
                    });
                } else if (!preview.targetFree) {
                    toast.add({
                        title: preview.firstTakenNight
                            ? `${name} is not free on ${longDate(preview.firstTakenNight)}.`
                            : `${name} is not free for the new dates.`,
                        type: 'error',
                    });
                } else {
                    setStayMove({ stay, ...shifted, preview });
                }
            })
            .catch(() =>
                toast.add({
                    title: 'The new dates could not be checked. Try again.',
                    type: 'error',
                }),
            )
            .finally(() => setChecking(null));
    };

    const onDragCancel = () => {
        setDrag(null);
        pointer.current = null;
    };

    return {
        sensors,
        onDragStart,
        onDragEnd,
        onDragCancel,
        drag,
        move,
        closeMove: () => setMove(null),
        stayMove,
        closeStayMove: () => setStayMove(null),
        /** The new place of a shifted bar to draw as a dashed outline: while the preview loads and while the Dialog is open. */
        stayOutline: checking ?? stayMove,
        /** Whether a stay's bar can be dragged at all. */
        canDrag: (stay: Stay) =>
            canUse &&
            checking === null &&
            movableNights(stay, businessDate) !== null,
        /** True for the click the browser sends when a drag ends on its own bar. */
        isClickAfterDrop: () =>
            performance.now() - droppedAt.current < CLICK_AFTER_DROP_MS,
    };
}
