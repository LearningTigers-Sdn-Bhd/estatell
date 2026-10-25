import { useDraggable } from '@dnd-kit/core';
import { cn } from '@/lib/utils';
import { barBox } from './project-bars';
import type { Bar } from './project-bars';
import type { Edge } from './resize-target';
import type { EdgeDragData } from './use-edge-drag';

type Stay = App.Data.StayView.StayData;

/** The width of the grab area, inside the bar so the handles of two bars that meet never overlap. */
const HANDLE_WIDTH = 8;

/**
 * The handle on one edge of a stay bar: a thin strip over the bar's edge that starts an edge drag. It is a sibling of
 * the bar button, not inside it, and hidden from assistive technology. The keyboard path to the same change is the
 * Change dates dialog on the reservation page.
 */
export function EdgeHandle({
    stay,
    bar,
    edge,
    labelWidth,
    columnWidth,
}: {
    stay: Stay;
    bar: Bar;
    edge: Edge;
    labelWidth: number;
    columnWidth: number;
}) {
    const { left, width } = barBox(bar, labelWidth, columnWidth);
    const { listeners, setNodeRef } = useDraggable({
        id: `${bar.id}-${edge}`,
        data: {
            kind: 'edge',
            edge,
            stay,
            rowKey: bar.rowKey,
        } satisfies EdgeDragData,
    });

    return (
        <div
            ref={setNodeRef}
            aria-hidden
            className={cn(
                'pointer-events-auto absolute inset-y-0 z-10 cursor-col-resize touch-none transition-colors hover:bg-ring/30',
            )}
            style={{
                left: edge === 'left' ? left : left + width - HANDLE_WIDTH,
                width: HANDLE_WIDTH,
            }}
            {...listeners}
        />
    );
}
