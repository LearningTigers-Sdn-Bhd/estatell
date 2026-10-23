import { Download, IdCard, RefreshCw, TriangleAlert } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useMykadReader, type MyKadData } from '@/hooks/use-mykad-reader';
import { cn } from '@/lib/utils';

export type MyKadScannerProps = {
    onScan: (data: MyKadData) => void;
    compact?: boolean;
    className?: string;
    autoScan?: boolean;
};

export function MyKadScanner({
    onScan,
    compact = false,
    className,
    autoScan = true,
}: MyKadScannerProps) {
    const {
        status,
        cardPresent,
        readerName,
        error,
        lastScannedData,
        isScanning,
        checkStatus,
        scan,
    } = useMykadReader({ onScan, autoScan });

    const isOffline = status === 'offline';
    const isReady = status === 'ready' || status === 'card_present';

    const statusBadge = () => {
        if (isScanning) {
            return (
                <span className="flex animate-pulse items-center gap-1.5 text-xs font-semibold text-primary">
                    <span className="relative flex h-2 w-2">
                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary opacity-75" />
                        <span className="relative inline-flex h-2 w-2 rounded-full bg-primary" />
                    </span>
                    <span>Card detected · Auto-reading...</span>
                </span>
            );
        }

        if (isOffline) {
            return (
                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span className="inline-block h-2 w-2 rounded-full bg-muted-foreground/40" />
                    <span>Bridge offline</span>
                </span>
            );
        }

        if (cardPresent) {
            return (
                <span className="flex items-center gap-1.5 text-xs font-medium text-success">
                    <span className="inline-block h-2 w-2 rounded-full bg-success" />
                    <span>Card inserted</span>
                </span>
            );
        }

        return (
            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <span className="inline-block h-2 w-2 rounded-full bg-success/60" />
                <span>{readerName || 'ACR39U'} ready</span>
            </span>
        );
    };

    if (compact) {
        return (
            <div
                className={cn(
                    'flex flex-col gap-2 rounded-md border border-border bg-muted/40 p-2.5 text-xs',
                    isScanning && 'border-primary/50 bg-primary/5',
                    className,
                )}
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex min-w-0 items-center gap-2">
                        <IdCard
                            className={cn(
                                'h-4 w-4 shrink-0',
                                isScanning
                                    ? 'animate-pulse text-primary'
                                    : 'text-primary',
                            )}
                        />
                        <span className="font-semibold text-foreground">
                            MyKad
                        </span>
                        <span className="text-muted-foreground">·</span>
                        {statusBadge()}
                    </div>

                    <div className="flex items-center gap-2">
                        {isOffline ? (
                            <Tooltip>
                                <TooltipTrigger
                                    render={
                                        <Button
                                            variant="outline"
                                            size="xs"
                                            className="h-7 gap-1.5 text-xs"
                                            nativeButton={false}
                                            render={
                                                <a
                                                    href="/downloads/mykad-bridge.zip"
                                                    download="mykad-bridge.zip"
                                                />
                                            }
                                        >
                                            <Download className="h-3 w-3" />
                                            <span>Download Bridge</span>
                                        </Button>
                                    }
                                />
                                <TooltipContent>
                                    Download and run the local card reader
                                    bridge for Windows/Mac
                                </TooltipContent>
                            </Tooltip>
                        ) : (
                            <Button
                                type="button"
                                size="xs"
                                className="h-7 gap-1.5 text-xs"
                                disabled={isScanning || !isReady}
                                onClick={() => scan()}
                            >
                                {isScanning ? (
                                    <Spinner className="h-3 w-3" />
                                ) : (
                                    <IdCard className="h-3 w-3" />
                                )}
                                <span>
                                    {isScanning
                                        ? 'Reading...'
                                        : cardPresent
                                          ? 'Re-scan'
                                          : 'Scan Card'}
                                </span>
                            </Button>
                        )}
                    </div>
                </div>

                {lastScannedData?.photo_base64 && (
                    <div className="flex items-center gap-3 rounded border border-border/80 bg-background/80 p-2">
                        <img
                            src={lastScannedData.photo_base64}
                            alt="Scanned portrait"
                            className="h-16 w-13 shrink-0 rounded border border-border object-cover shadow-xs ring-1 ring-border"
                        />
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5 text-xs">
                            <div className="flex items-center justify-between">
                                <span className="truncate font-semibold text-foreground">
                                    {lastScannedData.name}
                                </span>
                                <Badge
                                    variant="outline"
                                    className="border-emerald-300 text-[10px] text-emerald-600 dark:border-emerald-700"
                                >
                                    Scanned
                                </Badge>
                            </div>
                            <span className="font-mono text-[11px] text-muted-foreground">
                                {lastScannedData.formatted_ic ||
                                    lastScannedData.ic_number}
                            </span>
                            <span className="text-[11px] text-muted-foreground">
                                {lastScannedData.date_of_birth} ·{' '}
                                {lastScannedData.gender}
                            </span>
                            {lastScannedData.address_line1 && (
                                <span className="truncate text-[11px] text-muted-foreground">
                                    {[
                                        lastScannedData.address_line1,
                                        lastScannedData.city,
                                        lastScannedData.state,
                                    ]
                                        .filter(Boolean)
                                        .join(', ')}
                                </span>
                            )}
                        </div>
                    </div>
                )}

                {error && (
                    <div className="flex w-full items-center gap-1 pt-1 text-xs text-destructive">
                        <TriangleAlert className="h-3 w-3 shrink-0" />
                        <span>{error}</span>
                    </div>
                )}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'rounded-lg border border-border bg-card p-3 shadow-xs transition-colors',
                isScanning &&
                    'border-primary/50 bg-primary/5 ring-1 ring-primary/20',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-2.5">
                    <div
                        className={cn(
                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-primary',
                            isScanning
                                ? 'animate-pulse bg-primary/20'
                                : 'bg-primary/10',
                        )}
                    >
                        <IdCard className="h-4 w-4" />
                    </div>
                    <div className="min-w-0">
                        <div className="flex items-center gap-1.5">
                            <span className="text-xs font-semibold text-foreground">
                                MyKad Smart Card Reader
                            </span>
                        </div>
                        <div className="mt-0.5">{statusBadge()}</div>
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    {isOffline ? (
                        <div className="flex items-center gap-1.5">
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-8 gap-1.5 text-xs"
                                nativeButton={false}
                                render={
                                    <a
                                        href="/downloads/mykad-bridge.zip"
                                        download="mykad-bridge.zip"
                                    />
                                }
                            >
                                <Download className="h-3.5 w-3.5" />
                                <span>Download Bridge</span>
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                className="h-8 w-8 text-muted-foreground"
                                onClick={() => checkStatus()}
                                title="Retry connection"
                                aria-label="Retry reader connection"
                            >
                                <RefreshCw className="h-3.5 w-3.5" />
                            </Button>
                        </div>
                    ) : (
                        <Button
                            type="button"
                            size="sm"
                            className="h-8 gap-1.5 text-xs"
                            disabled={isScanning || !isReady}
                            onClick={() => scan()}
                        >
                            {isScanning ? (
                                <Spinner className="h-3.5 w-3.5" />
                            ) : (
                                <IdCard className="h-3.5 w-3.5" />
                            )}
                            <span>
                                {isScanning
                                    ? 'Reading chip...'
                                    : cardPresent
                                      ? 'Re-scan MyKad'
                                      : 'Scan Card'}
                            </span>
                        </Button>
                    )}
                </div>
            </div>

            {lastScannedData?.photo_base64 && (
                <div className="mt-3 flex items-start gap-4 rounded-md border border-border/80 bg-muted/40 p-3">
                    <img
                        src={lastScannedData.photo_base64}
                        alt="MyKad Portrait"
                        className="h-28 w-24 shrink-0 rounded-lg border-2 border-border object-cover shadow-sm ring-1 ring-border"
                    />
                    <div className="flex flex-1 flex-col gap-1 text-xs">
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-semibold text-foreground">
                                {lastScannedData.name}
                            </span>
                            <Badge
                                variant="outline"
                                className="border-emerald-300 text-[10px] text-emerald-600 dark:border-emerald-700"
                            >
                                Cardholder Verified
                            </Badge>
                        </div>
                        <div className="mt-1 grid grid-cols-2 gap-x-4 gap-y-1 text-muted-foreground">
                            <div>
                                <span className="text-[11px]">NRIC / ID:</span>
                                <p className="font-mono text-xs font-medium text-foreground">
                                    {lastScannedData.formatted_ic ||
                                        lastScannedData.ic_number}
                                </p>
                            </div>
                            <div>
                                <span className="text-[11px]">
                                    Date of Birth:
                                </span>
                                <p className="text-xs font-medium text-foreground">
                                    {lastScannedData.date_of_birth}
                                </p>
                            </div>
                            <div>
                                <span className="text-[11px]">Gender:</span>
                                <p className="text-xs font-medium text-foreground capitalize">
                                    {lastScannedData.gender}
                                </p>
                            </div>
                            <div>
                                <span className="text-[11px]">
                                    Nationality:
                                </span>
                                <p className="text-xs font-medium text-foreground">
                                    Malaysian (MY)
                                </p>
                            </div>
                        </div>
                        {lastScannedData.address_line1 && (
                            <div className="mt-1 border-t border-border/50 pt-1 text-muted-foreground">
                                <span className="text-[11px]">Address:</span>
                                <p className="line-clamp-2 text-xs text-foreground">
                                    {[
                                        lastScannedData.address_line1,
                                        lastScannedData.address_line2,
                                        lastScannedData.city,
                                        lastScannedData.state,
                                        lastScannedData.postal_code,
                                    ]
                                        .filter(Boolean)
                                        .join(', ')}
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {isOffline && (
                <div className="mt-2.5 rounded-md bg-muted/60 p-2 text-xs text-muted-foreground">
                    <span>
                        Bridge not detected on localhost:9292. Click{' '}
                        <a
                            href="/downloads/mykad-bridge.zip"
                            download="mykad-bridge.zip"
                            className="font-medium text-foreground underline underline-offset-4"
                        >
                            Download Bridge
                        </a>
                        , unzip and double-click <code>run_bridge.bat</code>{' '}
                        (Windows) or <code>run_bridge.sh</code> (Mac).
                    </span>
                </div>
            )}

            {error && (
                <div className="mt-2.5 flex items-center gap-1.5 rounded-md bg-destructive/10 p-2 text-xs text-destructive">
                    <TriangleAlert className="h-3.5 w-3.5 shrink-0" />
                    <span>{error}</span>
                </div>
            )}
        </div>
    );
}
