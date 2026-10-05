import SignaturePad from 'signature_pad';
import type { PointGroup } from 'signature_pad';
import { Maximize2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Size = { width: number; height: number };

/** Sizes the canvas for sharp lines on high-density screens. Setting the size wipes the canvas. */
function fit(element: HTMLCanvasElement): Size {
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    element.width = element.offsetWidth * ratio;
    element.height = element.offsetHeight * ratio;
    element.getContext('2d')?.scale(ratio, ratio);

    return { width: element.offsetWidth, height: element.offsetHeight };
}

/** Strokes drawn on one canvas size, scaled evenly so they fit inside another without clipping. */
function scaled(groups: PointGroup[], from: Size, to: Size): PointGroup[] {
    const factor = Math.min(to.width / from.width, to.height / from.height);

    return groups.map((group) => ({
        ...group,
        points: group.points.map((point) => ({
            ...point,
            x: point.x * factor,
            y: point.y * factor,
        })),
    }));
}

/**
 * The signature pad on the page. Clear sits above the pad, away from the Sign button.
 * Full screen opens a larger pad with the same strokes. Done hands them back, Cancel drops what was drawn there.
 * `onChange` gets a PNG data URL, or an empty string when the pad is empty.
 */
export function SignatureField({
    onChange,
    invalid,
    labels,
}: {
    onChange: (dataUrl: string) => void;
    invalid?: boolean;
    labels: {
        title: string;
        clear: string;
        fullScreen: string;
        done: string;
        cancel: string;
    };
}) {
    const inline = useRef<HTMLCanvasElement | null>(null);
    const inlinePad = useRef<SignaturePad | null>(null);
    const inlineSize = useRef<Size>({ width: 1, height: 1 });
    const large = useRef<HTMLCanvasElement | null>(null);
    const largePad = useRef<SignaturePad | null>(null);
    const largeSize = useRef<Size>({ width: 1, height: 1 });
    const changed = useRef(onChange);
    const [expanded, setExpanded] = useState(false);

    useEffect(() => {
        changed.current = onChange;
    });

    const emit = () => {
        const pad = inlinePad.current;
        changed.current(
            !pad || pad.isEmpty() ? '' : pad.toDataURL('image/png'),
        );
    };

    useEffect(() => {
        const element = inline.current;

        if (!element) {
            return;
        }

        inlineSize.current = fit(element);
        const pad = new SignaturePad(element, { penColor: 'black' });
        pad.addEventListener('endStroke', emit);
        inlinePad.current = pad;

        // A rotated tablet or a resized window changes the canvas size, so the strokes are kept and scaled.
        const observer = new ResizeObserver(() => {
            if (
                element.offsetWidth === inlineSize.current.width &&
                element.offsetHeight === inlineSize.current.height
            ) {
                return;
            }

            const data = pad.toData();
            const before = inlineSize.current;
            inlineSize.current = fit(element);
            pad.fromData(scaled(data, before, inlineSize.current));
        });
        observer.observe(element);

        return () => {
            observer.disconnect();
            pad.off();
            inlinePad.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // The dialog mounts its content when it opens, so the large pad is made then, with the strokes so far.
    useEffect(() => {
        if (!expanded) {
            return;
        }

        const frame = requestAnimationFrame(() => {
            const element = large.current;

            if (!element) {
                return;
            }

            largeSize.current = fit(element);
            largePad.current = new SignaturePad(element, { penColor: 'black' });
            largePad.current.fromData(
                scaled(
                    inlinePad.current?.toData() ?? [],
                    inlineSize.current,
                    largeSize.current,
                ),
            );
        });

        return () => {
            cancelAnimationFrame(frame);
            largePad.current?.off();
            largePad.current = null;
        };
    }, [expanded]);

    /** Done: the strokes drawn full screen replace the ones on the page. Cancel and Escape keep the page as it was. */
    const keep = () => {
        if (largePad.current && inlinePad.current) {
            inlinePad.current.fromData(
                scaled(
                    largePad.current.toData(),
                    largeSize.current,
                    inlineSize.current,
                ),
            );
            emit();
        }

        setExpanded(false);
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium">{labels.title} *</span>
                <div className="flex items-center gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            inlinePad.current?.clear();
                            emit();
                        }}
                    >
                        {labels.clear}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon-sm"
                        aria-label={labels.fullScreen}
                        onClick={() => setExpanded(true)}
                    >
                        <Maximize2 />
                    </Button>
                </div>
            </div>
            <canvas
                ref={inline}
                aria-label={labels.title}
                aria-invalid={invalid}
                className="h-48 w-full touch-none rounded-lg border bg-white aria-invalid:border-destructive"
            />

            <Dialog open={expanded} onOpenChange={setExpanded}>
                <DialogContent
                    showCloseButton={false}
                    className="flex h-svh max-w-none flex-col rounded-none sm:max-w-none"
                >
                    <DialogHeader className="flex-row items-center justify-between">
                        <DialogTitle>{labels.title}</DialogTitle>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => largePad.current?.clear()}
                        >
                            {labels.clear}
                        </Button>
                    </DialogHeader>
                    <canvas
                        ref={large}
                        aria-label={labels.title}
                        className="w-full flex-1 touch-none rounded-lg border bg-white"
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setExpanded(false)}
                        >
                            {labels.cancel}
                        </Button>
                        <Button type="button" onClick={keep}>
                            {labels.done}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
