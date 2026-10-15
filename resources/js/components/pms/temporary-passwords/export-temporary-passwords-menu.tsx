import { ChevronDown, Download } from 'lucide-react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { toast } from '@/components/ui/toast';
import { postDownload } from '@/lib/post-download';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Downloads the temp passwords of the given users as CSV, XLSX or PDF slips.
 */
export function ExportTemporaryPasswordsMenu({
    route,
    userIds,
    formats,
    size = 'default',
    side = 'bottom',
    onExported,
}: {
    /** The route that sends the file, for example `exportMethod()` from the staff or partner temp password routes. */
    route: RouteDefinition<'post'>;
    userIds: number[];
    formats: EnumOption[];
    size?: 'default' | 'sm';
    side?: 'top' | 'bottom';
    /** Called once the file has been downloaded. */
    onExported?: () => void;
}) {
    const [busy, setBusy] = useState(false);

    const download = (format: string) => {
        setBusy(true);
        postDownload(route.url, { user_ids: userIds, format })
            .then((headers) => {
                onExported?.();

                const skipped = Number(headers.get('X-Skipped') ?? 0);

                if (skipped > 0) {
                    toast.add({
                        title: `${skipped} skipped: no temp password`,
                        type: 'info',
                    });
                }
            })
            .catch((error: Error) =>
                toast.add({ title: error.message, type: 'error' }),
            )
            .finally(() => setBusy(false));
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={
                    <Button
                        type="button"
                        variant="outline"
                        size={size}
                        disabled={busy || userIds.length === 0}
                        focusableWhenDisabled
                    />
                }
            >
                {busy ? (
                    <Spinner data-icon="inline-start" />
                ) : (
                    <Download data-icon="inline-start" />
                )}
                Export
                <ChevronDown data-icon="inline-end" />
            </DropdownMenuTrigger>
            <DropdownMenuContent side={side} align="end">
                {formats.map((format) => (
                    <DropdownMenuItem
                        key={format.value}
                        onClick={() => download(format.value)}
                    >
                        {format.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
