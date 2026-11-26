import { Link, useHttp } from '@inertiajs/react';
import { Bus, ExternalLink, Mail, Phone, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { RoomTotalLabel } from '@/components/pms/room-total-label';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { peek as peekRoute } from '@/routes/group';

type Peek = App.Data.Group.GroupPeekData;

/** The reservation the panel shows: its property and id. Null closes the panel. */
export type PeekTarget = { propertyCode: string; reservationId: number } | null;

type State =
    | { status: 'loading' }
    | { status: 'ready'; peek: Peek }
    | { status: 'failed' };

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-2">
            <h3 className="text-sm font-medium">{title}</h3>
            {children}
        </section>
    );
}

/** Opens the reservation in its property, and the booker's profile when the role shows guests. */
function OpenLinks({ peek }: { peek: Peek }) {
    return (
        <div className="flex flex-wrap gap-2">
            <Link
                href={peek.reservationUrl}
                className={buttonVariants({ size: 'sm' })}
            >
                <ExternalLink data-icon="inline-start" />
                Open reservation
            </Link>
            {peek.guestUrl && (
                <Link
                    href={peek.guestUrl}
                    className={buttonVariants({
                        size: 'sm',
                        variant: 'outline',
                    })}
                >
                    <UserRound data-icon="inline-start" />
                    Guest profile
                </Link>
            )}
        </div>
    );
}

/**
 * The group side panel: a reservation of any property at a glance (booker, stay, rooms with their guests and
 * transfers, remarks, money), loaded when it opens, with links into the property at the top and the bottom. Used by
 * the group Reservations list, Timeline and Front desk reports. See docs/03-staff/05-group-mode.md#reservation-panel.
 */
