import { ChevronDown, Download, FileSpreadsheet, FileText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * Export on a group page: one button that opens PDF and CSV. The PDF opens in a new tab (landscape A4) and the CSV
 * downloads. Both URLs carry the page's filters, so the files hold what the page shows.
 */
export function GroupExportMenu({
    pdfUrl,
    csvUrl,
}: {
    pdfUrl: string;
    csvUrl: string;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={<Button type="button" variant="outline" />}
            >
                <Download data-icon="inline-start" />
                Export
                <ChevronDown data-icon="inline-end" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    render={<a href={pdfUrl} target="_blank" rel="noopener" />}
                >
                    <FileText />
                    Export PDF
                </DropdownMenuItem>
                <DropdownMenuItem render={<a href={csvUrl} download />}>
                    <FileSpreadsheet />
                    Export CSV
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
