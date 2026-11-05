import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

function Panel({
    className,
    children,
    label,
    scroll = 'panels',
    sticky = false,
    ...props
}: {
    className?: string;
    children: ReactNode;
    label?: string;
    scroll?: WorkspaceScroll;
    /** Holds sticky content, so the panel must not be a scroll container. */
    sticky?: boolean;
} & Pick<ComponentProps<'section'>, 'inert' | 'aria-hidden'>) {
    return (
        <section
            aria-label={label}
            className={cn(
                'min-h-0 min-w-0 rounded-xl border bg-card p-1.5',
                !sticky && 'overflow-x-auto',
                scroll === 'panels' && 'lg:overflow-y-auto',
                className,
            )}
            {...props}
        >
            {children}
        </section>
    );
}

/** `panels`: the body fills the shell height and each panel scrolls on its own. `page`: the page scrolls and the aside stays in view. */
type WorkspaceScroll = 'panels' | 'page';

/**
 * The fixed-height body of a workspace page (the reservation page, the manual folio, the channel connect and map pages):
 * up to three side-by-side panels (list, main, aside).
 * Each panel is a bordered card that scrolls on its own. Without a list there are two panels.
 * Below `lg` the panels stack in one column and the page scrolls as a whole, with the aside first.
 * `hideAside` shrinks the aside column to nothing (animated) so the centre panel takes the width. Below `lg` it
 * removes the aside panel.
 * `scroll="page"` is for a settings page (Booking website): the panels fill the content height and grow with their
 * content, the page scrolls, the aside content is sticky, and below `lg` the aside comes after the centre panel. Without an aside the centre panel takes the width.
 */
export function Workspace({
    list,
    aside,
    asideLabel = 'Summary',
    hideAside = false,
    scroll = 'panels',
    asideWidth = 'default',
    children,
}: {
    /** Left panel, for example the unit chooser or the folio rail. */
    list?: ReactNode;
    /** Right panel, for example the reservation summary. Optional with `scroll="page"`. */
    aside?: ReactNode;
    /** The accessible name of the aside panel. */
    asideLabel?: string;
    /** Hides the aside panel. */
    hideAside?: boolean;
    /** Whether each panel scrolls on its own or the page scrolls. */
    scroll?: WorkspaceScroll;
    /** With `scroll="page"`: `default` is 20rem, `wide` is 28rem for a rail with wider content. */
    asideWidth?: 'default' | 'wide';
    /** Centre panel, the content of the open tab. */
    children: ReactNode;
}) {
    if (scroll === 'page') {
        return (
            <div
                className={cn(
                    'grid grid-cols-1 gap-1.5 lg:flex-1',
                    aside !== undefined &&
                        (asideWidth === 'wide'
                            ? 'lg:grid-cols-[minmax(0,1fr)_28rem]'
                            : 'lg:grid-cols-[minmax(0,1fr)_20rem]'),
                )}
            >
                <Panel scroll="page" label="Details">
                    {children}
                </Panel>
                {aside !== undefined && (
                    <Panel scroll="page" sticky label={asideLabel}>
                        <div className="lg:sticky lg:top-4">{aside}</div>
                    </Panel>
                )}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'grid grid-cols-1 gap-1.5 transition-[grid-template-columns] duration-200 ease-out motion-reduce:transition-none lg:min-h-0 lg:flex-1 lg:grid-rows-[minmax(0,1fr)]',
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
                    'order-1 transition-[opacity,margin] duration-200 ease-out motion-reduce:transition-none lg:order-3 lg:overflow-x-hidden',
                    hideAside &&
                        'pointer-events-none max-lg:hidden lg:-ms-1.5 lg:overflow-hidden lg:border-0 lg:p-0 lg:opacity-0',
                )}
                label={asideLabel}
                inert={hideAside}
            >
                <div className={cn(hideAside && 'lg:w-77')}>{aside}</div>
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
