import SignaturePad from 'signature_pad';
import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * A large dialog with a drawing area. Done hands back the signature as a PNG data URL, and Clear wipes the area.
 * The words come from the card's open language.
 */
export function SignatureDialog({
    open,
    onOpenChange,
    onDone,
    labels,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onDone: (dataUrl: string) => void;
    labels: { title: string; clear: string; done: string; empty: string };
}) {
    const canvas = useRef<HTMLCanvasElement | null>(null);
    const pad = useRef<SignaturePad | null>(null);

    // The dialog mounts its content when it opens, so the pad is made then and sized for sharp lines.
    useEffect(() => {
        if (!open) {
            return;
        }

        const frame = requestAnimationFrame(() => {
            const element = canvas.current;

            if (!element) {
                return;
            }

            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            element.width = element.offsetWidth * ratio;
            element.height = element.offsetHeight * ratio;
            element.getContext('2d')?.scale(ratio, ratio);
            pad.current = new SignaturePad(element, { penColor: 'black' });
        });

        return () => {
            cancelAnimationFrame(frame);
            pad.current?.off();
            pad.current = null;
        };
    }, [open]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{labels.title}</DialogTitle>
                </DialogHeader>
                <canvas
                    ref={canvas}
                    aria-label={labels.title}
                    className="h-80 w-full touch-none rounded-lg border bg-white"
                />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => pad.current?.clear()}
                    >
                        {labels.clear}
                    </Button>
                    <Button
                        type="button"
                        onClick={() => {
                            if (!pad.current || pad.current.isEmpty()) {
                                return;
                            }

                            onDone(pad.current.toDataURL('image/png'));
                            onOpenChange(false);
                        }}
                    >
                        {labels.done}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
