import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

function Panel({
    className,
    children,
    label,
    ...props
}: {
    className?: string;
    children: ReactNode;
    label?: string;
} & Pick<ComponentProps<'section'>, 'inert' | 'aria-hidden'>) {
    return (
        <section
            aria-label={label}
            className={cn(
                'min-h-0 rounded-xl border bg-card p-1.5 lg:overflow-y-auto',
                className,
            )}
            {...props}
        >
            {children}
        </section>
    );
}

/**
 * The fixed-height body of the reservation page: up to three side-by-side panels (list, main, aside).
 * Each panel is a bordered card that scrolls on its own. Without a list there are two panels.
 * Below `lg` the panels stack in one column and the page scrolls as a whole, with the aside first.
 * `hideAside` shrinks the aside column to nothing (animated) so the centre panel takes the width. Below `lg` it
 * removes the aside panel.
 */
export function ReservationWorkspace({
    list,
    aside,
    hideAside = false,
    children,
}: {
    /** Left panel, for example the unit chooser or the folio rail. */
    list?: ReactNode;
    /** Right panel, the reservation summary. */
    aside: ReactNode;
    /** Hides the aside panel. */
    hideAside?: boolean;
    /** Centre panel, the content of the open tab. */
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'grid gap-1.5 transition-[grid-template-columns] duration-200 ease-out motion-reduce:transition-none lg:min-h-0 lg:flex-1 lg:grid-rows-[minmax(0,1fr)]',
                list
                    ? hideAside
                        ? 'lg:grid-cols-[14rem_minmax(0,1fr)_0rem]'
                        : 'lg:grid-cols-[14rem_minmax(0,1fr)_20rem]'
                    : hideAside
                      ? 'lg:grid-cols-[minmax(0,1fr)_0rem]'
                      : 'lg:grid-cols-[minmax(0,1fr)_20rem]',
            )}
        >
            <Panel
                className={cn(
                    'order-1 transition-[opacity,margin] duration-200 ease-out motion-reduce:transition-none lg:order-3',
                    hideAside &&
                        'pointer-events-none max-lg:hidden lg:-ms-1.5 lg:overflow-hidden lg:border-0 lg:p-0 lg:opacity-0',
                )}
                label="Reservation summary"
                inert={hideAside}
            >
                <div className="lg:w-77">{aside}</div>
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
