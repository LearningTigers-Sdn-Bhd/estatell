import { Head, router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
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
 * reloads only when a ticket moved. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
export default function PosOds({ outlet, version, board }: Props) {
    const latestVersion = useRef(version);

    useEffect(() => {
        latestVersion.current = version;
    }, [version]);

    useEffect(() => {
        const timer = window.setInterval(async () => {
            const response = await fetch(
                odsVersion.url({ outlet: outlet.id }),
                {
                    headers: { Accept: 'application/json' },
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
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [outlet.id]);

    return (
        <>
            <Head title={`Pickup · ${outlet.name}`} />
            <div className="grid min-h-svh gap-6 p-6 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-2xl">Preparing</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-3xl font-semibold tabular-nums">
                        {board.preparing.length === 0 && (
                            <p className="text-lg text-muted-foreground">
                                Nothing in the kitchen.
                            </p>
                        )}
                        {board.preparing.map((ticket) => (
                            <span key={ticket.id}>{ticket.label}</span>
                        ))}
                    </CardContent>
                </Card>
                <Card className="border-success">
                    <CardHeader>
                        <CardTitle className="text-2xl text-success">
                            Ready for pickup
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-3xl font-semibold tabular-nums">
                        {board.ready.length === 0 && (
                            <p className="text-lg text-muted-foreground">
                                Nothing ready yet.
                            </p>
                        )}
                        {board.ready.map((ticket) => (
                            <span key={ticket.id}>{ticket.label}</span>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
