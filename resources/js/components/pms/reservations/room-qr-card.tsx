import { router, usePage } from '@inertiajs/react';
import { Check, Copy, Printer, QrCode, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
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
import { useClipboard } from '@/hooks/use-clipboard';
import { regenerate } from '@/routes/pos/room-qr';

export type RoomQrData = {
    unitId: number;
    roomLabel: string;
    primaryGuest: string | null;
    hasActiveSession: boolean;
    roomUrl: string | null;
    qrSvg: string | null;
};

/**
 * Front desk controls for in-room dining QR ordering: issue or regenerate the stay PIN, show the printable welcome card
 * with the QR SVG and one-time 4-digit PIN.
 */
export function RoomQrCard({ roomQr }: { roomQr: RoomQrData }) {
    const flash = usePage().flash as {
        roomQrPin?: { unitId: number; pin: string };
    };
    const [busy, setBusy] = useState(false);
    const [manualOpen, setManualOpen] = useState(false);
    const [dismissedPin, setDismissedPin] = useState<string | null>(null);
    const [copiedText, copy] = useClipboard();

    const flashedPin =
        flash.roomQrPin?.unitId === roomQr.unitId &&
        flash.roomQrPin.pin !== dismissedPin
            ? flash.roomQrPin.pin
            : null;
    const dialogOpen = manualOpen || flashedPin !== null;

    const handleRegenerate = () => {
        setBusy(true);
        setDismissedPin(null);
        router.post(
            regenerate.url({ unit: roomQr.unitId }),
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <span className="flex items-center gap-1.5 text-muted-foreground">
                    <QrCode aria-hidden className="size-4" />
                    Room dining QR
                </span>
                <div className="flex flex-wrap items-center gap-2">
                    <Badge
                        variant={
                            roomQr.hasActiveSession ? 'secondary' : 'outline'
                        }
                    >
                        {roomQr.hasActiveSession ? 'Active' : 'Not issued'}
                    </Badge>
                    {roomQr.hasActiveSession && roomQr.qrSvg !== null && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setManualOpen(true)}
                        >
                            <QrCode data-icon="inline-start" />
                            Show QR
                        </Button>
                    )}
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={busy}
                        focusableWhenDisabled
                        onClick={handleRegenerate}
                    >
                        {busy ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <RotateCcw data-icon="inline-start" />
                        )}
                        {roomQr.hasActiveSession
                            ? 'Regenerate PIN'
                            : 'Issue room QR'}
                    </Button>
                </div>
            </div>

            <Dialog
                open={dialogOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        setManualOpen(false);
                        if (flashedPin !== null) {
                            setDismissedPin(flashedPin);
                        }
                    }
                }}
            >
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Room dining QR</DialogTitle>
                        <DialogDescription>
                            {flashedPin !== null
                                ? 'Give this welcome card or PIN to the guest now. The PIN is not shown again.'
                                : 'Guests scan this QR code and unlock with their stay PIN and last name.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="pos-print-area flex flex-col items-center gap-3 rounded-lg border bg-background p-4 text-center">
                        <div className="text-sm font-semibold">
                            Room {roomQr.roomLabel}
                        </div>
                        {roomQr.primaryGuest !== null && (
                            <div className="text-xs text-muted-foreground">
                                {roomQr.primaryGuest}
                            </div>
                        )}
                        {roomQr.qrSvg !== null && (
                            <div
                                className="rounded-lg border bg-white p-3"
                                dangerouslySetInnerHTML={{
                                    __html: roomQr.qrSvg,
                                }}
                            />
                        )}
                        {flashedPin !== null && (
                            <div className="flex flex-col items-center gap-1">
                                <span className="text-xs text-muted-foreground">
                                    Stay PIN
                                </span>
                                <div className="flex items-center gap-2">
                                    <span className="font-mono text-2xl font-semibold tracking-[0.25em] tabular-nums">
                                        {flashedPin}
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="icon-sm"
                                        aria-label="Copy PIN"
                                        onClick={() => copy(flashedPin)}
                                    >
                                        {copiedText === flashedPin ? (
                                            <Check />
                                        ) : (
                                            <Copy />
                                        )}
                                    </Button>
                                </div>
                            </div>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Scan to order in-room dining. Unlock using your
                            4-digit stay PIN and last name.
                        </p>
                    </div>
                    <DialogFooter>
                        {roomQr.roomUrl !== null && (
                            <Button
                                variant="outline"
                                onClick={() => copy(roomQr.roomUrl!)}
                            >
                                {copiedText === roomQr.roomUrl ? (
                                    <Check data-icon="inline-start" />
                                ) : (
                                    <Copy data-icon="inline-start" />
                                )}
                                Copy link
                            </Button>
                        )}
                        <Button onClick={() => window.print()}>
                            <Printer data-icon="inline-start" />
                            Print slip
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