export function ReservationPeekSheet({
    groupId,
    target,
    onClose,
}: {
    groupId: number;
    target: PeekTarget;
    onClose: () => void;
}) {
    const url =
        target === null
            ? ''
            : peekRoute.url({
                  propertyGroup: groupId,
                  propertyCode: target.propertyCode,
                  reservation: target.reservationId,
              });
    const http = useHttp<Record<string, never>, Peek>('get', url, {});
    const [state, setState] = useState<State>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (target === null) {
            return;
        }

        let current = true;

        setState({ status: 'loading' });
        http.submit()
            .then((peek) => current && setState({ status: 'ready', peek }))
            .catch(() => current && setState({ status: 'failed' }));

        return () => {
            current = false;
        };
        // The request depends only on the url and the attempt.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url, attempt]);

    const peek = state.status === 'ready' ? state.peek : null;

    return (
        <Sheet
            open={target !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent className="data-[side=right]:sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>
                        {peek ? (peek.booker ?? peek.reference) : 'Reservation'}
                    </SheetTitle>
                    <SheetDescription>
                        {peek
                            ? `${peek.reference} · ${peek.propertyName}`
                            : 'Loading the reservation…'}
                    </SheetDescription>
                    {peek && <OpenLinks peek={peek} />}
                </SheetHeader>
                <div className="flex flex-1 flex-col gap-5 overflow-y-auto px-4">
                    {state.status === 'loading' && (
                        <div aria-busy="true" className="flex flex-col gap-3">
                            <Skeleton className="h-6 w-32" />
                            <Skeleton className="h-24 w-full" />
                            <Skeleton className="h-32 w-full" />
                        </div>
                    )}
                    {state.status === 'failed' && (
                        <div className="flex items-center justify-between gap-3 rounded-lg bg-muted p-3 text-sm">
                            <p className="text-muted-foreground">
                                The reservation could not be loaded.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setAttempt((count) => count + 1)}
                            >
                                Try again
                            </Button>
                        </div>
                    )}
                    {peek && (
                        <>
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge status={peek.status} />
                                {Number(peek.balance) > 0 && (
                                    <span className="rounded-md bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning">
                                        Balance due
                                    </span>
                                )}
                                {peek.groupName && (
                                    <span className="text-sm text-muted-foreground">
                                        {peek.groupName}
                                    </span>
                                )}
                            </div>
                            {(peek.phone || peek.email || peek.company) && (
                                <Section title="Booker">
                                    <ul className="flex flex-col gap-1 text-sm">
                                        {peek.phone && (
                                            <li className="flex items-center gap-2">
                                                <Phone
                                                    aria-hidden
                                                    className="size-4 text-muted-foreground"
                                                />
                                                <a
                                                    href={`tel:${peek.phone}`}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    {peek.phone}
                                                </a>
                                            </li>
                                        )}
                                        {peek.email && (
                                            <li className="flex items-center gap-2">
                                                <Mail
                                                    aria-hidden
                                                    className="size-4 text-muted-foreground"
                                                />
                                                <a
                                                    href={`mailto:${peek.email}`}
                                                    className="break-all underline-offset-4 hover:underline"
                                                >
                                                    {peek.email}
                                                </a>
                                            </li>
                                        )}
                                        {peek.company && (
                                            <li className="text-muted-foreground">
                                                {peek.company}
                                            </li>
                                        )}
                                    </ul>
                                </Section>
                            )}
                            <Section title="Stay">
                                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                    <dt className="text-muted-foreground">
                                        Arrives
                                    </dt>
                                    <dd>
                                        <DateText date={peek.arrival} />
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Departs
                                    </dt>
                                    <dd>
                                        <DateText date={peek.departure} />
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Nights
                                    </dt>
                                    <dd className="tabular-nums">
                                        {peek.nights}
                                    </dd>
                                    {peek.source && (
                                        <>
                                            <dt className="text-muted-foreground">
                                                Source
                                            </dt>
                                            <dd>{peek.source}</dd>
                                        </>
                                    )}
                                </dl>
                            </Section>
                            <Section
                                title={
                                    peek.units.length === 1
                                        ? 'Room'
                                        : `Rooms (${peek.units.length})`
                                }
                            >
                                <ul className="flex flex-col divide-y rounded-lg border">
                                    {peek.units.map((unit) => (
                                        <li
                                            key={unit.id}
                                            className="flex flex-col gap-1 p-3 text-sm"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="font-medium">
                                                    {unit.label}
                                                </span>
                                                <StatusBadge
                                                    status={unit.status}
                                                />
                                            </div>
                                            <span className="text-muted-foreground">
                                                {unit.adults} adult
                                                {unit.adults === 1 ? '' : 's'}
                                                {unit.children > 0 &&
                                                    `, ${unit.children} child${unit.children === 1 ? '' : 'ren'}`}
                                            </span>
                                            {unit.party && (
                                                <span>{unit.party}</span>
                                            )}
                                            {[
                                                ['In', unit.transferIn],
                                                ['Out', unit.transferOut],
                                            ]
                                                .filter(([, text]) => text)
                                                .map(([direction, text]) => (
                                                    <span
                                                        key={direction}
                                                        className="flex items-center gap-1.5 text-muted-foreground"
                                                    >
                                                        <Bus
                                                            aria-hidden
                                                            className="size-3.5"
                                                        />
                                                        {direction}: {text}
                                                    </span>
                                                ))}
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                            {Object.keys(peek.remarks).length > 0 && (
                                <Section title="Remarks">
                                    <dl className="flex flex-col gap-1.5 text-sm">
                                        {Object.entries(peek.remarks).map(
                                            ([kind, text]) => (
                                                <div key={kind}>
                                                    <dt className="text-muted-foreground">
                                                        {kind}
                                                    </dt>
                                                    <dd>{text}</dd>
                                                </div>
                                            ),
                                        )}
                                    </dl>
                                </Section>
                            )}
                        </>
                    )}
                </div>
                {peek && (
                    <SheetFooter>
                        <dl className="flex flex-col gap-1.5 rounded-lg bg-muted p-3 text-sm">
                            <div className="flex items-center justify-between gap-3">
                                <dt>
                                    <RoomTotalLabel />
                                </dt>
                                <dd>
                                    <MoneyText
                                        amount={peek.total}
                                        currency={peek.currency}
                                        plain
                                    />
                                </dd>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <dt>Paid</dt>
                                <dd>
                                    <MoneyText
                                        amount={peek.paid}
                                        currency={peek.currency}
                                        plain
                                    />
                                </dd>
                            </div>
                            <Separator className="my-1" />
                            <div className="flex items-center justify-between gap-3">
                                <dt className="font-medium">Balance</dt>
                                <dd
                                    className={cn(
                                        'font-medium',
                                        Number(peek.balance) > 0 &&
                                            'text-destructive',
                                    )}
                                >
                                    <MoneyText
                                        amount={peek.balance}
                                        currency={peek.currency}
                                        plain
                                    />
                                </dd>
                            </div>
                        </dl>
                        <OpenLinks peek={peek} />
                    </SheetFooter>
                )}
            </SheetContent>
        </Sheet>
    );
}
