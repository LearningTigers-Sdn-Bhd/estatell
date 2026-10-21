import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ChevronDown,
    Plus,
    Save,
    Trash2,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { ThirdPartyFolioDialog } from '@/components/pms/folio/third-party-folio-dialog';
import { FormActions } from '@/components/pms/form-actions';
import { FormError } from '@/components/pms/form-error';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { Workspace } from '@/components/pms/workspace';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { folio as folioTab, index, show } from '@/routes/reservations';
import { oldFolio } from '@/routes/reservations';
import { store as storeOldFolios } from '@/routes/reservations/old-folio';
import { store as storeOldFolioFolio } from '@/routes/reservations/old-folio/folios';

type Page = App.Data.Folio.OldFolioPageData;
type Kind = App.Enums.OldFolioRowKind;

type Props = {
    oldFolio: Page;
    companies?: App.Data.CityLedger.CompanyOptionData[];
};

/** One draft row. `item` is `charge:{id}`, `tax:{id}` or `payment:{id}`; a room night has none. */
type Draft = {
    key: string;
    kind: Kind;
    service_date: string;
    folio_id: number;
    unit_id: number | null;
    item: string;
    description: string;
    quantity: string;
    amount: string;
};

const PLACEHOLDER: Record<Kind, string> = {
    room: 'e.g. Room · Standard Double',
    charge: 'e.g. 2 x Coke',
    tax: 'e.g. SST 8%',
    payment: 'e.g. OR-1203',
};

/** The picked item of a saved row: `charge:{id}`, `tax:{id}` or `payment:{id}`. A room night and a new row have none. */
function itemOf(row: App.Data.Folio.OldFolioRowData): string {
    if (row.chargeItemId !== null) {
        return `charge:${row.chargeItemId}`;
    }

    if (row.taxId !== null) {
        return `tax:${row.taxId}`;
    }

    return row.paymentMethodId !== null ? `payment:${row.paymentMethodId}` : '';
}

/** The day before go-live: the latest date a row may take. */
function dayBefore(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day - 1))
        .toISOString()
        .slice(0, 10);
}

/** The kind a picked item gives the row. */
function kindOf(item: string, fallback: Kind): Kind {
    const prefix = item.split(':')[0];

    return prefix === 'charge' || prefix === 'tax' || prefix === 'payment'
        ? prefix
        : fallback;
}

/** What one row adds to the folio: a payment lowers the balance. Display only; the server decides. */
function rowTotal(row: Draft): number {
    const amount = Number(row.amount || 0);
    const quantity =
        row.kind === 'charge' || row.kind === 'room'
            ? Number(row.quantity || 0)
            : 1;
    const total = amount * quantity;

    return Math.round((row.kind === 'payment' ? -total : total) * 100) / 100;
}

const sum = (rows: Draft[]): string =>
    rows.reduce((all, row) => all + rowTotal(row), 0).toFixed(2);

function isIncomplete(row: Draft): boolean {
    return (
        row.amount === '' ||
        row.service_date === '' ||
        (row.kind !== 'room' && row.item === '') ||
        (row.kind === 'payment' && row.description.trim() === '')
    );
}

function toPayload(row: Draft) {
    const [prefix, id] = row.item.split(':');
    const hasQuantity = row.kind === 'charge' || row.kind === 'room';

    return {
        key: row.key,
        kind: row.kind,
        service_date: row.service_date,
        folio_id: row.folio_id,
        unit_id: row.unit_id,
        charge_item_id: prefix === 'charge' ? Number(id) : null,
        tax_id: prefix === 'tax' ? Number(id) : null,
        payment_method_id: prefix === 'payment' ? Number(id) : null,
        description: row.description,
        quantity: hasQuantity ? Number(row.quantity || 0) : 1,
        amount: row.amount,
    };
}

/**
 * Copy old folio: one tab for each Open folio of the reservation, where staff enter the lines their old folios show.
 * Room nights before go-live are drafted from the unit nights. Tax is a row of its own and payments use the Old
 * system method. Save posts every tab at once; the lines keep their own dates and post on the business date. The page
 * opens with the lines saved before, so staff can change or remove them while the migration window is open: the server
 * compares the rows with the saved lines and corrects only what changed. The browser holds what was typed; the server
 * checks and posts everything.
 * See docs/12-reservations/12-past-and-migrated-stays.md#copy-line-by-line.
 */
