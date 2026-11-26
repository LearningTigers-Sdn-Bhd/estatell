import { Head, Link, router } from '@inertiajs/react';
import {
    ChefHat,
    History,
    Monitor,
    Printer,
    Volume2,
    VolumeX,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import {
    ageLabel,
    formatKitchenQuantity,
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { version as kdsVersion, show as kdsShow } from '@/routes/pos/kds';
import { status as kdsLineStatus } from '@/routes/pos/kds/lines';
import { bump as kdsBump, recall as kdsRecall } from '@/routes/pos/kds/orders';
import { show as odsShow } from '@/routes/pos/ods';

type Station = {
    id: number;
    outletId?: number;
    name: string;
    outlet?: string;
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

function playAlertChime() {
    try {
        const AudioCtx =
            window.AudioContext ||
            (window as unknown as { webkitAudioContext?: typeof AudioContext })
                .webkitAudioContext;

        if (!AudioCtx) {
            return;
        }

        const ctx = new AudioCtx();
        const now = ctx.currentTime;

        [880, 1175].forEach((freq, idx) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.08, now + idx * 0.16);
            gain.gain.exponentialRampToValueAtTime(
                0.001,
                now + idx * 0.16 + 0.14,
            );
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(now + idx * 0.16);
            osc.stop(now + idx * 0.16 + 0.15);
        });
    } catch {
        // Ignore audio playback failures when browser autoplay policy blocks sound.
    }
}

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
    const [muted, setMuted] = useState(false);
    const [printTicket, setPrintTicket] = useState<KitchenTicket | null>(null);
    const [now, setNow] = useState(() => Date.now());
    const latestVersion = useRef(version);
    const alertedTickets = useRef<Set<number>>(new Set());

    useEffect(() => {
        latestVersion.current = version;
    }, [version]);

    useEffect(() => {
        if (station === null) {
            return;
        }

        const stationId = station.id;
        const timer = window.setInterval(async () => {
            try {
                const response = await fetch(
                    kdsVersion.url({ station: stationId }),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );

                if (!response.ok) {
                    return;
                }

                const { version: current } = (await response.json()) as {
                    version: string;
                };

                if (current !== latestVersion.current) {
                    router.reload({
                        only: ['tickets', 'recallable', 'version'],
                    });
                }
            } catch {
                // Keep polling quietly across transient network blips.
            }
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [station]);

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), CLOCK_MS);

        return () => window.clearInterval(timer);
    }, []);

    useEffect(() => {
        if (station === null || muted) {
            return;
        }

        let shouldChime = false;
        const activeIds = new Set<number>();

        for (const ticket of tickets) {
            activeIds.add(ticket.id);
            const tone = slaTone(
                ticket.firedAt,
                now,
                station.warnAfterMinutes,
                station.alertAfterMinutes,
            );

            if (
                tone === 'destructive' &&
                !alertedTickets.current.has(ticket.id)
            ) {
                alertedTickets.current.add(ticket.id);
                shouldChime = true;
            }
        }

        for (const id of alertedTickets.current) {
            if (!activeIds.has(id)) {
                alertedTickets.current.delete(id);
            }
        }

        if (shouldChime) {
            playAlertChime();
        }
    }, [tickets, now, station, muted]);

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

    const allDayTotal = allDay.reduce((sum, row) => sum + row.quantity, 0);

    return (
        <>
            <Head title={`KDS · ${station.name}`} />
            <div className="flex min-h-dvh flex-col bg-background">
                <header className="flex flex-wrap items-center gap-3 border-b bg-card px-4 py-3">
                    <div className="flex items-center gap-3">
                        <h1 className="font-heading text-xl font-semibold">
                            {station.name}
                        </h1>
                        {stations.length > 1 && (
                            <nav
                                aria-label="Kitchen stations"
                                className="flex flex-wrap gap-1.5"
                            >
                                {stations.map((row) => (
                                    <Link
                                        key={row.id}
                                        href={kdsShow.url({ station: row.id })}
                                        className={cn(
                                            buttonVariants({
                                                variant:
                                                    row.id === station.id
                                                        ? 'secondary'
                                                        : 'ghost',
                                            }),
                                            'min-h-11',
                                        )}
                                    >
                                        {row.name}
                                    </Link>
                                ))}
                            </nav>
                        )}
                    </div>
                    <div className="ms-auto flex flex-wrap items-center gap-2">
                        <div
                            className="flex flex-wrap gap-1.5"
                            role="group"
                            aria-label="Kitchen view"
                        >
                            {(
                                [
                                    {
                                        key: 'kitchen' as const,
                                        label: 'Kitchen',
                                        count: kitchenTickets.length,
                                    },
                                    {
                                        key: 'window' as const,
                                        label: 'Window',
                                        count: windowTickets.length,
                                    },
                                    {
                                        key: 'all-day' as const,
                                        label: 'All day',
                                        count: allDayTotal,
                                    },
                                ] as const
                            ).map((option) => (
                                <Button
                                    key={option.key}
                                    type="button"
                                    aria-pressed={view === option.key}
                                    className="min-h-11 gap-2 tabular-nums"
                                    variant={
                                        view === option.key
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => setView(option.key)}
                                >
                                    <span>{option.label}</span>
                                    <span
                                        className={cn(
                                            'rounded-md px-1.5 py-0.5 text-xs font-semibold',
                                            view === option.key
                                                ? 'bg-primary-foreground/20 text-primary-foreground'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {option.count}
                                    </span>
                                </Button>
                            ))}
                        </div>
                        <Button
                            type="button"
                            className="min-h-11"
                            variant={showRecall ? 'secondary' : 'outline'}
                            aria-pressed={showRecall}
                            aria-label="Recall a ticket"
                            onClick={() => setShowRecall((open) => !open)}
                        >
                            <History
                                data-icon="inline-start"
                                aria-hidden="true"
                            />
                            Recall
                        </Button>
                        <Button
                            type="button"
                            className="min-h-11"
                            variant="outline"
                            aria-pressed={muted}
                            aria-label={muted ? 'Unmute alerts' : 'Mute alerts'}
                            onClick={() => setMuted((value) => !value)}
                        >
                            {muted ? (
                                <VolumeX
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                            ) : (
                                <Volume2
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                            )}
                            {muted ? 'Muted' : 'Chime'}
                        </Button>
                        {station.outletId !== undefined && (
                            <Link
                                href={odsShow.url({ outlet: station.outletId })}
                                target="_blank"
                                rel="noopener noreferrer"
                                className={cn(
                                    buttonVariants({
                                        variant: 'outline',
                                    }),
                                    'min-h-11',
                                )}
                            >
                                <Monitor
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                                Pickup board
                            </Link>
                        )}
                    </div>
                </header>

                <main className="flex flex-1 flex-col gap-4 p-4">
                    {view === 'all-day' && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="font-heading">
                                    Still to cook
                                </CardTitle>
                                <CardDescription>
                                    Every open dish at this station, added up.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {allDay.length === 0 ? (
                                    <EmptyState
                                        icon={ChefHat}
                                        title="All caught up"
                                        description="No open dishes are waiting at this station right now."
                                    />
                                ) : (
                                    <ul
                                        role="list"
                                        className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3"
                                    >
                                        {allDay.map((row) => (
                                            <li
                                                key={`${row.name}|${row.modifiers.join(',')}`}
                                                className="flex items-center gap-3 rounded-xl border bg-background p-3.5"
                                            >
                                                <span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 font-heading text-xl font-semibold text-primary tabular-nums">
                                                    {formatKitchenQuantity(
                                                        row.quantity,
                                                    )}
                                                </span>
                                                <div className="flex min-w-0 flex-col">
                                                    <span className="font-medium text-foreground">
                                                        {row.name}
                                                    </span>
                                                    {row.modifiers.length >
                                                        0 && (
                                                        <span className="text-sm text-muted-foreground">
                                                            {row.modifiers.join(
                                                                ', ',
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {view === 'kitchen' &&
                        (kitchenTickets.length === 0 ? (
                            <EmptyState
                                icon={ChefHat}
                                title="No tickets to cook"
                                description="New orders sent from the register or guest QR appear here automatically."
                            />
                        ) : (
                            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                {kitchenTickets.map((ticket) => (
                                    <KitchenTicketCard
                                        key={ticket.id}
                                        ticket={ticket}
                                        tone={slaFor(ticket)}
                                        age={ageLabel(ticket.firedAt, now)}
                                        onPrint={() => setPrintTicket(ticket)}
                                        actions={(line: KitchenLine) => (
                                            <Button
                                                type="button"
                                                className="min-h-11 shrink-0"
                                                variant={
                                                    line.status === 'fired'
                                                        ? 'outline'
                                                        : 'secondary'
                                                }
                                                onClick={() =>
                                                    patchKitchen(
                                                        kdsLineStatus.url({
                                                            line: line.id,
                                                        }),
                                                        {
                                                            status:
                                                                line.status ===
                                                                'fired'
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
                                                type="button"
                                                className="mt-1 min-h-11 w-full font-semibold"
                                                onClick={() =>
                                                    postKitchen(
                                                        kdsBump.url({
                                                            station: station.id,
                                                            order: ticket.id,
                                                        }),
                                                    )
                                                }
                                            >
                                                Bump ticket
                                            </Button>
                                        }
                                    />
                                ))}
                            </div>
                        ))}

                    {view === 'window' &&
                        (windowTickets.length === 0 ? (
                            <EmptyState
                                icon={ChefHat}
                                title="Nothing ready for runners"
                                description="Dishes marked Ready or bumped from Kitchen move here for pickup."
                            />
                        ) : (
                            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                {windowTickets.map((ticket) => (
                                    <KitchenTicketCard
                                        key={ticket.id}
                                        ticket={ticket}
                                        tone={slaFor(ticket)}
                                        age={ageLabel(ticket.firedAt, now)}
                                        onPrint={() => setPrintTicket(ticket)}
                                        actions={(line: KitchenLine) => (
                                            <Button
                                                type="button"
                                                className="min-h-11 shrink-0"
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
                        ))}

                    {showRecall && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="font-heading">
                                    Recall
                                </CardTitle>
                                <CardDescription>
                                    The last bumped tickets. A recall puts their
                                    ready dishes back to Preparing.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-wrap gap-2">
                                {recallable.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        No bumped tickets to recall.
                                    </p>
                                )}
                                {recallable.map((row) => (
                                    <Button
                                        key={row.id}
                                        type="button"
                                        variant="outline"
                                        className="min-h-11"
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
                </main>
            </div>

            <Dialog
                open={printTicket !== null}
                onOpenChange={(open) => !open && setPrintTicket(null)}
            >
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Kitchen docket</DialogTitle>
                        <DialogDescription>
                            80 mm thermal printer formatted docket for{' '}
                            {station.name}.
                        </DialogDescription>
                    </DialogHeader>
                    {printTicket !== null && (
                        <div className="pos-print-area flex flex-col gap-2 rounded-lg border bg-background p-4 font-mono text-xs">
                            <div className="text-center text-sm font-semibold">
                                {station.name}
                            </div>
                            <div className="flex justify-between border-t pt-2 font-semibold">
                                <span>{printTicket.label}</span>
                                <span>{printTicket.diningKind ?? ''}</span>
                            </div>
                            <div className="flex flex-col gap-1.5 border-t pt-2">
                                {printTicket.lines.map((line) => (
                                    <div
                                        key={line.id}
                                        className="flex flex-col"
                                    >
                                        <span className="font-semibold">
                                            {formatKitchenQuantity(
                                                line.quantity,
                                            )}
                                            × {line.name}
                                            {line.voided ? ' (VOID)' : ''}
                                        </span>
                                        {line.modifiers.length > 0 && (
                                            <span>
                                                + {line.modifiers.join(', ')}
                                            </span>
                                        )}
                                        {line.note && (
                                            <span>Note: {line.note}</span>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setPrintTicket(null)}
                        >
                            Close
                        </Button>
                        <Button onClick={() => window.print()}>
                            <Printer data-icon="inline-start" />
                            Print docket
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
