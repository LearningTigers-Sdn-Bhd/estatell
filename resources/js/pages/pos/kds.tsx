import { Head, Link, router } from '@inertiajs/react';
import {
    ChefHat,
    History,
    Monitor,
    Printer,
    RefreshCw,
    RotateCcw,
    Volume2,
    VolumeX,
    WifiOff,
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
import { PosNavbar } from '@/components/pms/pos/pos-navbar';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as posIndex } from '@/routes/pos';
import { show as kdsShow, version as kdsVersion } from '@/routes/pos/kds';
import { status as kdsLineStatus } from '@/routes/pos/kds/lines';
import { bump as kdsBump, recall as kdsRecall } from '@/routes/pos/kds/orders';
import { show as odsShow } from '@/routes/pos/ods';
import { index as kitchenSettingsIndex } from '@/routes/settings/pos/kitchen';

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
    locked?: boolean;
    canSell?: boolean;
    canManageMenu?: boolean;
    stations: Station[];
    station: Station | null;
    version: string;
    tickets: KitchenTicket[];
    recallable: Recallable[];
};

type View = 'kitchen' | 'window' | 'all-day';

const POLL_MS = 4000;
const CLOCK_MS = 1000;

function formatClock(timestamp: number): string {
    return new Date(timestamp).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatBumpedTime(iso: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

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
        // Browser autoplay policy may block audio until first user gesture.
    }
}

/**
 * Web KDS for one kitchen station: Kitchen (cook), Window (runners), and All-day tally.
 * Polls a short version hash and reloads tickets only when the version changes.
 */