export default function OldFolio({ oldFolio: page, companies }: Props) {
    const lastDay = dayBefore(page.goLiveDate);
    const [rows, setRows] = useState<Draft[]>(() =>
        page.tabs.flatMap((tab) =>
            tab.rows.map((row) => ({
                key: row.key,
                kind: row.kind,
                service_date: row.serviceDate,
                folio_id: row.folioId,
                unit_id: row.unitId,
                item: itemOf(row),
                description: row.description ?? '',
                quantity: String(row.quantity),
                amount: row.amount,
            })),
        ),
    );
    const [numbers, setNumbers] = useState<Record<number, string>>(() =>
        Object.fromEntries(
            page.tabs.map((tab) => [tab.folioId, tab.legacyNumber ?? '']),
        ),
    );
    const [tab, setTab] = useState(String(page.tabs[0]?.folioId ?? ''));
    const [touched, setTouched] = useState(false);
    const [thirdParty, setThirdParty] = useState(false);
    const [addingMaster, setAddingMaster] = useState(false);
    const known = useRef(new Set(page.tabs.map((one) => one.folioId)));
    const form = useForm({});
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        const added = page.tabs.find((one) => !known.current.has(one.folioId));

        known.current = new Set(page.tabs.map((one) => one.folioId));

        if (added) {
            setTab(String(added.folioId));
            setNumbers((all) => ({
                ...all,
                [added.folioId]: added.legacyNumber ?? '',
            }));
        }
    }, [page.tabs]);

    const items = useMemo(
        () =>
            page.items.map((item) => ({
                value: `${item.kind}:${item.id}`,
                label: item.label,
                description:
                    item.kind === 'tax'
                        ? 'Tax'
                        : item.kind === 'payment'
                          ? 'Payment'
                          : 'Charge',
            })),
        [page.items],
    );
    const folioOptions = page.tabs.map((one) => ({
        value: String(one.folioId),
        label: one.label,
    }));
    const guard = useUnsavedChanges(touched && !form.processing);
    const unfinished = rows.some(isIncomplete);
    const canSave =
        (rows.length > 0 || touched) && !unfinished && !form.processing;
    const folioId = Number(tab);

    const change = (key: string, patch: Partial<Draft>) => {
        setTouched(true);
        setRows((all) =>
            all.map((row) => (row.key === key ? { ...row, ...patch } : row)),
        );
    };

    const addRow = () => {
        setTouched(true);
        setRows((all) => [
            ...all,
            {
                key: crypto.randomUUID(),
                kind: 'charge',
                service_date: lastDay,
                folio_id: folioId,
                unit_id: null,
                item: '',
                description: '',
                quantity: '1',
                amount: '',
            },
        ]);
    };

    const submit = () => {
        if (!canSave) {
            return;
        }

        form.transform(() => ({
            rows: rows.map(toPayload),
            legacy_numbers: numbers,
        }));
        form.post(storeOldFolios.url({ reservation: page.reservationId }), {
            preserveScroll: true,
            onSuccess: () => guard.proceed(),
        });
    };

    const addMaster = () => {
        setAddingMaster(true);
        router.post(
            storeOldFolioFolio.url({ reservation: page.reservationId }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setAddingMaster(false),
            },
        );
    };

    const grandTotal = sum(rows);
    const folioTotal = (id: number) =>
        sum(rows.filter((row) => row.folio_id === id));

    return (
        <>
            <Head title={page.title} />

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
                className="flex min-h-0 flex-1 flex-col"
            >
                <div
                    scroll-region=""
                    className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 lg:overflow-hidden"
                >
                    <PageHeader
                        title={page.title}
                        description={`Lines before go-live (${page.goLiveDate}). Enter them as your old folios show them.`}
                        back={{ href: page.cancelUrl, label: 'folio' }}
                    />

                    <FormError errors={errors} />

                    <Workspace
                        asideLabel="Reservation summary"
                        aside={
                            <dl className="grid grid-cols-[1fr_auto] gap-2 p-3 text-sm">
                                <dt className="col-span-2 font-medium">
                                    Summary
                                </dt>
                                <dt className="text-muted-foreground">
                                    This folio
                                </dt>
                                <dd className="text-right tabular-nums">
                                    <MoneyText
                                        amount={folioTotal(folioId)}
                                        currency={page.currency}
                                        plain
                                    />
                                </dd>
                                <dt className="text-muted-foreground">
                                    All folios
                                </dt>
                                <dd className="text-right font-medium tabular-nums">
                                    <MoneyText
                                        amount={grandTotal}
                                        currency={page.currency}
                                        plain
                                    />
                                </dd>
                            </dl>
                        }
                    >
                        <Tabs
                            value={tab}
                            onValueChange={setTab}
                            className="flex flex-col gap-1.5"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <TabsList variant="line">
                                    {page.tabs.map((one) => (
                                        <TabsTrigger
                                            key={one.folioId}
                                            value={String(one.folioId)}
                                        >
                                            {one.label}
                                        </TabsTrigger>
                                    ))}
                                </TabsList>
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        render={
                                            <Button
                                                type="button"
                                                variant="outline"
                                            />
                                        }
                                    >
                                        <Plus data-icon="inline-start" />
                                        Folio
                                        <ChevronDown data-icon="inline-end" />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {page.canAddMaster && (
                                            <DropdownMenuItem
                                                disabled={addingMaster}
                                                onClick={addMaster}
                                            >
                                                <Users />
                                                Master folio
                                            </DropdownMenuItem>
                                        )}
                                        <DropdownMenuItem
                                            onClick={() => setThirdParty(true)}
                                        >
                                            <UserPlus />
                                            Third-party folio…
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>

                            {page.tabs.length === 0 && (
                                <p className="p-6 text-center text-sm text-muted-foreground">
                                    This reservation has no open folio yet. Add
                                    a folio to enter its old lines.
                                </p>
                            )}

                            {page.tabs.map((one) => (
                                <TabsContent
                                    key={one.folioId}
                                    value={String(one.folioId)}
                                    className="flex flex-col gap-3"
                                >
                                    <div className="flex max-w-xs flex-col gap-1.5">
                                        <label
                                            htmlFor={`old-number-${one.folioId}`}
                                            className="text-sm font-medium"
                                        >
                                            Old folio no.
                                        </label>
                                        <Input
                                            id={`old-number-${one.folioId}`}
                                            maxLength={50}
                                            placeholder="e.g. F1203"
                                            value={numbers[one.folioId] ?? ''}
                                            onChange={(event) => {
                                                setTouched(true);
                                                setNumbers((all) => ({
                                                    ...all,
                                                    [one.folioId]:
                                                        event.target.value,
                                                }));
                                            }}
                                        />
                                    </div>

                                    {one.openingBalance !== null && (
                                        <p className="text-sm text-muted-foreground">
                                            This folio carries an opening
                                            balance of{' '}
                                            <MoneyText
                                                amount={one.openingBalance}
                                                currency={page.currency}
                                                plain
                                            />
                                            . Saving lines here replaces it.
                                        </p>
                                    )}

                                    {
                                        <div className="overflow-hidden rounded-lg border">
                                            <Table>
                                                <TableHeader className="hidden xl:table-header-group">
                                                    <TableRow>
                                                        <TableHead className="min-w-40">
                                                            Date
                                                        </TableHead>
                                                        <TableHead className="min-w-48">
                                                            Item / method
                                                        </TableHead>
                                                        <TableHead className="min-w-48">
                                                            Description
                                                        </TableHead>
                                                        <TableHead className="min-w-20">
                                                            Qty
                                                        </TableHead>
                                                        <TableHead className="min-w-32">
                                                            Amount
                                                        </TableHead>
                                                        <TableHead className="min-w-44">
                                                            Folio
                                                        </TableHead>
                                                        <TableHead>
                                                            <span className="sr-only">
                                                                Remove
                                                            </span>
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {rows
                                                        .map((row, index) => ({
                                                            row,
                                                            index,
                                                        }))
                                                        .filter(
                                                            ({ row }) =>
                                                                row.folio_id ===
                                                                one.folioId,
                                                        )
                                                        .map(
                                                            (
                                                                { row, index },
                                                                position,
                                                            ) => (
                                                                <DraftRow
                                                                    key={
                                                                        row.key
                                                                    }
                                                                    row={row}
                                                                    label={`Line ${position + 1}`}
                                                                    page={page}
                                                                    items={
                                                                        items
                                                                    }
                                                                    folioOptions={
                                                                        folioOptions
                                                                    }
                                                                    lastDay={
                                                                        lastDay
                                                                    }
                                                                    problem={
                                                                        Object.entries(
                                                                            errors,
                                                                        ).find(
                                                                            ([
                                                                                name,
                                                                            ]) =>
                                                                                name.startsWith(
                                                                                    `rows.${index}.`,
                                                                                ),
                                                                        )?.[1]
                                                                    }
                                                                    processing={
                                                                        form.processing
                                                                    }
                                                                    onChange={
                                                                        change
                                                                    }
                                                                    onRemove={() => {
                                                                        setTouched(
                                                                            true,
                                                                        );
                                                                        setRows(
                                                                            (
                                                                                all,
                                                                            ) =>
                                                                                all.filter(
                                                                                    (
                                                                                        other,
                                                                                    ) =>
                                                                                        other.key !==
                                                                                        row.key,
                                                                                ),
                                                                        );
                                                                    }}
                                                                />
                                                            ),
                                                        )}
                                                </TableBody>
                                                <TableFooter>
                                                    <TableRow className="block xl:table-row">
                                                        <TableCell
                                                            colSpan={7}
                                                            className="whitespace-normal max-xl:block"
                                                        >
                                                            <div className="flex items-center justify-between gap-3">
                                                                <span className="text-sm text-muted-foreground">
                                                                    {
                                                                        rows.length
                                                                    }{' '}
                                                                    of{' '}
                                                                    {
                                                                        page.maxRows
                                                                    }{' '}
                                                                    lines in all
                                                                </span>
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    onClick={
                                                                        addRow
                                                                    }
                                                                    disabled={
                                                                        rows.length >=
                                                                        page.maxRows
                                                                    }
                                                                >
                                                                    <Plus data-icon="inline-start" />
                                                                    Add line
                                                                </Button>
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                </TableFooter>
                                            </Table>
                                        </div>
                                    }
                                </TabsContent>
                            ))}
                        </Tabs>
                    </Workspace>
                </div>

                <FormActions fixed>
                    <Link
                        href={page.cancelUrl}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        <X data-icon="inline-start" />
                        Discard
                    </Link>
                    <Button
                        type="submit"
                        disabled={!canSave}
                        focusableWhenDisabled
                    >
                        {form.processing ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <Save data-icon="inline-start" />
                        )}
                        Save old folios
                    </Button>
                </FormActions>
            </form>

            <ThirdPartyFolioDialog
                open={thirdParty}
                onOpenChange={setThirdParty}
                reservationId={page.reservationId}
                canViewCompany={page.canViewCompany}
                companies={companies}
                url={storeOldFolioFolio.url({
                    reservation: page.reservationId,
                })}
            />

            <UnsavedChangesDialog
                guard={guard}
                name="these old folio lines"
                saving={form.processing}
                onSave={submit}
            />
        </>
    );
}

