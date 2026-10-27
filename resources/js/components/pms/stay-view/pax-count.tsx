import { Baby, User } from 'lucide-react';
import { cn } from '@/lib/utils';

/** The adults and children of a stay as icons with counts. Children show only when there are any. */
export function PaxCount({
    stay,
    size = 'default',
}: {
    stay: Pick<App.Data.StayView.StayData, 'adults' | 'children'>;
    size?: 'default' | 'sm';
}) {
    const icon = size === 'sm' ? 'size-3' : 'size-4';
    const item = cn('flex items-center', size === 'sm' ? 'gap-0.5' : 'gap-1');

    return (
        <span
            className={cn(
                'flex items-center tabular-nums',
                size === 'sm' ? 'gap-2' : 'gap-3',
            )}
        >
            <span className={item}>
                <User aria-hidden className={icon} />
                {stay.adults}
                <span className="sr-only">
                    {stay.adults === 1 ? 'adult' : 'adults'}
                </span>
            </span>
            {stay.children > 0 && (
                <span className={item}>
                    <Baby aria-hidden className={icon} />
                    {stay.children}
                    <span className="sr-only">
                        {stay.children === 1 ? 'child' : 'children'}
                    </span>
                </span>
            )}
        </span>
    );
}
