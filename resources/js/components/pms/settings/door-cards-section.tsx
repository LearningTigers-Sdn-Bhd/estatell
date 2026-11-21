import { router, usePoll } from '@inertiajs/react';
import { CreditCard, Usb } from 'lucide-react';
import { useEffect, useState } from 'react';
import DoorLockSettingsController from '@/actions/App/Http/Controllers/DoorLocks/DoorLockSettingsController';
import {
    PaginationFooter,
    toPaginationData,
} from '@/components/pms/pagination-footer';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { AUTO_KEY, useDoorCardScan } from '@/hooks/use-door-card-scan';
import {
    connectReader,
    hidSupported,
    readerGranted,
    watchReader,
} from '@/lib/ecrfid';

const POLL_MS = 3000;

const connected: App.Data.Shared.BadgeData = {
    value: 'connected',
    label: 'Reader connected',
    tone: 'success',
};
const notConnected: App.Data.Shared.BadgeData = {
    value: 'not_connected',
    label: 'Reader not connected',
    tone: 'neutral',
};

function when(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Cards on the Door locks page, under the PINs. The desk reader is connected here once (the browser asks once per computer). With
 * "Add cards automatically" on, a guest who checks in on the Concierge page gets the card lying on the reader: the page reads it and
 * registers it with the stay's lock, so nobody at the desk has to. Keep this page open and visible on the desk computer.
 */
export function DoorCardsSection({
    cards,
    waitingStay,
}: {
    cards: Illuminate.LengthAwarePaginator<
        number,
        App.Data.DoorLocks.DoorCardRowData
    >;
    waitingStay: { unitId: number; label: string } | null;
}) {
    const [supported, setSupported] = useState(false);
    const [ready, setReady] = useState(false);
    const [auto, setAuto] = useState(false);
    const [connectError, setConnectError] = useState<string | null>(null);
    const { start, stop } = usePoll(
        POLL_MS,
        { only: ['waitingStay', 'cards'] },
        { autoStart: false, mode: 'rest' },
    );

    useEffect(() => {
        setSupported(hidSupported());

        try {
            setAuto(localStorage.getItem(AUTO_KEY) === '1');
        } catch {
            // Private window or blocked storage: the switch starts off.
        }

        const refresh = () => void readerGranted().then(setReady);
        refresh();

        return watchReader(refresh);
    }, []);

    const active = ready && auto;

    useEffect(() => {
        if (!active) {
            stop();

            return;
        }

        start();

        return stop;
        // `start` and `stop` change every render; only `active` should restart polling.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [active]);

    const stayId = waitingStay?.unitId;

    const { phase, error: scanError } = useDoorCardScan(active, stayId);
    const error = connectError ?? scanError;

    const connect = async () => {
        setConnectError(null);

        try {
            await connectReader();
            setReady(await readerGranted());
        } catch (caught) {
            setConnectError(
                caught instanceof Error
                    ? caught.message
                    : 'The reader could not be connected.',
            );
        }
    };

    const setAutoAdd = (next: boolean) => {
        setAuto(next);

        try {
            localStorage.setItem(AUTO_KEY, next ? '1' : '0');
        } catch {
            // The switch still works for this visit.
        }
    };

    const remove = (id: number) =>
        router.delete(
            DoorLockSettingsController.destroyCard.url({ doorCard: id }),
            { preserveScroll: true },
        );

    return (
        <section className="flex flex-col gap-4">
            <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                <div className="min-w-0 space-y-1">
                    <h3 className="text-base font-medium">Cards</h3>
                    <p className="text-sm text-muted-foreground">
                        Newest first. Nothing is written to a card, its number
                        is registered with the lock.
                    </p>
                </div>
                {supported ? (
                    <div className="flex flex-wrap items-center gap-3">
                        <StatusBadge
                            status={ready ? connected : notConnected}
                            size="lg"
                        />
                        {ready ? (
                            <label
                                htmlFor="auto-door-cards"
                                className="flex items-center gap-2 text-sm"
                            >
                                <Switch
                                    id="auto-door-cards"
                                    checked={auto}
                                    onCheckedChange={setAutoAdd}
                                />
                                Add cards automatically
                            </label>
                        ) : (
                            <Button type="button" onClick={connect}>
                                <Usb />
                                Connect reader
                            </Button>
                        )}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Reading cards needs Chrome or Edge.
                    </p>
                )}
            </header>

            {active && (
                <div
                    role="status"
                    className="flex items-center gap-3 rounded-lg bg-muted/50 px-4 py-3 text-sm"
                >
                    {phase === 'adding' ? (
                        <Spinner />
                    ) : (
                        <CreditCard
                            aria-hidden
                            className="size-5 shrink-0 text-muted-foreground"
                        />
                    )}
                    <p className="min-w-0">
                        {stayId === undefined
                            ? 'Waiting for a guest to check in on the Concierge page.'
                            : phase === 'adding'
                              ? 'Card detected. Adding it to the lock…'
                              : phase === 'again'
                                ? `That card was already tried for ${waitingStay?.label}. Put a different card on the reader.`
                                : `${waitingStay?.label} is checked in. Put a card on the reader.`}
                    </p>
                </div>
            )}
            {error && <p className="text-sm text-destructive">{error}</p>}

            {cards.meta.total === 0 ? (
                <p className="text-sm text-muted-foreground">No cards yet.</p>
            ) : (
                <>
                    <div className="overflow-hidden rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>For</TableHead>
                                    <TableHead>Room</TableHead>
                                    <TableHead>Lock</TableHead>
                                    <TableHead>Card number</TableHead>
                                    <TableHead>Valid</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {cards.data.map((card) => (
                                    <TableRow key={card.id}>
                                        <TableCell>
                                            {card.guestName ??
                                                card.reference ??
                                                card.label}
                                            {card.guestName &&
                                                card.reference && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {card.reference}
                                                    </span>
                                                )}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {card.room ?? '—'}
                                        </TableCell>
                                        <TableCell>{card.lockName}</TableCell>
                                        <TableCell className="font-mono tabular-nums">
                                            {card.cardNumber}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {when(card.startsAt)} to{' '}
                                            {when(card.endsAt)}
                                        </TableCell>
                                        <TableCell className="max-w-72 whitespace-normal">
                                            <StatusBadge status={card.status} />
                                            {card.error && (
                                                <span
                                                    title={card.error}
                                                    className="mt-1 block text-xs break-words text-destructive"
                                                >
                                                    {card.error}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap">
                                            {card.canRemove && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        remove(card.id)
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <PaginationFooter
                        pagination={toPaginationData(cards.meta, 'cards_page')}
                    />
                </>
            )}
        </section>
    );
}