export default function PosKds({
    locked = false,
    canSell = false,
    canManageMenu = false,
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
    const [pendingKey, setPendingKey] = useState<string | null>(null);
    const [pollError, setPollError] = useState(false);
    const [now, setNow] = useState(() => Date.now());
    const latestVersion = useRef(version);
    const failedPolls = useRef(0);
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
                    failedPolls.current += 1;

                    if (failedPolls.current >= 2) {
                        setPollError(true);
                    }

                    return;
                }

                failedPolls.current = 0;
                setPollError(false);

                const { version: current } = (await response.json()) as {
                    version: string;
                };

                if (current !== latestVersion.current) {
                    router.reload({
                        only: ['tickets', 'recallable', 'version'],
                    });
                }
            } catch {
                failedPolls.current += 1;

                if (failedPolls.current >= 2) {
                    setPollError(true);
                }
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

        return [...totals.values()].sort((a, b) => b.quantity - a.quantity);
    }, [tickets]);

    const backLink = canSell
        ? { href: posIndex.url(), label: 'POS' }
        : !locked
          ? { href: dashboard.url(), label: 'Back office' }
          : null;

    if (station === null) {
        return (
            <>
                <Head title="Kitchen display" />
                <PosNavbar
                    title="Kitchen display"
                    titleAs="h1"
                    back={backLink}
                    showUser={!locked}
                />
                <main className="flex flex-1 flex-col justify-center bg-muted/50 px-4 py-8 sm:px-6">
                    <div className="mx-auto w-full max-w-2xl">
                        <Card>
                            <CardContent className="py-10">
                                <EmptyState
                                    icon={ChefHat}
                                    title="No kitchen station configured"
                                    description="Add a kitchen station with display mode enabled, then route menu categories or items to it."
                                    action={
                                        canManageMenu ? (
                                            <Link
                                                href={kitchenSettingsIndex.url()}
                                                className={`${buttonVariants()} min-h-11`}
                                            >
                                                Open kitchen settings
                                            </Link>
                                        ) : undefined
                                    }
                                />
                            </CardContent>
                        </Card>
                    </div>
                </main>
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

    const refreshNow = () => {
        router.reload({
            only: ['tickets', 'recallable', 'version'],
            onStart: () => setPendingKey('refresh'),
            onFinish: () => {
                setPendingKey(null);
                failedPolls.current = 0;
                setPollError(false);
            },
        });
    };

    const views = [
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
        { key: 'all-day' as const, label: 'All day', count: allDayTotal },
    ];

    return (
        <>
            <Head title={`KDS · ${station.name}`} />

            <PosNavbar
                title={station.name}
                subtitle={station.outlet}
                titleAs="h1"
                back={backLink}
                leftExtra={
                    stations.length > 1 ? (
                        <>
                            <span
                                aria-hidden="true"
                                className="hidden h-5 w-px shrink-0 bg-sidebar-border sm:block"
                            />
                            <nav
                                aria-label="Kitchen stations"
                                className="flex items-center gap-0.5"
                            >
                                {stations.map((row) => (
                                    <Link
                                        key={row.id}
                                        href={kdsShow.url({
                                            station: row.id,
                                        })}
                                        aria-current={
                                            row.id === station.id
                                                ? 'page'
                                                : undefined
                                        }
                                        className={cn(
                                            buttonVariants({
                                                variant:
                                                    row.id === station.id
                                                        ? 'secondary'
                                                        : 'ghost',
                                            }),
                                            'min-h-11 px-3',
                                        )}
                                    >
                                        {row.name}
                                    </Link>
                                ))}
                            </nav>
                        </>
                    ) : undefined
                }
                center={
                    <div
                        className="inline-flex items-center gap-0.5 rounded-lg bg-muted p-1"
                        role="group"
                        aria-label="Kitchen view"
                    >
                        {views.map((option) => {
                            const active = view === option.key;

                            return (
                                <button
                                    key={option.key}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => setView(option.key)}
                                    className={cn(
                                        'inline-flex min-h-11 items-center gap-1.5 rounded-md px-3.5 text-sm font-medium tabular-nums transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground',
                                        active
                                            ? 'bg-card font-semibold text-foreground shadow-xs'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    <span>{option.label}</span>
                                    <span
                                        className={cn(
                                            'rounded px-1.5 py-0.5 text-xs font-semibold',
                                            active
                                                ? 'bg-muted text-foreground'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {option.count}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                }
                right={
                    <>
                        <span
                            className="hidden pr-1 font-mono text-sm font-medium text-muted-foreground tabular-nums xl:inline"
                            aria-label="Current time"
                        >
                            {formatClock(now)}
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            className="min-h-11 gap-1.5 px-3 tabular-nums"
                            aria-expanded={showRecall}
                            onClick={() => setShowRecall(true)}
                        >
                            <History
                                data-icon="inline-start"
                                aria-hidden="true"
                            />
                            <span>
                                {recallable.length > 0
                                    ? `Recall (${recallable.length})`
                                    : 'Recall'}
                            </span>
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-11"
                            aria-pressed={muted}
                            aria-label={
                                muted
                                    ? 'Unmute overdue chime'
                                    : 'Mute overdue chime'
                            }
                            onClick={() => setMuted((value) => !value)}
                        >
                            {muted ? (
                                <VolumeX aria-hidden="true" />
                            ) : (
                                <Volume2 aria-hidden="true" />
                            )}
                        </Button>
                        {station.outletId !== undefined && (
                            <Link
                                href={odsShow.url({ outlet: station.outletId })}
                                target="_blank"
                                rel="noopener noreferrer"
                                className={buttonVariants({
                                    variant: 'ghost',
                                    className: 'min-h-11 px-3',
                                })}
                            >
                                <Monitor
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                                <span className="hidden md:inline">
                                    Pickup board
                                </span>
                                <span className="sr-only md:hidden">
                                    Pickup board
                                </span>
                            </Link>
                        )}
                    </>
                }
            />

            {pollError && (
                <div
                    role="status"
                    className="flex flex-wrap items-center justify-between gap-3 border-b border-warning/40 bg-warning/10 px-4 py-2.5 text-sm sm:px-6"
                >
                    <div className="flex items-center gap-2 font-medium text-warning-text">
                        <WifiOff
                            className="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            Live updates paused while reconnecting to the
                            server.
                        </span>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11 bg-card"
                        disabled={pendingKey === 'refresh'}
                        focusableWhenDisabled
                        onClick={refreshNow}
                    >
                        {pendingKey === 'refresh' ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <RefreshCw
                                data-icon="inline-start"
                                aria-hidden="true"
                            />
                        )}
                        Refresh now
                    </Button>
                </div>
            )}

            <main className="flex flex-1 flex-col bg-muted/50 p-4 sm:p-6">
                {view === 'all-day' && (
                    <section
                        aria-labelledby="all-day-heading"
                        className="mx-auto flex w-full max-w-4xl flex-col overflow-hidden rounded-lg border bg-card"
                    >
                        <div className="flex items-baseline justify-between gap-4 border-b px-6 py-4">
                            <div>
                                <h2
                                    id="all-day-heading"
                                    className="font-heading text-lg font-semibold text-foreground"
                                >
                                    All-day tally
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Total open dishes to cook at {station.name}.
                                </p>
                            </div>
                            <span className="font-heading text-2xl font-bold text-foreground tabular-nums">
                                {formatKitchenQuantity(allDayTotal)}
                            </span>
                        </div>
                        {allDay.length === 0 ? (
                            <div className="py-12">
                                <EmptyState
                                    icon={ChefHat}
                                    title="All caught up"
                                    description="No open dishes are waiting at this station right now."
                                />
                            </div>
                        ) : (
                            <ul
                                role="list"
                                className="grid grid-cols-1 divide-y md:grid-cols-2 md:divide-x"
                            >
                                {allDay.map((row) => (
                                    <li
                                        key={`${row.name}|${row.modifiers.join(',')}`}
                                        className="flex items-center gap-4 px-6 py-4"
                                    >
                                        <span className="w-12 shrink-0 font-heading text-3xl font-bold text-foreground tabular-nums">
                                            {formatKitchenQuantity(
                                                row.quantity,
                                            )}
                                        </span>
                                        <div className="flex min-w-0 flex-col">
                                            <span className="text-base font-semibold text-foreground">
                                                {row.name}
                                            </span>
                                            {row.modifiers.length > 0 && (
                                                <span className="text-sm text-muted-foreground">
                                                    {row.modifiers.join(', ')}
                                                </span>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {view === 'kitchen' &&
                    (kitchenTickets.length === 0 ? (
                        <div className="flex flex-1 items-center justify-center py-12">
                            <EmptyState
                                icon={ChefHat}
                                title="Pass is clear"
                                description={
                                    windowTickets.length > 0
                                        ? `No dishes cooking. ${windowTickets.length} ${windowTickets.length === 1 ? 'order is' : 'orders are'} waiting in Window.`
                                        : 'Orders sent from the register or guest QR appear here automatically.'
                                }
                                action={
                                    windowTickets.length > 0 ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="min-h-11 bg-card"
                                            onClick={() => setView('window')}
                                        >
                                            Open Window ({windowTickets.length})
                                        </Button>
                                    ) : undefined
                                }
                            />
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                            {kitchenTickets.map((ticket) => {
                                const bumpKey = `bump-${ticket.id}`;

                                return (
                                    <KitchenTicketCard
                                        key={ticket.id}
                                        ticket={ticket}
                                        tone={slaFor(ticket)}
                                        age={ageLabel(ticket.firedAt, now)}
                                        onPrint={() => setPrintTicket(ticket)}
                                        lineAction={(line: KitchenLine) => {
                                            const lineKey = `line-${line.id}`;

                                            return {
                                                label:
                                                    line.status === 'fired'
                                                        ? 'Start'
                                                        : 'Ready',
                                                busy: pendingKey === lineKey,
                                                disabled: pendingKey !== null,
                                                onClick: () =>
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
                                                        {
                                                            onStart: () =>
                                                                setPendingKey(
                                                                    lineKey,
                                                                ),
                                                            onFinish: () =>
                                                                setPendingKey(
                                                                    null,
                                                                ),
                                                        },
                                                    ),
                                            };
                                        }}
                                        footer={
                                            <Button
                                                type="button"
                                                className="min-h-11 w-full bg-foreground font-semibold text-background hover:bg-foreground/90"
                                                disabled={pendingKey !== null}
                                                focusableWhenDisabled
                                                onClick={() =>
                                                    postKitchen(
                                                        kdsBump.url({
                                                            station: station.id,
                                                            order: ticket.id,
                                                        }),
                                                        {},
                                                        {
                                                            onStart: () =>
                                                                setPendingKey(
                                                                    bumpKey,
                                                                ),
                                                            onFinish: () =>
                                                                setPendingKey(
                                                                    null,
                                                                ),
                                                        },
                                                    )
                                                }
                                            >
                                                {pendingKey === bumpKey && (
                                                    <Spinner data-icon="inline-start" />
                                                )}
                                                Bump ticket
                                            </Button>
                                        }
                                    />
                                );
                            })}
                        </div>
                    ))}

                {view === 'window' &&
                    (windowTickets.length === 0 ? (
                        <div className="flex flex-1 items-center justify-center py-12">
                            <EmptyState
                                icon={ChefHat}
                                title="Nothing waiting at the window"
                                description="Dishes marked Ready or bumped from Kitchen move here for runners."
                            />
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                            {windowTickets.map((ticket) => (
                                <KitchenTicketCard
                                    key={ticket.id}
                                    ticket={ticket}
                                    tone={slaFor(ticket)}
                                    age={ageLabel(ticket.firedAt, now)}
                                    onPrint={() => setPrintTicket(ticket)}
                                    lineAction={(line: KitchenLine) => {
                                        const lineKey = `serve-${line.id}`;

                                        return {
                                            label: 'Served',
                                            busy: pendingKey === lineKey,
                                            disabled: pendingKey !== null,
                                            onClick: () =>
                                                patchKitchen(
                                                    kdsLineStatus.url({
                                                        line: line.id,
                                                    }),
                                                    { status: 'served' },
                                                    {
                                                        onStart: () =>
                                                            setPendingKey(
                                                                lineKey,
                                                            ),
                                                        onFinish: () =>
                                                            setPendingKey(null),
                                                    },
                                                ),
                                        };
                                    }}
                                />
                            ))}
                        </div>
                    ))}
            </main>

            <Sheet open={showRecall} onOpenChange={setShowRecall}>
                <SheetContent className="data-[side=right]:w-full data-[side=right]:sm:max-w-md">
                    <SheetHeader>
                        <SheetTitle>Recall ticket</SheetTitle>
                        <SheetDescription>
                            Recently bumped tickets at {station.name}. Recalling
                            moves ready dishes back to Preparing.
                        </SheetDescription>
                    </SheetHeader>
                    <div className="flex flex-1 flex-col gap-2 overflow-y-auto px-4 pb-6">
                        {recallable.length === 0 ? (
                            <EmptyState
                                icon={History}
                                title="No bumped tickets"
                                description="Tickets bumped from Kitchen appear here while their dishes are still ready for pickup."
                            />
                        ) : (
                            recallable.map((row) => {
                                const recallKey = `recall-${row.id}`;
                                const bumpedTime = formatBumpedTime(
                                    row.bumpedAt,
                                );

                                return (
                                    <Button
                                        key={row.id}
                                        type="button"
                                        variant="outline"
                                        className="min-h-12 justify-between px-4"
                                        disabled={pendingKey !== null}
                                        focusableWhenDisabled
                                        onClick={() =>
                                            postKitchen(
                                                kdsRecall.url({
                                                    station: station.id,
                                                    order: row.id,
                                                }),
                                                {},
                                                {
                                                    onStart: () =>
                                                        setPendingKey(
                                                            recallKey,
                                                        ),
                                                    onFinish: () => {
                                                        setPendingKey(null);
                                                        setShowRecall(false);
                                                    },
                                                },
                                            )
                                        }
                                    >
                                        <span className="flex items-center gap-2 font-medium">
                                            {pendingKey === recallKey ? (
                                                <Spinner data-icon="inline-start" />
                                            ) : (
                                                <RotateCcw
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {row.label}
                                        </span>
                                        {bumpedTime !== '' && (
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {bumpedTime}
                                            </span>
                                        )}
                                    </Button>
                                );
                            })
                        )}
                    </div>
                </SheetContent>
            </Sheet>

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
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setPrintTicket(null)}
                        >
                            Close
                        </Button>
                        <Button
                            type="button"
                            className="min-h-11"
                            onClick={() => window.print()}
                        >
                            <Printer
                                data-icon="inline-start"
                                aria-hidden="true"
                            />
                            Print docket
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
