import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

function Panel({
    className,
    children,
    label,
}: {
    className?: string;
    children: ReactNode;
    label?: string;
}) {
    return (
        <section
            aria-label={label}
            className={cn(
                'min-h-0 rounded-xl border bg-card p-1.5 lg:overflow-y-auto',
                className,
            )}
        >
            {children}
        </section>
    );
}

/**
 * The fixed-height body of the reservation page: up to three side-by-side panels (list, main, aside).
 * Each panel is a bordered card that scrolls on its own. Without a list there are two panels.
 * Below `lg` the panels stack in one column and the page scrolls as a whole, with the aside first.
 */
export function ReservationWorkspace({
    list,
    aside,
    children,
}: {
    /** Left panel, for example the unit chooser or the folio rail. */
    list?: ReactNode;
    /** Right panel, the reservation summary. */
    aside: ReactNode;
    /** Centre panel, the content of the open tab. */
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'grid gap-3 lg:min-h-0 lg:flex-1 lg:grid-rows-[minmax(0,1fr)]',
                list
                    ? 'lg:grid-cols-[14rem_minmax(0,1fr)_20rem]'
                    : 'lg:grid-cols-[minmax(0,1fr)_20rem]',
            )}
        >
            <Panel className="order-1 lg:order-3" label="Reservation summary">
                {aside}
            </Panel>
            {list && (
                <Panel className="order-2 lg:order-1" label="Choose">
                    {list}
                </Panel>
            )}
            <Panel className="order-3 lg:order-2" label="Details">
                {children}
            </Panel>
        </div>
    );
}
