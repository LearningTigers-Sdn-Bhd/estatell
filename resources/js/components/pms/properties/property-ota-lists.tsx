import { Form, useHttp } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import PropertyOtaListController from '@/actions/App/Http/Controllers/Admin/PropertyOtaListController';
import { DateText } from '@/components/pms/date-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useClipboard } from '@/hooks/use-clipboard';

type OtaListRow = App.Data.Platform.AdminOtaListRowData;

/**
 * The admin OTA lists tab body. Passwords are never in the rows: Reveal fetches one as JSON and the row keeps it
 * only while shown. Leaving the tab unmounts the rows, so the passwords go with them.
 */
export function PropertyOtaLists({
    code,
    rows,
}: {
    code: string;
    rows: OtaListRow[];
}) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Channel</TableHead>
                        <TableHead>Property ID</TableHead>
                        <TableHead>Username</TableHead>
                        <TableHead>Password</TableHead>
                        <TableHead>Market manager</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Updated</TableHead>
                        <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.length === 0 && (
                        <TableRow>
                            <TableCell
                                colSpan={8}
                                className="py-8 text-center text-muted-foreground"
                            >
                                No OTA lists yet.
                            </TableCell>
                        </TableRow>
                    )}
                    {rows.map((row) => (
                        <OtaListRowItem key={row.id} code={code} row={row} />
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function OtaListRowItem({ code, row }: { code: string; row: OtaListRow }) {
    const [password, setPassword] = useState<string | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [copiedText, copy] = useClipboard();
    const reveal = useHttp<Record<string, never>, { password: string }>(
        PropertyOtaListController.reveal({
            property: code,
            credential: row.id,
        }),
        {},
    );

    function revealPassword() {
        setProblem(null);
        reveal
            .submit()
            .then((response) => setPassword(response.password))
            .catch(() => setProblem('The password could not be loaded.'));
    }

    const marketManager = [
        row.marketManagerName,
        row.marketManagerPhone,
        row.marketManagerEmail,
    ].filter(Boolean);

    return (
        <TableRow>
            <TableCell className="font-medium">{row.channel}</TableCell>
            <TableCell className="tabular-nums">{row.propertyCode}</TableCell>
            <TableCell>{row.username}</TableCell>
            <TableCell>
                {password === null ? (
                    <div className="flex flex-col items-start gap-1">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={revealPassword}
                            disabled={reveal.processing}
                            focusableWhenDisabled
                        >
                            {reveal.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Reveal
                        </Button>
                        {problem && (
                            <span className="text-xs text-destructive">
                                {problem}
                            </span>
                        )}
                    </div>
                ) : (
                    <div className="flex items-center gap-2">
                        <span className="font-mono text-sm break-all">
                            {password}
                        </span>
                        <Button
                            type="button"
                            size="icon-sm"
                            variant="ghost"
                            aria-label="Copy password"
                            onClick={() => copy(password)}
                        >
                            {copiedText === password ? <Check /> : <Copy />}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => setPassword(null)}
                        >
                            Hide
                        </Button>
                    </div>
                )}
            </TableCell>
            <TableCell>
                {marketManager.length === 0 ? (
                    <span className="text-muted-foreground">None</span>
                ) : (
                    <div className="flex flex-col">
                        {marketManager.map((line) => (
                            <span key={line}>{line}</span>
                        ))}
                    </div>
                )}
            </TableCell>
            <TableCell>
                <StatusBadge status={row.status} />
            </TableCell>
            <TableCell className="tabular-nums">
                <DateText date={row.updatedAt} />
            </TableCell>
            <TableCell className="text-right">
                {row.canMarkProcessed && (
                    <Form
                        {...PropertyOtaListController.markProcessed.form({
                            property: code,
                            credential: row.id,
                        })}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Mark processed
                            </Button>
                        )}
                    </Form>
                )}
            </TableCell>
        </TableRow>
    );
}
