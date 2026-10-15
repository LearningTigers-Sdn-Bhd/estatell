import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Tax = App.Data.Catalogues.ChargeItemTaxData;
type Preview = App.Data.Catalogues.RoomRevenuePreviewData;

export function PostingPreview({
    title,
    sample,
    revenueLabel,
    preview,
    offTaxes,
    money,
    loading,
    localLabel = 'Malaysian guest',
}: {
    title: string;
    /** What the sample amount is, for example "A room night". */
    sample: string;
    /** The name of the first line, for example "Room revenue". */
    revenueLabel: string;
    preview: Preview;
    offTaxes: Tax[];
    money: Intl.NumberFormat;
    loading: boolean;
    /** The heading of the local guest column. */
    localLabel?: string;
}) {
    const amount = (value: string | null) =>
        value === null ? '—' : money.format(Number(value));

    return (
        <section
            aria-labelledby="room-revenue-preview"
            aria-busy={loading}
            className="flex w-full min-w-0 flex-col gap-3"
        >
            <div className="flex items-center justify-between gap-2">
                <div className="flex flex-col gap-1">
                    <h2
                        id="room-revenue-preview"
                        className="text-sm font-medium"
                    >
                        {title}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {sample} of {money.format(Number(preview.sampleAmount))}{' '}
                        with the selected taxes.
                    </p>
                </div>
                {loading && <Spinner />}
            </div>
            {preview.refusal ? (
                <p className="text-sm text-destructive">{preview.refusal}</p>
            ) : (
                <div className="overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Line</TableHead>
                                <TableHead className="text-right">
                                    {localLabel}
                                </TableHead>
                                <TableHead className="text-right">
                                    Foreign guest
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow>
                                <TableCell>{revenueLabel}</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {amount(preview.localNet)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {amount(preview.foreignNet)}
                                </TableCell>
                            </TableRow>
                            {preview.lines.map((line) => (
                                <TableRow key={line.taxId}>
                                    <TableCell>
                                        {line.name}
                                        {line.inclusive && (
                                            <span className="text-xs text-muted-foreground">
                                                {' '}
                                                · included in the price
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {amount(line.localAmount)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {amount(line.foreignAmount)}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {offTaxes.map((tax) => (
                                <TableRow
                                    key={tax.id}
                                    className="text-muted-foreground"
                                >
                                    <TableCell>{tax.name}</TableCell>
                                    <TableCell className="text-right">
                                        Off
                                    </TableCell>
                                    <TableCell className="text-right">
                                        Off
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell className="font-medium">
                                    Guest pays
                                </TableCell>
                                <TableCell className="text-right font-medium tabular-nums">
                                    {amount(preview.localTotal)}
                                </TableCell>
                                <TableCell className="text-right font-medium tabular-nums">
                                    {amount(preview.foreignTotal)}
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            )}
        </section>
    );
}