function DraftRow({
    row,
    label,
    page,
    items,
    folioOptions,
    lastDay,
    problem,
    processing,
    onChange,
    onRemove,
}: {
    row: Draft;
    label: string;
    page: Page;
    items: { value: string; label: string; description: string }[];
    folioOptions: { value: string; label: string }[];
    lastDay: string;
    problem: string | undefined;
    processing: boolean;
    onChange: (key: string, patch: Partial<Draft>) => void;
    onRemove: () => void;
}) {
    const hasQuantity = row.kind === 'charge' || row.kind === 'room';

    return (
        <>
            <TableRow className="grid grid-cols-2 gap-2 p-3 xl:table-row xl:p-0">
                <Cell label="Date">
                    <DatePicker
                        id={`old-${row.key}-date`}
                        value={row.service_date}
                        onChange={(value) =>
                            onChange(row.key, { service_date: value })
                        }
                        variant="dropdown"
                        max={lastDay}
                    />
                </Cell>
                <Cell label="Item / method">
                    {row.kind === 'room' ? (
                        <span className="text-sm">Room night</span>
                    ) : (
                        <EnumSelect
                            aria-label={`${label} item`}
                            options={items}
                            placeholder="Choose an item"
                            value={row.item}
                            onValueChange={(value) =>
                                onChange(row.key, {
                                    item: value,
                                    kind: kindOf(value, row.kind),
                                    quantity: '1',
                                })
                            }
                        />
                    )}
                </Cell>
                <Cell label="Description">
                    <Input
                        aria-label={`${label} description`}
                        maxLength={120}
                        placeholder={PLACEHOLDER[row.kind]}
                        value={row.description}
                        onChange={(event) =>
                            onChange(row.key, {
                                description: event.target.value,
                            })
                        }
                    />
                </Cell>
                <Cell label="Qty">
                    {hasQuantity ? (
                        <Input
                            aria-label={`${label} quantity`}
                            type="number"
                            min={1}
                            max={999}
                            placeholder="e.g. 2"
                            className="tabular-nums xl:w-16"
                            value={row.quantity}
                            onChange={(event) =>
                                onChange(row.key, {
                                    quantity: event.target.value,
                                })
                            }
                        />
                    ) : (
                        <span
                            aria-hidden="true"
                            className="text-muted-foreground"
                        >
                            ·
                        </span>
                    )}
                </Cell>
                <Cell label="Amount">
                    <MoneyInput
                        id={`old-${row.key}-amount`}
                        value={row.amount}
                        onChange={(value) =>
                            onChange(row.key, { amount: value })
                        }
                        currency={page.currency}
                    />
                </Cell>
                <Cell label="Folio">
                    <EnumSelect
                        aria-label={`${label} folio`}
                        options={folioOptions}
                        value={String(row.folio_id)}
                        onValueChange={(value) =>
                            onChange(row.key, { folio_id: Number(value) })
                        }
                    />
                </Cell>
                <Cell label="" className="col-span-2 xl:col-span-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={`Remove ${label.toLowerCase()}`}
                        onClick={onRemove}
                        disabled={processing}
                        focusableWhenDisabled
                    >
                        <Trash2 />
                    </Button>
                </Cell>
            </TableRow>
            {problem && (
                <TableRow className="block border-0 px-3 xl:table-row xl:px-0">
                    <TableCell
                        colSpan={7}
                        className="block pt-0 whitespace-normal xl:table-cell"
                    >
                        <FieldError>
                            {label}: {problem}
                        </FieldError>
                    </TableCell>
                </TableRow>
            )}
        </>
    );
}

/** A table cell that is a labelled block at phone width. */
function Cell({
    label,
    className,
    children,
}: {
    label: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <TableCell
            className={`flex flex-col gap-1 align-top whitespace-normal xl:table-cell ${className ?? ''}`}
        >
            {label !== '' && (
                <span className="text-xs text-muted-foreground xl:hidden">
                    {label}
                </span>
            )}
            {children}
        </TableCell>
    );
}

OldFolio.layout = (props: {
    oldFolio: Page;
    property: App.Data.Property.PropertySummaryData;
}) => ({
    variant: 'fixed' as const,
    breadcrumbs: [
        { title: 'Reservations', href: index(props.property.code) },
        {
            title: props.oldFolio.reference,
            href: show({
                property: props.property.code,
                reservation: props.oldFolio.reservationId,
            }),
        },
        {
            title: 'Folio',
            href: folioTab({
                property: props.property.code,
                reservation: props.oldFolio.reservationId,
            }),
        },
        {
            title: 'Copy old folio',
            href: oldFolio({
                property: props.property.code,
                reservation: props.oldFolio.reservationId,
            }),
        },
    ],
});
