import { Head, Link, useForm, useHttp } from '@inertiajs/react';
import { ChevronDown, Download, FileUp, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { FormActions } from '@/components/pms/form-actions';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { importMethod, importTemplate, index } from '@/routes/reservations';
import { preview, store } from '@/routes/reservations/import';

type Row = App.Data.Reservations.ArrivalImportRowData;

/**
 * Reservations › Import arrivals: upload an arrival list export from the old PMS or the estatell template, check the preview, then
 * import. The server reads the file again on import and decides everything. See docs/12-reservations/13-arrival-import.md.
 */
export default function ReservationsImport({
    openBusinessDate,
    goLiveDate,
    migrationWindowClosesOn,
    maxRows,
    currency,
}: {
    openBusinessDate: string | null;
    goLiveDate: string | null;
    migrationWindowClosesOn: string | null;
    maxRows: number;
    currency: App.Data.Property.CurrencyData;
}) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [rows, setRows] = useState<Row[] | null>(null);
    const upload = useHttp<{ file: File | null }, { rows: Row[] }>(preview(), {
        file: null,
    });
    const form = useForm<{ file: File | null; reason: string }>({
        file: null,
        reason: 'Imported from old system arrival list',
    });

    const [dragging, setDragging] = useState(false);
    const [dropError, setDropError] = useState<string | null>(null);
    const dragDepth = useRef(0);

    const readFile = (file: File) => {
        if (!/\.(csv|xlsx)$/i.test(file.name)) {
            setDropError(`${file.name} is not a CSV or XLSX file.`);

            return;
        }

        setDropError(null);
        form.setData('file', file);
        setRows(null);
        upload.transform(() => ({ file }));
        upload
            .submit()
            .then(({ rows: planned }) => setRows(planned))
            .catch(() => undefined);
    };

    const importing =
        rows?.filter(
            (row) =>
                row.outcome !== 'skipped' && row.outcome !== 'already_imported',
        ) ?? [];
    const withProblems = rows?.filter((row) => row.problems.length > 0) ?? [];
    const bookings = new Set(
        importing.map((row) => `${row.groupNo}|${row.outcome}`),
    ).size;
    const canImport =
        form.data.file !== null &&
        rows !== null &&
        importing.length > 0 &&
        withProblems.length === 0;

    return (
        <>
            <Head title="Import arrivals" />

            <form
                className="flex min-w-0 flex-1 flex-col gap-6 p-4 pb-0"
                onDragEnter={(event) => {
                    if (!event.dataTransfer.types.includes('Files')) {
                        return;
                    }

                    event.preventDefault();
                    dragDepth.current += 1;
                    setDragging(true);
                }}
                onDragOver={(event) => {
                    if (event.dataTransfer.types.includes('Files')) {
                        event.preventDefault();
                        event.dataTransfer.dropEffect = 'copy';
                    }
                }}
                onDragLeave={() => {
                    dragDepth.current = Math.max(0, dragDepth.current - 1);

                    if (dragDepth.current === 0) {
                        setDragging(false);
                    }
                }}
                onDrop={(event) => {
                    event.preventDefault();
                    dragDepth.current = 0;
                    setDragging(false);

                    const file = event.dataTransfer.files[0];

                    if (file && !upload.processing) {
                        readFile(file);
                    }
                }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(store.url(), { preserveScroll: true });
                }}
            >
                <PageHeader
                    title="Import arrivals"
                    description={`Bring in bookings from your old PMS, up to ${maxRows} rows. Check the preview, then import. A newer export adds only new bookings.`}
                    back={{ href: index(), label: 'Reservations' }}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <input
                                ref={fileInput}
                                type="file"
                                accept=".csv,.xlsx"
                                className="hidden"
                                onChange={(event) => {
                                    const file = event.target.files?.[0];

                                    if (file) {
                                        readFile(file);
                                    }

                                    event.target.value = '';
                                }}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                disabled={upload.processing}
                                focusableWhenDisabled
                                onClick={() => fileInput.current?.click()}
                            >
                                {upload.processing ? (
                                    <Spinner data-icon="inline-start" />
                                ) : (
                                    <Upload data-icon="inline-start" />
                                )}
                                Upload CSV / XLSX
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger
                                    render={
                                        <Button
                                            type="button"
                                            variant="outline"
                                        />
                                    }
                                >
                                    <Download data-icon="inline-start" />
                                    Template
                                    <ChevronDown data-icon="inline-end" />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        render={
                                            <a
                                                href={importTemplate.url({
                                                    format: 'csv',
                                                })}
                                                download
                                            />
                                        }
                                    >
                                        CSV
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        render={
                                            <a
                                                href={importTemplate.url({
                                                    format: 'xlsx',
                                                })}
                                                download
                                            />
                                        }
                                    >
                                        Excel (XLSX)
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    }
                />

                <dl className="grid max-w-3xl gap-3 sm:grid-cols-3">
                    <ImportFact
                        label="Business date"
                        hint="Earlier arrivals become past stays"
                    >
                        <DateText date={openBusinessDate} />
                    </ImportFact>
                    <ImportFact
                        label="Go-live"
                        hint="Past stays start from here"
                    >
                        <DateText date={goLiveDate} />
                    </ImportFact>
                    <ImportFact
                        label="Deposits accepted until"
                        hint={
                            migrationWindowClosesOn
                                ? 'Recorded as paid in the old system'
                                : 'The migration window is closed'
                        }
                    >
                        {migrationWindowClosesOn ? (
                            <DateText date={migrationWindowClosesOn} />
                        ) : (
                            'Closed'
                        )}
                    </ImportFact>
                </dl>

                <Field className="max-w-3xl">
                    <FieldLabel htmlFor="reason">Reason</FieldLabel>
                    <Input
                        id="reason"
                        name="reason"
                        value={form.data.reason}
                        aria-invalid={!!form.errors.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                    />
                    {form.errors.reason && (
                        <FieldError>{form.errors.reason}</FieldError>
                    )}
                </Field>

                {dropError && <FieldError>{dropError}</FieldError>}
                {upload.errors.file && (
                    <FieldError>{upload.errors.file}</FieldError>
                )}
                {form.errors.file && (
                    <FieldError>{form.errors.file}</FieldError>
                )}

                {rows === null ? (
                    <Empty
                        className={cn(
                            'border border-dashed transition-colors',
                            dragging && 'border-primary bg-primary/5',
                        )}
                    >
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                {upload.processing ? <Spinner /> : <FileUp />}
                            </EmptyMedia>
                            <EmptyTitle>
                                {dragging
                                    ? 'Drop the file to read it'
                                    : 'Drag a file here'}
                            </EmptyTitle>
                            <EmptyDescription>
                                Drop the arrival list from your old PMS or the
                                estatell template (CSV or XLSX) to see what each
                                row becomes. Nothing is saved until you import.
                            </EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={upload.processing}
                                focusableWhenDisabled
                                onClick={() => fileInput.current?.click()}
                            >
                                <Upload data-icon="inline-start" />
                                Choose a file
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <>
                        {dragging && (
                            <p className="rounded-lg border border-dashed border-primary bg-primary/5 p-3 text-sm">
                                Drop the file to replace {form.data.file?.name}.
                            </p>
                        )}
                        <p className="text-sm">
                            {form.data.file?.name} · {rows.length} rows:{' '}
                            {importing.length} rooms in {bookings}{' '}
                            {bookings === 1 ? 'booking' : 'bookings'},{' '}
                            {rows.length - importing.length} left out
                            {withProblems.length > 0 && (
                                <span className="text-destructive">
                                    {' '}
                                    · {withProblems.length}{' '}
                                    {withProblems.length === 1
                                        ? 'row has'
                                        : 'rows have'}{' '}
                                    problems. Fix the file and upload it again.
                                </span>
                            )}
                        </p>
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-10">
                                            #
                                        </TableHead>
                                        <TableHead>Res no</TableHead>
                                        <TableHead>Guest</TableHead>
                                        <TableHead>Room</TableHead>
                                        <TableHead>Stay</TableHead>
                                        <TableHead className="text-right">
                                            Pax
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Total
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Deposit
                                        </TableHead>
                                        <TableHead>Company · source</TableHead>
                                        <TableHead>Boat in · out</TableHead>
                                        <TableHead>Becomes</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <ImportRow
                                            key={row.line}
                                            row={row}
                                            currency={currency}
                                        />
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}

                <FormActions>
                    <Link
                        href={index()}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        Cancel
                    </Link>
                    <Button
                        type="submit"
                        disabled={!canImport || form.processing}
                        focusableWhenDisabled
                    >
                        {form.processing && (
                            <Spinner data-icon="inline-start" />
                        )}
                        {importing.length > 0
                            ? `Import ${importing.length} ${importing.length === 1 ? 'room' : 'rooms'}`
                            : 'Import'}
                    </Button>
                </FormActions>
            </form>
        </>
    );
}

