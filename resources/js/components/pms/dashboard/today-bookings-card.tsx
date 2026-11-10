import { Link } from '@inertiajs/react';
import {
    BedDouble,
    CalendarDays,
    Clock,
    Inbox,
    NotebookText,
} from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

export function TodayBookingsSkeleton() {
    return (
        <Card className="min-h-60 flex-1">
            <CardHeader>
                <Skeleton className="h-5 w-40" />
            </CardHeader>
            <CardContent className="flex flex-col gap-6">
                {[0, 1, 2].map((key) => (
                    <div key={key} className="flex flex-col gap-2">
                        <Skeleton className="h-4 w-2/3" />
                        <Skeleton className="h-4 w-1/2" />
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

/** Bookings made on the business date, newest first. A click anywhere on a row opens the booking. */
export function TodayBookingsCard({
    bookings,
}: {
    bookings: App.Data.Dashboard.TodayBookingsData;
}) {
    return (
        <Card className="min-h-80 flex-1">
            <CardHeader>
                <div className="flex items-center gap-2">
                    <NotebookText
                        aria-hidden
                        className="size-5 shrink-0 text-muted-foreground"
                    />
                    <div className="flex min-w-0 flex-col gap-1">
                        <CardTitle>Today's booking list</CardTitle>
                        <CardDescription>
                            <DateText date={bookings.date} />
                        </CardDescription>
                    </div>
                </div>
                <CardAction>
                    <Badge variant="outline" className="tabular-nums">
                        {bookings.total}{' '}
                        {bookings.total === 1 ? 'booking' : 'bookings'}
                    </Badge>
                </CardAction>
            </CardHeader>
            <CardContent className="flex min-h-0 flex-1 flex-col px-0">
                {bookings.rows.length === 0 ? (
                    <div className="flex flex-1 items-center justify-center px-(--card-spacing)">
                        <EmptyState
                            icon={Inbox}
                            title="No new bookings today"
                            description="Bookings made today show up here."
                        />
                    </div>
                ) : (
                    <ul className="divide-y border-y xl:max-h-112 xl:overflow-y-auto">
                        {bookings.rows.map((row) => (
                            <li
                                key={row.id}
                                className="relative flex flex-col gap-1 px-(--card-spacing) py-3 transition-colors hover:bg-muted/50"
                            >
                                <Link
                                    href={row.url}
                                    className="min-w-0 truncate rounded-sm font-medium outline-none after:absolute after:inset-0 focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    {row.guest}
                                </Link>
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground tabular-nums">
                                    <span className="flex items-center gap-1">
                                        <Clock
                                            aria-hidden
                                            className="size-3.5"
                                        />
                                        {row.created}
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <CalendarDays
                                            aria-hidden
                                            className="size-3.5"
                                        />
                                        <DateText date={row.arrival} /> –{' '}
                                        <DateText date={row.departure} />
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <BedDouble
                                            aria-hidden
                                            className="size-3.5"
                                        />
                                        {row.rooms}{' '}
                                        {row.rooms === 1 ? 'room' : 'rooms'}
                                    </span>
                                    <span>{row.reference}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
