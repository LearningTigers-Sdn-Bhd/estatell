import { TriangleAlert } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    MYKAD_PROBLEMS_URL,
    bridgeDownload,
    readerChip,
    useMykadReader,
    type MyKadData,
} from '@/hooks/use-mykad-reader';
import { cn } from '@/lib/utils';

export type MyKadScannerProps = {
    /** Leave out to show the status only, as the guest tables do. */
    onScan?: (data: MyKadData) => void;
    className?: string;
};

const dotClass = {
    ready: 'bg-success',
    busy: 'animate-pulse bg-primary',
    idle: 'bg-muted-foreground/40',
    warning: 'bg-warning',
} as const;

/**
 * One status chip for MyKad Reader. The form fills itself when a card goes in.
 */
export function MyKadScanner({ onScan, className }: MyKadScannerProps) {
    const { status, isBridgeOutdated, error, errorCode } = useMykadReader({
        onScan,
    });

    const chip = readerChip(status, isBridgeOutdated, bridgeDownload());

    if (chip === null) {
        return null;
    }

    return (
        <div className={cn('flex flex-wrap items-center gap-2', className)}>
            <Badge
                variant="outline"
                size="lg"
                className="gap-1.5"
                render={
                    chip.href === null ? (
                        <span />
                    ) : (
                        <a href={chip.href} target="_blank" rel="noreferrer" />
                    )
                }
            >
                <span
                    aria-hidden="true"
                    className={cn(
                        'inline-block size-2 rounded-full',
                        dotClass[chip.tone],
                    )}
                />
                {chip.label}
            </Badge>

            {error && (
                <span className="flex items-center gap-1 text-xs text-destructive">
                    <TriangleAlert className="size-3 shrink-0" />
                    {error}
                    {errorCode === 'forbidden' && (
                        <a
                            href={MYKAD_PROBLEMS_URL}
                            target="_blank"
                            rel="noreferrer"
                            className="font-medium underline underline-offset-4"
                        >
                            Fix this ↗
                        </a>
                    )}
                </span>
            )}
        </div>
    );
}
