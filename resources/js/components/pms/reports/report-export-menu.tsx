import { usePage } from '@inertiajs/react';
import { ChevronDown, Download } from 'lucide-react';
import { useRef, useState } from 'react';
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
import { pdf, spreadsheet } from '@/routes/reports';

/**
 * Exports a report as PDF, CSV or Excel. The browser sends the dates, the sort and the selected row keys, never row
 * data. With no row keys the server exports every row that matches the dates. The PDF opens in a new tab through a
 * native form POST; CSV and Excel download through `postDownload`.
 */
export function ReportExportMenu({
    report,
    from,
    to,
    sort,
    rowKeys,
}: {
    report: App.Enums.ReportKey;
    from: string;
    to: string;
    sort: string;
    rowKeys: string[];
}) {
    const { csrfToken } = usePage().props;
    const formRef = useRef<HTMLFormElement>(null);
    const [busy, setBusy] = useState(false);

    const download = (format: 'csv' | 'xlsx') => {
        setBusy(true);
        postDownload(spreadsheet.url({ report }), {
            format,
            from,
            to,
            sort,
            ...(rowKeys.length > 0 ? { rowKeys } : {}),
        })
            .catch((error: Error) =>
                toast.add({ title: error.message, type: 'error' }),
            )
            .finally(() => setBusy(false));
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger
                    render={
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
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
                <DropdownMenuContent align="end">
                    <DropdownMenuItem onClick={() => formRef.current?.submit()}>
                        PDF
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => download('csv')}>
                        CSV
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => download('xlsx')}>
                        Excel
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <form
                ref={formRef}
                method="post"
                action={pdf.url({ report })}
                target="_blank"
                hidden
            >
                <input type="hidden" name="_token" value={csrfToken} />
                <input type="hidden" name="from" value={from} />
                <input type="hidden" name="to" value={to} />
                <input type="hidden" name="sort" value={sort} />
                {rowKeys.map((key) => (
                    <input
                        key={key}
                        type="hidden"
                        name="rowKeys[]"
                        value={key}
                    />
                ))}
            </form>
        </>
    );
}