function ImportFact({
    label,
    hint,
    children,
}: {
    label: string;
    hint: string;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1 rounded-lg border p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-medium tabular-nums">{children}</dd>
            <dd className="text-xs text-muted-foreground">{hint}</dd>
        </div>
    );
}

function ImportRow({
    row,
    currency,
}: {
    row: Row;
    currency: App.Data.Property.CurrencyData;
}) {
    const hasProblems = row.problems.length > 0;

    return (
        <TableRow aria-invalid={hasProblems || undefined}>
            <TableCell className="align-top text-muted-foreground tabular-nums">
                {row.line}
            </TableCell>
            <TableCell className="align-top">
                <div className="font-medium">{row.resNo}</div>
                {row.groupNo !== row.resNo && (
                    <div className="text-xs text-muted-foreground">
                        Group {row.groupNo}
                    </div>
                )}
            </TableCell>
            <TableCell className="align-top">
                <div>{row.guest}</div>
                {hasProblems && (
                    <ul className="flex flex-col gap-0.5 pt-1 text-xs text-destructive">
                        {row.problems.map((problem) => (
                            <li key={problem}>{problem}</li>
                        ))}
                    </ul>
                )}
            </TableCell>
            <TableCell className="align-top">
                <div>{row.room ?? 'Unassigned'}</div>
                <div className="text-xs text-muted-foreground">
                    {row.roomType}
                </div>
            </TableCell>
            <TableCell className="align-top whitespace-nowrap">
                <DateText date={row.arrival} /> →{' '}
                <DateText date={row.departure} />
            </TableCell>
            <TableCell className="text-right align-top tabular-nums">
                {row.adults}
                {row.children > 0 ? ` + ${row.children}` : ''}
            </TableCell>
            <TableCell className="text-right align-top">
                {row.total === null ? (
                    '—'
                ) : (
                    <MoneyText amount={row.total} currency={currency} plain />
                )}
            </TableCell>
            <TableCell className="text-right align-top">
                {row.deposit === null ? (
                    '—'
                ) : (
                    <MoneyText amount={row.deposit} currency={currency} plain />
                )}
            </TableCell>
            <TableCell className="align-top">
                <div>{row.company ?? '—'}</div>
                <div className="text-xs text-muted-foreground">
                    {row.source}
                </div>
            </TableCell>
            <TableCell className="align-top whitespace-nowrap">
                {row.boatIn ?? '—'} · {row.boatOut ?? '—'}
            </TableCell>
            <TableCell className="align-top">
                <StatusBadge status={row.outcomeBadge} />
            </TableCell>
        </TableRow>
    );
}

ReservationsImport.layout = {
    breadcrumbs: [
        { title: 'Reservations', href: index() },
        { title: 'Import arrivals', href: importMethod() },
    ],
};
