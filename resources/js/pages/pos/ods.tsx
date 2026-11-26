import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { version as odsVersion } from '@/routes/pos/ods';

type Ticket = { id: number; label: string };

type Props = {
    outlet: { id: number; name: string };
    version: string;
    board: { preparing: Ticket[]; ready: Ticket[] };
};

const POLL_MS = 4000;

/**
 * The pickup board of an outlet: what is still cooking, and what is ready to collect. It polls the outlet's version and
 * reloads only when a ticket moves. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
export default function PosOds({ outlet, version, board }: Props) {
    const latestVersion = useRef(version);
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        latestVersion.current = version;
    }, [version]);

    useEffect(() => {
        const clockTimer = window.setInterval(() => setNow(new Date()), 15000);

        return () => window.clearInterval(clockTimer);
    }, []);

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

    const clock = now.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });

    return (
        <>
            <Head title={`Pickup · ${outlet.name}`} />
            <div className="flex min-h-dvh flex-col bg-background">
                <header className="flex flex-wrap items-center justify-between gap-4 border-b bg-card px-6 py-4">
                    <div className="flex items-center gap-3">
                        <h1 className="font-heading text-2xl font-semibold">
                            {outlet.name}
                        </h1>
                        <Badge variant="outline" className="text-sm">
                            Order pickup
                        </Badge>
                    </div>
                    <p className="font-heading text-2xl font-semibold tabular-nums">
                        {clock}
                    </p>
                </header>

                <main className="grid flex-1 gap-6 p-6 md:grid-cols-2">
                    <Card className="flex flex-col overflow-hidden">
                        <CardHeader className="flex flex-row items-center justify-between border-b bg-muted/40 py-4">
                            <CardTitle className="font-heading text-2xl">
                                Preparing
                            </CardTitle>
                            <Badge
                                variant="secondary"
                                className="px-2.5 py-1 text-base tabular-nums"
                            >
                                {board.preparing.length}
                            </Badge>
                        </CardHeader>
                        <CardContent className="flex flex-1 flex-col pt-6">
                            {board.preparing.length === 0 ? (
                                <p className="my-auto text-center text-lg text-muted-foreground">
                                    No orders cooking right now.
                                </p>
                            ) : (
                                <ul
                                    role="list"
                                    className="grid grid-cols-2 gap-3 lg:grid-cols-3"
                                >
                                    {board.preparing.map((ticket) => (
                                        <li
                                            key={ticket.id}
                                            className="rounded-xl border bg-background px-4 py-5 text-center font-heading text-3xl font-semibold text-foreground tabular-nums lg:text-4xl"
                                        >
                                            {ticket.label}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="flex flex-col overflow-hidden border-2 border-primary">
                        <CardHeader className="flex flex-row items-center justify-between bg-primary py-4 text-primary-foreground">
                            <CardTitle className="font-heading text-2xl">
                                Ready for pickup
                            </CardTitle>
                            <span className="rounded-md bg-primary-foreground/20 px-2.5 py-1 text-base font-semibold tabular-nums">
                                {board.ready.length}
                            </span>
                        </CardHeader>
                        <CardContent className="flex flex-1 flex-col pt-6">
                            {board.ready.length === 0 ? (
                                <p className="my-auto text-center text-lg text-muted-foreground">
                                    No orders waiting for pickup.
                                </p>
                            ) : (
                                <ul
                                    role="list"
                                    className="grid grid-cols-2 gap-3 lg:grid-cols-3"
                                >
                                    {board.ready.map((ticket) => (
                                        <li
                                            key={ticket.id}
                                            className="rounded-xl border border-primary/40 bg-primary/10 px-4 py-5 text-center font-heading text-3xl font-semibold text-foreground tabular-nums lg:text-4xl"
                                        >
                                            {ticket.label}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </main>
            </div>
        </>
    );
}
