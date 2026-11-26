import { Head, router } from '@inertiajs/react';
import {
    Bell,
    CheckCircle2,
    ChefHat,
    Maximize2,
    Minimize2,
    Volume2,
    VolumeX,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { PosNavbar } from '@/components/pms/pos/pos-navbar';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { version as odsVersion } from '@/routes/pos/ods';

type Ticket = { id: number; label: string };

type Props = {
    outlet: { id: number; name: string };
    version: string;
    board: { preparing: Ticket[]; ready: Ticket[] };
};

const POLL_MS = 4000;

function playReadyChime() {
    try {
        const AudioCtx =
            window.AudioContext ||
            (window as unknown as { webkitAudioContext?: typeof AudioContext })
                .webkitAudioContext;

        if (!AudioCtx) {
            return;
        }

        const ctx = new AudioCtx();
        const start = ctx.currentTime;

        [523.25, 659.25, 783.99].forEach((freq, idx) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.06, start + idx * 0.12);
            gain.gain.exponentialRampToValueAtTime(
                0.001,
                start + idx * 0.12 + 0.35,
            );
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(start + idx * 0.12);
            osc.stop(start + idx * 0.12 + 0.36);
        });
    } catch {
        // Browser autoplay policy may block audio until first user gesture.
    }
}

/**
 * Guest-facing pickup signage: adaptive spotlight for Ready orders on the brand-forest wall
 * paired with a calm Preparing queue. Polls outlet version and reloads only on change.
 */
