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
import { movePreview } from '@/routes/reservations/units';
import { columnAt, dropAt, movableNights } from './drop-target';
import type { Drop, MoveNights } from './drop-target';

type Stay = App.Data.StayView.StayData;
type Target = App.Data.Reservations.MoveTargetData;
type Preview = App.Data.Reservations.UnitMovePreviewData;

/** A drag in progress. `targets` is null until the preview answers; rows are not greyed out before that. */
export type TimelineDrag = {
    stay: Stay;
    nights: MoveNights;
    targets: ReadonlyMap<string, Target> | null;
    drop: Drop | null;
};

/** A drop waiting for the confirm Dialog. */
export type TimelineMove = {
    stay: Stay;
    nights: MoveNights;
    drop: Drop;
};

/** The data a stay bar gives its draggable. */
export type StayDragData = { kind: 'move'; stay: Stay };

/** A dragged bar moves up and down only: a drag changes the room, not the dates. */
export const lockToVerticalAxis: Modifier = ({ transform }) => ({
    ...transform,
    x: 0,
});

/** How far the pointer moves before a press on a bar becomes a drag, so a click still opens the peek sheet. */
const DRAG_DISTANCE = 6;

/** A click this soon after a drop is the end of the drag, not a click on the bar. */
const CLICK_AFTER_DROP_MS = 250;

/**
 * The drag of a stay bar to another room or bed. The preview is read once when the drag starts: every row the unit
 * can move to and the night it is free from, so the rows grey out and the drop is checked without another request.
 * The row under the pointer comes from the grid's `data-row-key`, and the night from the pointer's column. Nothing is
 * saved here: a drop waits in `move` for the confirm Dialog. No drag on a phone or without `reservations.manage`.
 */
export function useTimelineDrag({
    enabled,
    businessDate,
    dates,
    labelWidth,
    columnWidth,
}: {
    enabled: boolean;
    businessDate: string;
    dates: string[];
    labelWidth: number;
    columnWidth: number;
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
    const [move, setMove] = useState<TimelineMove | null>(null);
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

            setDrag((current) => {
                if (current === null || current.targets === null) {
                    return current;
                }

                const drop =
                    row?.dataset.rowKey === undefined
                        ? null
                        : dropAt({
                              stay: current.stay,
                              nights: current.nights,
                              rowKey: row.dataset.rowKey,
                              date: dates[
                                  columnAt(
                                      x,
                                      row.getBoundingClientRect().left,
                                      labelWidth,
                                      columnWidth,
                                      dates.length,
                                  )
                              ],
                              targets: current.targets,
                          });

                return current.drop?.rowKey === drop?.rowKey &&
                    current.drop?.fromNight === drop?.fromNight
                    ? current
                    : { ...current, drop };
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
    }, [activeUnit, targets, dates, labelWidth, columnWidth]);

    const onDragStart = ({ active, activatorEvent }: DragStartEvent) => {
        const data = active.data.current as StayDragData | undefined;
        const stay = data?.kind === 'move' ? data.stay : undefined;
        const nights = stay ? movableNights(stay, businessDate) : null;

        if (!stay || !nights) {
            return;
        }

        if (activatorEvent instanceof PointerEvent) {
            pointer.current = {
                x: activatorEvent.clientX,
                y: activatorEvent.clientY,
            };
        }

        setDrag({ stay, nights, targets: null, drop: null });
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
        }

        setDrag(null);
        pointer.current = null;
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
        /** Whether a stay's bar can be dragged at all. */
        canDrag: (stay: Stay) =>
            canUse && movableNights(stay, businessDate) !== null,
        /** True for the click the browser sends when a drag ends on its own bar. */
        isClickAfterDrop: () =>
            performance.now() - droppedAt.current < CLICK_AFTER_DROP_MS,
    };
}
