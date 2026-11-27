import { Expand, Shrink } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

type Zoom = 'fit' | 'fill';

/**
 * The width Fit lays the email out at: the 570px card with a margin each side, as in a desktop inbox. It must stay
 * above the template's 600px mobile breakpoint, or Fit shows the mobile layout too.
 */
const EMAIL_WIDTH = 680;
const ZOOM_KEY = 'email-preview-zoom';

function storedZoom(): Zoom {
    try {
        return window.localStorage.getItem(ZOOM_KEY) === 'fill'
            ? 'fill'
            : 'fit';
    } catch {
        return 'fit';
    }
}

/**
 * Fit or Fill, remembered in the browser. The first render says Fit, the same as the server render, and the stored
 * choice is read right after mounting, so hydration never mismatches.
 */
function useZoom(): [Zoom, (zoom: Zoom) => void] {
    const [zoom, setZoom] = useState<Zoom>('fit');

    useEffect(() => {
        setZoom(storedZoom());
    }, []);

    const change = useCallback((next: Zoom) => {
        setZoom(next);

        try {
            window.localStorage.setItem(ZOOM_KEY, next);
        } catch {
            // Browser storage can be blocked. The choice still holds until the page reloads.
        }
    }, []);

    return [zoom, change];
}

/**
 * A guest email as the guest gets it. The server renders `html` from the same mail template it sends with, so the
 * preview is exact. **Fit** lays the email out as a desktop inbox shows it and scales it down to the space there is, so the
 * whole layout shows; **Fill** shows it at real size across the space there is, so a narrow space gets the mobile layout. The frame grows to
 * the email's height and is not clickable (links point to examples). It has no scripts (`sandbox` without
 * `allow-scripts`); `allow-same-origin` only lets it be measured.
 */
export function EmailPreview({
    subject,
    replyTo,
    html,
    loading = false,
    className,
}: {
    subject: string;
    replyTo: string | null;
    html: string;
    loading?: boolean;
    className?: string;
}) {
    const [zoom, setZoom] = useZoom();
    const frameRef = useRef<HTMLIFrameElement>(null);
    const boxRef = useRef<HTMLDivElement>(null);
    const [boxWidth, setBoxWidth] = useState(EMAIL_WIDTH);
    const [contentHeight, setContentHeight] = useState(480);
    /** In Fit, the email is laid out at least at its desktop width, then scaled to the space there is. */
    const layoutWidth = Math.max(EMAIL_WIDTH, boxWidth);
    const scale = zoom === 'fit' ? boxWidth / layoutWidth : 1;

    const measure = useCallback(() => {
        const body = frameRef.current?.contentDocument?.body;
        if (!body) {
            return;
        }

        // The bottom of the content, not the document's scroll height, which never drops below the frame's height.
        const bottom = Array.from(body.children).reduce(
            (lowest, child) =>
                Math.max(lowest, child.getBoundingClientRect().bottom),
            0,
        );
        if (bottom > 0) {
            setContentHeight(Math.ceil(bottom));
        }
    }, []);

    useEffect(() => {
        const box = boxRef.current;
        if (!box) {
            return;
        }

        const observer = new ResizeObserver(([entry]) =>
            setBoxWidth(entry.contentRect.width),
        );
        observer.observe(box);

        return () => observer.disconnect();
    }, []);

    // The email reflows when the frame's width changes, so its height is measured again.
    useEffect(() => {
        const frame = window.requestAnimationFrame(measure);

        return () => window.cancelAnimationFrame(frame);
    }, [zoom, boxWidth, measure]);

    return (
        <div
            className={cn(
                'flex flex-col overflow-hidden rounded-xl border',
                className,
            )}
        >
            <div className="flex items-start justify-between gap-3 border-b bg-muted/40 px-3 py-2">
                <dl className="grid min-w-0 grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                    <dt className="text-muted-foreground">Subject</dt>
                    <dd className="min-w-0 font-medium break-words">
                        {subject}
                    </dd>
                    <dt className="text-muted-foreground">Replies go to</dt>
                    <dd className="min-w-0 break-words">
                        {replyTo ?? (
                            <span className="text-muted-foreground">
                                No contact email set for the property
                            </span>
                        )}
                    </dd>
                </dl>
                <ToggleGroup
                    variant="outline"
                    size="sm"
                    spacing={0}
                    value={[zoom]}
                    onValueChange={(value) =>
                        value[0] && setZoom(value[0] as Zoom)
                    }
                    aria-label="Preview size"
                    className="shrink-0"
                >
                    <ToggleGroupItem value="fit">
                        <Shrink data-icon="inline-start" />
                        Fit
                    </ToggleGroupItem>
                    <ToggleGroupItem value="fill">
                        <Expand data-icon="inline-start" />
                        Fill
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>
            <div
                ref={boxRef}
                className="relative overflow-hidden"
                style={{ height: contentHeight * scale }}
            >
                <iframe
                    ref={frameRef}
                    title={`Preview of the email: ${subject}`}
                    srcDoc={html}
                    sandbox="allow-same-origin"
                    tabIndex={-1}
                    className={cn(
                        'pointer-events-none block origin-top-left transition-opacity',
                        loading && 'opacity-60',
                    )}
                    style={{
                        width: zoom === 'fit' ? layoutWidth : '100%',
                        height: contentHeight,
                        transform: scale < 1 ? `scale(${scale})` : undefined,
                    }}
                    onLoad={measure}
                />
                {loading && (
                    <Spinner className="absolute top-3 right-3 text-muted-foreground" />
                )}
            </div>
        </div>
    );
}
