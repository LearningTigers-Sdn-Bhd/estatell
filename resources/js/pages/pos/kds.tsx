import { Head, Link, router } from '@inertiajs/react';
import { ChefHat, History } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import {
    ageLabel,
    KitchenTicketCard,
    patchKitchen,
    postKitchen,
    slaTone,
} from '@/components/pms/pos/pos-kds-ticket';
import type {
    KitchenLine,
    KitchenTicket,
} from '@/components/pms/pos/pos-kds-ticket';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { version as kdsVersion, show as kdsShow } from '@/routes/pos/kds';
import { status as kdsLineStatus } from '@/routes/pos/kds/lines';
import { bump as kdsBump, recall as kdsRecall } from '@/routes/pos/kds/orders';

type Station = {
    id: number;
    name: string;
    outlet: string;
    warnAfterMinutes: number;
    alertAfterMinutes: number;
};

type Recallable = { id: number; label: string; bumpedAt: string };

type Props = {
    stations: Station[];
    station: Station | null;
    version: string;
    tickets: KitchenTicket[];
    recallable: Recallable[];
};

type View = 'kitchen' | 'window' | 'all-day';

const POLL_MS = 4000;
const CLOCK_MS = 1000;

/**
 * The Web KDS of one station. The Kitchen view tickets cook (tap a dish to start, then to ready, Bump the ticket). The
 * Window view lists ready food for runners, and Served closes a line. All-Day adds up the dishes still to cook. The view
 * switch works on the loaded tickets, with no request. The screen polls the station's version and reloads its tickets only
 * when it changes. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
export default function PosKds({
    stations,
    station,
    version,
    tickets,
    recallable,
}: Props) {
    const [view, setView] = useState<View>('kitchen');
    const [showRecall, setShowRecall] = useState(false);
    const [now, setNow] = useState(() => Date.now());
    const latestVersion = useRef(version);

    useEffect(() => {
        latestVersion.current = version;
    }, [version]);

    useEffect(() => {
        if (station === null) {
            return;
        }

        const stationId = station.id;
        const timer = window.setInterval(async () => {
            const response = await fetch(
                kdsVersion.url({ station: stationId }),
                { headers: { Accept: 'application/json' } },
            );

            if (!response.ok) {
                return;
            }

            const { version: current } = (await response.json()) as {
                version: string;
            };

            if (current !== latestVersion.current) {
                router.reload({ only: ['tickets', 'recallable', 'version'] });
            }
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [station]);

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), CLOCK_MS);

        return () => window.clearInterval(timer);
    }, []);

    const allDay = useMemo(() => {
        const totals = new Map<
            string,
            { name: string; modifiers: string[]; quantity: number }
        >();

        tickets.forEach((ticket) =>
            ticket.lines
                .filter(
                    (line) =>
                        line.status === 'fired' || line.status === 'preparing',
                )
                .forEach((line) => {
                    const key = `${line.name}|${line.modifiers.join(',')}`;
                    const row = totals.get(key) ?? {
                        name: line.name,
                        modifiers: line.modifiers,
                        quantity: 0,
                    };
                    row.quantity += Number(line.quantity);
                    totals.set(key, row);
                }),
        );

        return [...totals.values()];
    }, [tickets]);

    if (station === null) {
        return (
            <>
                <Head title="Kitchen display" />
                <div className="p-4">
                    <EmptyState
                        icon={ChefHat}
                        title="No kitchen station"
                        description="Add a kitchen station in POS settings, and route the menu to it."
                    />
                </div>
            </>
        );
    }

    const slaFor = (ticket: KitchenTicket) =>
        slaTone(
            ticket.firedAt,
            now,
            station.warnAfterMinutes,
            station.alertAfterMinutes,
        );

    const kitchenTickets = tickets
        .map((ticket) => ({
            ...ticket,
            lines: ticket.lines.filter(
                (line) =>
                    line.status === 'fired' ||
                    line.status === 'preparing' ||
                    line.voided,
            ),
        }))
        .filter((ticket) => ticket.lines.length > 0);

    const windowTickets = tickets
        .map((ticket) => ({
            ...ticket,
            lines: ticket.lines.filter((line) => line.status === 'ready'),
        }))
        .filter((ticket) => ticket.lines.length > 0);

    return (
        <>
            <Head title={`KDS · ${station.name}`} />
            <div className="flex min-h-svh flex-col gap-4 p-4">
                <header className="flex flex-wrap items-center gap-3">
                    <h1 className="text-2xl font-semibold">{station.name}</h1>
                    <nav className="flex flex-wrap gap-2">
                        {stations.map((row) => (
                            <Link
                                key={row.id}
                                href={kdsShow.url({ station: row.id })}
                                className={buttonVariants({
                                    variant:
                                        row.id === station.id
                                            ? 'default'
                                            : 'outline',
                                    size: 'sm',
                                })}
                            >
                                {row.name}
                            </Link>
                        ))}
                    </nav>
                    <div className="ms-auto flex flex-wrap gap-2">
                        {(['kitchen', 'window', 'all-day'] as const).map(
                            (option) => (
                                <Button
                                    key={option}
                                    size="lg"
                                    variant={
                                        view === option ? 'default' : 'outline'
                                    }
                                    onClick={() => setView(option)}
                                >
                                    {option === 'kitchen'
                                        ? 'Kitchen'
                                        : option === 'window'
                                          ? 'Window'
                                          : 'All day'}
                                </Button>
                            ),
                        )}
                        <Button
                            size="lg"
                            variant="outline"
                            aria-label="Recall a ticket"
                            onClick={() => setShowRecall((open) => !open)}
                        >
                            <History data-icon="inline-start" />
                            Recall
                        </Button>
                    </div>
                </header>

                {view === 'all-day' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Still to cook</CardTitle>
                            <CardDescription>
                                Every open dish at this station, added up.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                            {allDay.length === 0 && (
                                <p className="text-muted-foreground">
                                    Nothing to cook.
                                </p>
                            )}
                            {allDay.map((row) => (
                                <p
                                    key={`${row.name}|${row.modifiers.join(',')}`}
                                    className="text-lg font-medium tabular-nums"
                                >
                                    {row.quantity}× {row.name}
                                    {row.modifiers.length > 0 && (
                                        <span className="text-base text-muted-foreground">
                                            {' '}
                                            ({row.modifiers.join(', ')})
                                        </span>
                                    )}
                                </p>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {view === 'kitchen' && (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        {kitchenTickets.length === 0 && (
                            <p className="text-muted-foreground">
                                No tickets to cook.
                            </p>
                        )}
                        {kitchenTickets.map((ticket) => (
                            <KitchenTicketCard
                                key={ticket.id}
                                ticket={ticket}
                                tone={slaFor(ticket)}
                                age={ageLabel(ticket.firedAt, now)}
                                actions={(line: KitchenLine) => (
                                    <Button
                                        size="lg"
                                        variant="outline"
                                        onClick={() =>
                                            patchKitchen(
                                                kdsLineStatus.url({
                                                    line: line.id,
                                                }),
                                                {
                                                    status:
                                                        line.status === 'fired'
                                                            ? 'preparing'
                                                            : 'ready',
                                                },
                                            )
                                        }
                                    >
                                        {line.status === 'fired'
                                            ? 'Start'
                                            : 'Ready'}
                                    </Button>
                                )}
                                footer={
                                    <Button
                                        size="lg"
                                        className={cn('mt-2 w-full')}
                                        onClick={() =>
                                            postKitchen(
                                                kdsBump.url({
                                                    station: station.id,
                                                    order: ticket.id,
                                                }),
                                            )
                                        }
                                    >
                                        Bump
                                    </Button>
                                }
                            />
                        ))}
                    </div>
                )}

                {view === 'window' && (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        {windowTickets.length === 0 && (
                            <p className="text-muted-foreground">
                                Nothing ready for the runners.
                            </p>
                        )}
                        {windowTickets.map((ticket) => (
                            <KitchenTicketCard
                                key={ticket.id}
                                ticket={ticket}
                                tone={slaFor(ticket)}
                                age={ageLabel(ticket.firedAt, now)}
                                actions={(line: KitchenLine) => (
                                    <Button
                                        size="lg"
                                        onClick={() =>
                                            patchKitchen(
                                                kdsLineStatus.url({
                                                    line: line.id,
                                                }),
                                                { status: 'served' },
                                            )
                                        }
                                    >
                                        Served
                                    </Button>
                                )}
                            />
                        ))}
                    </div>
                )}

                {showRecall && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Recall</CardTitle>
                            <CardDescription>
                                The last bumped tickets. A recall puts their
                                ready dishes back to Preparing.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {recallable.length === 0 && (
                                <p className="text-muted-foreground">
                                    No bumped tickets to recall.
                                </p>
                            )}
                            {recallable.map((row) => (
                                <Button
                                    key={row.id}
                                    variant="outline"
                                    size="lg"
                                    onClick={() =>
                                        postKitchen(
                                            kdsRecall.url({
                                                station: station.id,
                                                order: row.id,
                                            }),
                                        )
                                    }
                                >
                                    {row.label}
                                </Button>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
