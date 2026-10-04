import {
    BedDouble,
    Banknote,
    CalendarPlus,
    Circle,
    CreditCard,
    History,
    Pencil,
    Receipt,
    UserPlus,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import {
    PaginationFooter,
    toPaginationData,
} from '@/components/pms/pagination-footer';

type Activity = App.Data.Reservations.ReservationActivityData;

const kindIcons: Record<string, LucideIcon> = {
    'reservation.created': CalendarPlus,
    'reservation.unit_added': BedDouble,
    'reservation.room_assigned': BedDouble,
    'reservation.bed_assigned': BedDouble,
    'reservation.guest_attached': UserPlus,
    'reservation.add_on_added': Receipt,
    'reservation.add_on_removed': Receipt,
    'reservation.group_updated': Pencil,
};

function iconFor(kind: string | null): LucideIcon {
    if (kind === null) {
        return Circle;
    }

    if (kind.startsWith('payment.')) {
        return Banknote;
    }

    if (kind.startsWith('folio.')) {
        return CreditCard;
    }

    return kindIcons[kind] ?? Circle;
}

const dayFormat = new Intl.DateTimeFormat('en-GB', {
    weekday: 'short',
    day: '2-digit',
    month: 'short',
    year: 'numeric',
});

const timeFormat = new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
});

/**
 * The Activity tab: the reservation's log, newest first, grouped by day with a dot on a thin line.
 */
export function ActivityTab({
    activity,
}: {
    activity: Illuminate.LengthAwarePaginator<number, Activity>;
}) {
    if (activity.data.length === 0) {
        return <EmptyState icon={History} title="No activity yet" />;
    }

    const days = [
        ...Map.groupBy(activity.data, (entry) =>
            dayFormat.format(new Date(entry.createdAt)),
        ),
    ];

    return (
        <div className="flex flex-col gap-4">
            <h2 className="ps-3 pt-2 font-heading text-base font-medium">
                Activity
            </h2>
            <div className="flex flex-col gap-5 ps-3">
                {days.map(([day, entries]) => (
                    <section key={day} className="flex flex-col gap-3">
                        <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {day}
                        </h3>
                        <ol className="flex flex-col border-s">
                            {entries.map((entry) => {
                                const Icon = iconFor(entry.kind);

                                return (
                                    <li
                                        key={entry.id}
                                        className="relative flex flex-col gap-0.5 ps-8 pb-4 last:pb-0"
                                    >
                                        <span
                                            aria-hidden
                                            className="absolute start-0 top-0 flex size-6 -translate-x-1/2 items-center justify-center rounded-full bg-muted text-muted-foreground ring-4 ring-card"
                                        >
                                            <Icon className="size-3.5" />
                                        </span>
                                        <div className="flex items-baseline justify-between gap-3 pe-3">
                                            <p className="text-sm">
                                                {entry.text}
                                            </p>
                                            <time
                                                dateTime={entry.createdAt}
                                                className="shrink-0 text-xs text-muted-foreground tabular-nums"
                                            >
                                                {timeFormat.format(
                                                    new Date(entry.createdAt),
                                                )}
                                            </time>
                                        </div>
                                        {entry.reason && (
                                            <p className="text-sm text-muted-foreground">
                                                {entry.reason}
                                            </p>
                                        )}
                                        <p className="text-xs text-muted-foreground">
                                            {entry.causerName ?? 'System'}
                                        </p>
                                    </li>
                                );
                            })}
                        </ol>
                    </section>
                ))}
            </div>
            <PaginationFooter pagination={toPaginationData(activity.meta)} />
        </div>
    );
}