export default function PosOds({ outlet, version, board }: Props) {
    const latestVersion = useRef(version);
    const knownReadyIds = useRef<Set<number>>(
        new Set(board.ready.map((ticket) => ticket.id)),
    );
    const [now, setNow] = useState(() => new Date());
    const [muted, setMuted] = useState(true);
    const [isFullscreen, setIsFullscreen] = useState(false);

    useEffect(() => {
        latestVersion.current = version;
    }, [version]);

    useEffect(() => {
        const clockTimer = window.setInterval(() => setNow(new Date()), 15000);

        return () => window.clearInterval(clockTimer);
    }, []);

    useEffect(() => {
        const onFullscreenChange = () =>
            setIsFullscreen(Boolean(document.fullscreenElement));

        document.addEventListener('fullscreenchange', onFullscreenChange);

        return () =>
            document.removeEventListener(
                'fullscreenchange',
                onFullscreenChange,
            );
    }, []);

    useEffect(() => {
        const incoming = board.ready.some(
            (ticket) => !knownReadyIds.current.has(ticket.id),
        );
        knownReadyIds.current = new Set(board.ready.map((t) => t.id));

        if (incoming && !muted) {
            playReadyChime();
        }
    }, [board.ready, muted]);

    useEffect(() => {
        const timer = window.setInterval(async () => {
            try {
                const response = await fetch(
                    odsVersion.url({ outlet: outlet.id }),
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
                    router.reload({ only: ['board', 'version'] });
                }
            } catch {
                // Keep polling quietly across transient network blips.
            }
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [outlet.id]);

    const toggleFullscreen = async () => {
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else {
                await document.documentElement.requestFullscreen();
            }
        } catch {
            // Fullscreen API may be restricted by browser or iframe policy.
        }
    };

    const clock = now.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
    const dateLabel = now.toLocaleDateString([], {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });

    const readyCount = board.ready.length;
    const spotlight = readyCount > 0 && readyCount <= 4 ? board.ready[0] : null;
    const secondaryReady = spotlight ? board.ready.slice(1) : board.ready;

    return (
        <>
            <Head title={`Pickup · ${outlet.name}`} />

            <PosNavbar
                title={outlet.name}
                subtitle="Order pickup"
                titleAs="h1"
                signage
                right={
                    <>
                        <div className="flex items-baseline gap-2.5 pr-2">
                            <span className="hidden text-sm font-medium text-muted-foreground md:inline">
                                {dateLabel}
                            </span>
                            <p
                                className="font-heading text-xl font-semibold text-sidebar-foreground tabular-nums sm:text-2xl"
                                aria-label="Current time"
                            >
                                {clock}
                            </p>
                        </div>
                        <span
                            aria-hidden="true"
                            className="hidden h-5 w-px shrink-0 bg-sidebar-border sm:block"
                        />
                        <Tooltip>
                            <TooltipTrigger
                                render={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-11"
                                        aria-pressed={!muted}
                                        aria-label={
                                            muted
                                                ? 'Enable pickup chime'
                                                : 'Mute pickup chime'
                                        }
                                        onClick={() =>
                                            setMuted((value) => !value)
                                        }
                                    />
                                }
                            >
                                {muted ? (
                                    <VolumeX aria-hidden="true" />
                                ) : (
                                    <Volume2 aria-hidden="true" />
                                )}
                            </TooltipTrigger>
                            <TooltipContent side="bottom">
                                {muted
                                    ? 'Enable pickup chime'
                                    : 'Mute pickup chime'}
                            </TooltipContent>
                        </Tooltip>
                        <Tooltip>
                            <TooltipTrigger
                                render={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="hidden size-11 sm:inline-flex"
                                        aria-pressed={isFullscreen}
                                        aria-label={
                                            isFullscreen
                                                ? 'Exit full screen'
                                                : 'Enter full screen'
                                        }
                                        onClick={toggleFullscreen}
                                    />
                                }
                            >
                                {isFullscreen ? (
                                    <Minimize2 aria-hidden="true" />
                                ) : (
                                    <Maximize2 aria-hidden="true" />
                                )}
                            </TooltipTrigger>
                            <TooltipContent side="bottom">
                                {isFullscreen
                                    ? 'Exit full screen'
                                    : 'Enter full screen'}
                            </TooltipContent>
                        </Tooltip>
                    </>
                }
            />

            <main className="grid flex-1 grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.75fr)]">
                <section
                    aria-labelledby="ods-preparing-heading"
                    className="flex flex-col border-b bg-muted/50 p-6 lg:border-r lg:border-b-0 lg:p-10"
                >
                    <div className="flex items-end justify-between gap-4 border-b pb-5">
                        <div className="flex flex-col gap-0.5">
                            <h2
                                id="ods-preparing-heading"
                                className="font-heading text-2xl font-semibold tracking-tight text-foreground"
                            >
                                Preparing
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                In the kitchen
                            </p>
                        </div>
                        <span className="font-heading text-3xl font-semibold text-muted-foreground tabular-nums">
                            {board.preparing.length}
                        </span>
                    </div>

                    {board.preparing.length === 0 ? (
                        <div className="flex flex-1 flex-col items-center justify-center gap-3 py-12 text-center">
                            <span
                                aria-hidden="true"
                                className="flex size-12 items-center justify-center rounded-xl border bg-card text-muted-foreground"
                            >
                                <ChefHat className="size-5" />
                            </span>
                            <div className="flex flex-col gap-1">
                                <p className="font-heading text-base font-semibold text-foreground">
                                    Kitchen is clear
                                </p>
                                <p className="max-w-xs text-sm text-muted-foreground">
                                    Orders appear here as soon as they start
                                    cooking.
                                </p>
                            </div>
                        </div>
                    ) : (
                        <ul
                            role="list"
                            className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2"
                        >
                            {board.preparing.map((ticket, index) => (
                                <li
                                    key={ticket.id}
                                    className="flex items-center justify-between gap-3 rounded-xl border bg-card px-5 py-4"
                                >
                                    <span className="truncate font-heading text-2xl font-semibold tracking-tight text-foreground tabular-nums sm:text-3xl">
                                        {ticket.label}
                                    </span>
                                    <span className="shrink-0 font-mono text-xs font-medium text-muted-foreground tabular-nums">
                                        {String(index + 1).padStart(2, '0')}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section
                    aria-labelledby="ods-ready-heading"
                    className="order-first flex flex-col bg-brand-forest p-6 text-primary-foreground lg:order-none lg:p-10"
                >
                    <div className="flex items-end justify-between gap-4 border-b border-primary-foreground/15 pb-5">
                        <div className="flex flex-col gap-0.5">
                            <h2
                                id="ods-ready-heading"
                                className="font-heading text-2xl font-bold tracking-tight sm:text-3xl"
                            >
                                Ready for pickup
                            </h2>
                            <p className="text-sm text-primary-foreground/75">
                                Please collect your order at the counter
                            </p>
                        </div>
                        <span className="font-heading text-3xl font-bold text-primary-foreground/85 tabular-nums sm:text-4xl">
                            {readyCount}
                        </span>
                    </div>

                    {readyCount === 0 ? (
                        <div className="flex flex-1 flex-col items-center justify-center gap-4 py-16 text-center">
                            <span
                                aria-hidden="true"
                                className="flex size-16 items-center justify-center rounded-2xl border border-primary-foreground/15 bg-primary-foreground/10 text-primary-foreground"
                            >
                                <Bell className="size-7" />
                            </span>
                            <div className="flex flex-col gap-1.5">
                                <p className="font-heading text-xl font-semibold tracking-tight">
                                    Ready orders will appear here
                                </p>
                                <p className="max-w-sm text-sm text-primary-foreground/75">
                                    Watch this display for your table or order
                                    number.
                                </p>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-1 flex-col justify-center gap-6 py-6">
                            {spotlight && (
                                <div
                                    key={spotlight.id}
                                    className="mx-auto flex w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-primary-foreground text-brand-forest shadow-xl motion-safe:animate-in motion-safe:duration-300 motion-safe:zoom-in-95 motion-safe:fade-in"
                                >
                                    <div className="flex items-center justify-between gap-3 border-b border-brand-forest/15 px-6 py-3.5 sm:px-8">
                                        <span className="inline-flex items-center gap-2 text-sm font-semibold">
                                            <CheckCircle2
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            Now ready
                                        </span>
                                        <span className="text-sm font-medium opacity-75">
                                            Collect at counter
                                        </span>
                                    </div>
                                    <div
                                        className={
                                            secondaryReady.length === 0
                                                ? 'px-6 py-16 text-center sm:px-10 sm:py-24'
                                                : 'px-6 py-10 text-center sm:px-10 sm:py-12'
                                        }
                                    >
                                        <p
                                            className={
                                                secondaryReady.length === 0
                                                    ? 'font-heading text-6xl font-bold tracking-tight tabular-nums sm:text-7xl xl:text-8xl'
                                                    : 'font-heading text-5xl font-bold tracking-tight tabular-nums sm:text-6xl'
                                            }
                                        >
                                            {spotlight.label}
                                        </p>
                                    </div>
                                </div>
                            )}

                            {secondaryReady.length > 0 && (
                                <ul
                                    role="list"
                                    className={
                                        spotlight
                                            ? 'mx-auto grid w-full max-w-4xl grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3'
                                            : 'grid w-full grid-cols-2 gap-4 xl:grid-cols-3'
                                    }
                                >
                                    {secondaryReady.map((ticket, index) => (
                                        <li
                                            key={ticket.id}
                                            className="flex flex-col items-center justify-center gap-1.5 rounded-xl bg-primary-foreground px-5 py-7 text-center text-brand-forest shadow-sm motion-safe:animate-in motion-safe:duration-300 motion-safe:fade-in"
                                        >
                                            {!spotlight && index === 0 && (
                                                <span className="text-xs font-semibold opacity-75">
                                                    Just ready
                                                </span>
                                            )}
                                            <span className="font-heading text-3xl font-bold tracking-tight tabular-nums sm:text-4xl">
                                                {ticket.label}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}

                    <footer className="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-primary-foreground/15 pt-4 text-xs text-primary-foreground/75">
                        <span>{outlet.name}</span>
                        <span>Updates automatically</span>
                    </footer>
                </section>
            </main>
        </>
    );
}
