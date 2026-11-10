import { router } from '@inertiajs/react';
import { CreditCard, Plus, Usb } from 'lucide-react';
import { useRef, useState, useSyncExternalStore } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { hidSupported, readerGranted, scanCardUid } from '@/lib/ecrfid';
import { store } from '@/routes/reservations/units/door-cards';

type Phase = 'connect' | 'opening' | 'scanning' | 'sending' | 'error';

/**
 * Staff hold a card on the desk reader, then the card is registered with the stay's locks through the gateway.
 * The reader is read in the browser (WebHID), so no helper app is needed. The browser's own reader picker shows
 * once per browser; this dialog says what it is for and what to do next.
 */
export function ScanDoorCardButton({
    reservationId,
    unitId,
}: {
    reservationId: number;
    unitId: number;
}) {
    const [open, setOpen] = useState(false);
    const [phase, setPhase] = useState<Phase>('connect');
    const [error, setError] = useState<string | null>(null);
    const controller = useRef<AbortController | null>(null);
    // The server and the first client render say "no", so the page hydrates the same everywhere.
    const supported = useSyncExternalStore(
        () => () => undefined,
        hidSupported,
        () => false,
    );

    if (!supported) {
        return null;
    }

    const close = () => {
        controller.current?.abort();
        setOpen(false);
    };

    const scan = async () => {
        const current = new AbortController();
        controller.current = current;
        setError(null);
        setPhase((now) => (now === 'error' ? 'opening' : now));

        try {
            const uid = await scanCardUid(current.signal, () =>
                setPhase('scanning'),
            );
            if (current.signal.aborted) {
                return;
            }

            setPhase('sending');
            router.post(
                store.url({ reservation: reservationId, unit: unitId }),
                { uid },
                { preserveScroll: true, onFinish: () => setOpen(false) },
            );
        } catch (caught) {
            if (current.signal.aborted) {
                return;
            }

            setError(
                caught instanceof Error ? caught.message : 'Could not read.',
            );
            setPhase('error');
        }
    };

    const show = async () => {
        setError(null);
        setOpen(true);

        if (await readerGranted()) {
            setPhase('opening');
            void scan();
        } else {
            setPhase('connect');
        }
    };

    return (
        <>
            <Button variant="outline" size="sm" onClick={show}>
                <Plus /> Add card
            </Button>
            <Dialog open={open} onOpenChange={(next) => !next && close()}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Add a door card</DialogTitle>
                        <DialogDescription>
                            The card opens this stay's door until departure.
                            Nothing is written to the card.
                        </DialogDescription>
                    </DialogHeader>

                    <div
                        role="status"
                        className="flex flex-col items-center gap-3 rounded-lg bg-muted/50 px-4 py-6 text-center text-sm"
                    >
                        {phase === 'connect' && (
                            <>
                                <Usb
                                    aria-hidden
                                    className="size-8 text-muted-foreground"
                                />
                                <p className="font-medium">
                                    Connect the card reader
                                </p>
                                <p className="text-muted-foreground">
                                    Plug in the reader, then choose “Micro power
                                    reader” in the browser box that opens. You
                                    only do this once on this computer.
                                </p>
                            </>
                        )}
                        {phase === 'opening' && (
                            <>
                                <Spinner />
                                <p className="font-medium">
                                    Opening the reader…
                                </p>
                            </>
                        )}
                        {phase === 'scanning' && (
                            <>
                                <CreditCard
                                    aria-hidden
                                    className="size-8 animate-pulse text-primary"
                                />
                                <p className="font-medium">
                                    Hold the card on the reader
                                </p>
                                <p className="text-muted-foreground">
                                    Waiting up to 30 seconds.
                                </p>
                            </>
                        )}
                        {phase === 'sending' && (
                            <>
                                <Spinner />
                                <p className="font-medium">
                                    Adding the card to the lock…
                                </p>
                                <p className="text-muted-foreground">
                                    The gateway can take up to 30 seconds. Keep
                                    this window open.
                                </p>
                            </>
                        )}
                        {phase === 'error' && (
                            <p className="font-medium text-destructive">
                                {error}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        {(phase === 'connect' || phase === 'error') && (
                            <Button onClick={scan}>
                                {phase === 'connect'
                                    ? 'Connect reader'
                                    : 'Try again'}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
