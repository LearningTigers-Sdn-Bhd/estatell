import type { DragStartEvent } from '@dnd-kit/core';
import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from '@/components/ui/toast';
import { useIsMobile } from '@/hooks/use-mobile';
import { datesPreview } from '@/routes/reservations/units';
import { columnAt } from './drop-target';
import type { Bar } from './project-bars';
import { clampEdgeDate, edgeHandles, resizedDates } from './resize-target';
import type { Edge, EdgeDates, EdgeHandles } from './resize-target';

type Stay = App.Data.StayView.StayData;
type Preview = App.Data.Reservations.UnitDatesPreviewData;

/** The data an edge handle gives its draggable. `kind` tells it from the draggable of a bar, which moves rooms. */
export type EdgeDragData = {
    kind: 'edge';
    edge: Edge;
    stay: Stay;
    /** The row the bar is drawn in. For a bar in the Unassigned lane it is the lane's sub-row. */
    rowKey: string;
};

/** An edge drag in progress. `date` is the departure (right edge) or the arrival (left edge), already clamped. */
export type EdgeDrag = { stay: Stay; edge: Edge; rowKey: string; date: string };

/** A drop the server accepted, waiting for the confirm Dialog. */
export type EdgeChange = {
    stay: Stay;
    rowKey: string;
    arrivalDate: string;
    departureDate: string;
    preview: Preview;
};

/** The new span of a stay while its edge is dragged or its change waits for the Dialog. */
export type EdgeOutline = { rowKey: string; stay: Stay } & EdgeDates;

/** The refusal for restricted nights when the user may not book past restrictions. Same words as Change dates. */
export const RESTRICTED_MESSAGE =
    'The new nights break a rate restriction, for example a minimum stay. Ask someone who may book past restrictions.';

/**
 * The drag of a stay bar's edge to change its dates. It sits beside `useTimelineDrag` in the same DndContext: a
 * draggable tells them apart by its data `kind` (`edge` here, `move` there) and each hook ignores the other's.
 * The date is the column under the pointer, clamped by `clampEdgeDate`. On a drop the preview is read once: a refusal
 * (or restricted nights for a user who may not override) shows as a toast and nothing opens; otherwise the confirm
 * Dialog gets the preview. Nothing is saved here. No handle on a phone or without `reservations.manage`.
 */
export function useEdgeDrag({
    enabled,
    canOverrideRestrictions,
    businessDate,
    dates,
    labelWidth,
    columnWidth,
}: {
    enabled: boolean;
    canOverrideRestrictions: boolean;
    businessDate: string;
    dates: string[];
    labelWidth: number;
    columnWidth: number;
}) {
    const isMobile = useIsMobile();
    const http = useHttp<Record<string, string>, Preview>('post', '', {});
    const [drag, setDrag] = useState<EdgeDrag | null>(null);
    const [checking, setChecking] = useState<EdgeOutline | null>(null);
    const [change, setChange] = useState<EdgeChange | null>(null);
    const pointer = useRef<{ x: number; y: number } | null>(null);
    const activeUnit = drag?.stay.unitId ?? null;
    const activeEdge = drag?.edge ?? null;
    const rowKey = drag?.rowKey ?? null;
    const busy = checking !== null || change !== null;
    const canUse = enabled && !isMobile && !busy;

    useEffect(() => {
        if (activeUnit === null || activeEdge === null || rowKey === null) {
            return;
        }

        const place = () => {
            const row = document.querySelector<HTMLElement>(
                `[data-row-key="${CSS.escape(rowKey)}"]`,
            );

            if (pointer.current === null || row === null) {
                return;
            }

            const column = columnAt(
                pointer.current.x,
                row.getBoundingClientRect().left,
                labelWidth,
                columnWidth,
                dates.length,
            );

            setDrag((current) => {
                if (current === null) {
                    return current;
                }

                const date = clampEdgeDate({
                    stay: current.stay,
                    edge: current.edge,
                    date: dates[column],
                    businessDate,
                    firstDate: dates[0],
                    lastDate: dates[dates.length - 1],
                });

                return date === current.date ? current : { ...current, date };
            });
        };
        const onPointerMove = (event: PointerEvent) => {
            pointer.current = { x: event.clientX, y: event.clientY };
            place();
        };

        window.addEventListener('pointermove', onPointerMove);
        // The grid scrolls under a still pointer while dragging near its edge.
        window.addEventListener('scroll', place, true);

        return () => {
            window.removeEventListener('pointermove', onPointerMove);
            window.removeEventListener('scroll', place, true);
        };
    }, [
        activeUnit,
        activeEdge,
        rowKey,
        dates,
        businessDate,
        labelWidth,
        columnWidth,
    ]);

    const onDragStart = ({ active, activatorEvent }: DragStartEvent) => {
        const data = active.data.current as EdgeDragData | undefined;

        if (data?.kind !== 'edge') {
            return;
        }

        if (activatorEvent instanceof PointerEvent) {
            pointer.current = {
                x: activatorEvent.clientX,
                y: activatorEvent.clientY,
            };
        }

        setDrag({
            stay: data.stay,
            edge: data.edge,
            rowKey: data.rowKey,
            date:
                data.edge === 'right'
                    ? data.stay.departureDate
                    : data.stay.arrivalDate,
        });
    };

    const onDragEnd = () => {
        const dropped = drag;

        setDrag(null);
        pointer.current = null;

        const moved = dropped
            ? resizedDates(dropped.stay, dropped.edge, dropped.date)
            : null;

        if (!dropped || moved === null) {
            return;
        }

        setChecking({ rowKey: dropped.rowKey, stay: dropped.stay, ...moved });
        http.transform(() => ({
            arrival_date: moved.arrivalDate,
            departure_date: moved.departureDate,
        }));
        http.post(
            datesPreview.url({
                reservation: dropped.stay.reservationId,
                unit: dropped.stay.unitId,
            }),
        )
            .then((preview) => {
                if (preview.error !== null) {
                    toast.add({ title: preview.error, type: 'error' });
                } else if (preview.restricted && !canOverrideRestrictions) {
                    toast.add({ title: RESTRICTED_MESSAGE, type: 'error' });
                } else {
                    setChange({
                        stay: dropped.stay,
                        rowKey: dropped.rowKey,
                        ...moved,
                        preview,
                    });
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

    const outline: EdgeOutline | null = drag
        ? {
              rowKey: drag.rowKey,
              stay: drag.stay,
              ...(resizedDates(drag.stay, drag.edge, drag.date) ?? {
                  arrivalDate: drag.stay.arrivalDate,
                  departureDate: drag.stay.departureDate,
              }),
          }
        : (checking ?? change);

    return {
        onDragStart,
        onDragEnd,
        onDragCancel,
        /** The new span to draw as a dashed outline: while dragging, while the preview loads and while the Dialog is open. */
        outline,
        change,
        closeChange: () => setChange(null),
        /** The edges of a bar that show a handle. */
        handlesFor: (stay: Stay, bar: Bar): EdgeHandles =>
            canUse
                ? edgeHandles(stay, bar, dates)
                : { left: false, right: false },
    };
}
