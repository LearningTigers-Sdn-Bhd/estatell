import { Info, Pencil, Trash2 } from 'lucide-react';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { taxLabel } from '@/components/pms/charge-item-taxes-field';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { ManualPayloadRow } from '@/components/pms/folio/use-manual-preview';

type Page = App.Data.Folio.ManualFolioPageData;
type RowType = App.Enums.ManualRowType;

/** One draft row. Ids are strings (the selects' values); an empty string means nothing is picked. */
export type ManualDraft = {
    key: string;
    type: RowType;
    service_date: string;
    folio_id: string;
    unit_id: string;
    charge_item_id: string;
    description: string;
    quantity: string;
    amount: string;
    /** Null starts from the charge item's own taxes; a list is the user's choice. */
    tax_ids: string[] | null;
    payment_method_id: string;
    payment_id: string;
    reference: string;
};

const TYPES: { value: RowType; label: string }[] = [
    { value: 'charge', label: 'Charge' },
    { value: 'payment', label: 'Payment' },
    { value: 'refund', label: 'Refund' },
];

/** A new row dated today, on the given folio. */
export function newDraft(
    page: Page,
    folioId: number,
    type: RowType = 'charge',
): ManualDraft {
    const own = page.folios.find((folio) => folio.id === folioId);

    return {
        key: crypto.randomUUID(),
        type,
        service_date: page.businessDate,
        folio_id: String(folioId),
        unit_id:
            own?.unitId == null && page.units.length === 1
                ? String(page.units[0].id)
                : '',
        charge_item_id: '',
        description: '',
        quantity: '1',
        amount: '',
        tax_ids: null,
        payment_method_id: '',
        payment_id: '',
        reference: '',
    };
}

const toId = (value: string): number | null =>
    value === '' ? null : Number(value);

/** What the server reads: the same row with numbers, and no field that does not belong to the row's type. */
export function toPayload(row: ManualDraft): ManualPayloadRow {
    return {
        key: row.key,
        type: row.type,
        service_date: row.service_date,
        folio_id: Number(row.folio_id),
        unit_id: toId(row.unit_id),
        charge_item_id: row.type === 'charge' ? toId(row.charge_item_id) : null,
        description: row.type === 'charge' ? row.description : null,
        quantity: row.type === 'charge' ? Number(row.quantity || 0) : 1,
        amount: row.amount,
        tax_ids:
            row.type === 'charge' && row.tax_ids !== null
                ? row.tax_ids.map(Number)
                : null,
        payment_method_id:
            row.type === 'payment' ? toId(row.payment_method_id) : null,
        payment_id: row.type === 'refund' ? toId(row.payment_id) : null,
        reference: row.type === 'charge' ? null : row.reference,
    };
}

/** A row the user has not finished: nothing is picked or no amount is typed yet. The server is not asked to judge it. */
export function isIncomplete(row: ManualDraft): boolean {
    const picked =
        row.type === 'charge'
            ? row.charge_item_id
            : row.type === 'payment'
              ? row.payment_method_id
              : row.payment_id;

    return picked === '' || row.amount === '';
}

const FIELD_OF: Record<string, string> = {
    unit_amount: 'amount',
    charge_item_id: 'item',
    payment_method_id: 'item',
    payment_id: 'item',
};

/**
 * The New lines table of one folio: one row per line to post on it. `rows` holds the drafts of every folio, so a
 * server error (`rows.{index}.…`) keeps its index; only the rows of `folioId` show. There is no Folio column: a row
 * posts on the folio it was added to. The Room column shows on a folio without its own unit, unless the reservation
 * has one postable unit (it is then preselected). At phone width each row stacks into a card with a label on every
 * cell. The server answers each row's tax lines, total and refusal; the browser only holds what was typed.
 */
export function ManualFolioRows({
    page,
    folioId,
    rows,
    onChange,
    onRemove,
    preview,
    serverErrors,
    processing,
}: {
    page: Page;
    folioId: number;
    rows: ManualDraft[];
    onChange: (key: string, patch: Partial<ManualDraft>) => void;
    onRemove: (key: string) => void;
    preview: App.Data.Folio.ManualPreviewData;
    serverErrors: Record<string, string | undefined>;
    processing: boolean;
}) {
    const currency = page.currency;
    const answers = new Map(preview.rows.map((one) => [one.key, one]));
    const itemOptions = page.chargeItems.map((one) => ({
        value: String(one.id),
        label: one.name,
    }));
    const taxOptions = page.taxes.map((tax) => ({
        value: String(tax.id),
        label: currency ? taxLabel(tax, currency) : tax.name,
    }));
    const folio = page.folios.find((one) => one.id === folioId);
    const showRoom = folio?.unitId == null && page.units.length !== 1;
    const columns = showRoom ? 10 : 9;
    const shown = rows
        .map((row, index) => ({ row, index }))
        .filter(({ row }) => row.folio_id === String(folioId));
    const unitOptions = page.units.map((one) => ({
        value: String(one.id),
        label: one.label,
    }));
    const methodOptions = page.paymentMethods.map((one) => ({
        value: String(one.id),
        label: one.name,
    }));
    const oldSystemIds = new Set(
        page.paymentMethods
            .filter((one) => one.isOldSystem)
            .map((one) => String(one.id)),
    );
    const paymentOptions = page.payments
        .filter((one) => one.folioId === folioId)
        .map((one) => ({
            value: String(one.id),
            label: one.label,
            description: `${one.left} left to refund`,
        }));

    return (
        <div className="overflow-hidden rounded-lg border">
            <Table>
                <TableHeader className="hidden xl:table-header-group">
                    <TableRow>
                        <TableHead className="min-w-28">Type</TableHead>
                        <TableHead className="min-w-40">
                            <span className="inline-flex items-center gap-1">
                                Date
                                <Tooltip>
                                    <TooltipTrigger
                                        render={
                                            <span
                                                role="img"
                                                aria-label="About the date"
                                                tabIndex={0}
                                                className="text-muted-foreground"
                                            />
                                        }
                                    >
                                        <Info className="size-3.5" />
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        Lines post today ({page.businessDate})
                                        and keep the date you enter.
                                    </TooltipContent>
                                </Tooltip>
                            </span>
                        </TableHead>
                        <TableHead className="min-w-44">
                            Item / method
                        </TableHead>
                        <TableHead className="min-w-48">Description</TableHead>
                        <TableHead className="min-w-20">Qty</TableHead>
                        <TableHead className="min-w-32">Amount</TableHead>
                        <TableHead className="min-w-48">Taxes</TableHead>
                        <TableHead className="min-w-28 text-center">
                            Total
                        </TableHead>
                        {showRoom && (
                            <TableHead className="min-w-40">Room</TableHead>
                        )}
                        <TableHead>
                            <span className="sr-only">Remove</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {shown.length === 0 && (
                        <TableRow>
                            <TableCell
                                colSpan={columns}
                                className="py-6 text-center text-muted-foreground"
                            >
                                No lines yet. Add a charge, a payment or a
                                refund.
                            </TableCell>
                        </TableRow>
                    )}
                    {shown.map(({ row, index }, position) => {
                        const answer = answers.get(row.key);
                        const message = (field: string) => {
                            const server = Object.entries(serverErrors).find(
                                ([name]) =>
                                    name.startsWith(`rows.${index}.`) &&
                                    (FIELD_OF[name.split('.')[2]] ??
                                        name.split('.')[2]) === field,
                            );

                            return server?.[1];
                        };
                        const refusal = isIncomplete(row)
                            ? undefined
                            : (answer?.refusal ?? undefined);
                        const refusedField = refusal
                            ? (FIELD_OF[answer?.field ?? ''] ??
                              answer?.field ??
                              'form')
                            : null;
                        const invalid = (field: string) =>
                            refusedField === field || !!message(field);
                        const problem =
                            refusal ??
                            Object.entries(serverErrors).find(([name]) =>
                                name.startsWith(`rows.${index}.`),
                            )?.[1];
                        const item = page.chargeItems.find(
                            (one) => String(one.id) === row.charge_item_id,
                        );
                        const defaults = (
                            page.itemTaxIds[Number(row.charge_item_id)] ?? []
                        ).map(String);
                        const taxIds = row.tax_ids ?? defaults;
                        const changedTaxes =
                            row.tax_ids !== null &&
                            (row.tax_ids.length !== defaults.length ||
                                row.tax_ids.some(
                                    (id) => !defaults.includes(id),
                                ));
                        const fixedAmount =
                            item !== undefined &&
                            !item.allowOverride &&
                            item.defaultAmount !== null;
                        const label = `Line ${position + 1}`;
                        const oldSystemRow =
                            row.type === 'payment' &&
                            oldSystemIds.has(row.payment_method_id);

                        return (
                            <RowFragment key={row.key}>
                                <TableRow className="grid grid-cols-2 gap-2 p-3 xl:table-row xl:p-0">
                                    <Cell label="Type">
                                        <EnumSelect
                                            aria-label={`${label} type`}
                                            options={TYPES}
                                            value={row.type}
                                            onValueChange={(value) =>
                                                onChange(row.key, {
                                                    type: value as RowType,
                                                    amount: '',
                                                    charge_item_id: '',
                                                    payment_method_id: '',
                                                    payment_id: '',
                                                    tax_ids: null,
                                                    reference: '',
                                                    description: '',
                                                })
                                            }
                                        />
                                    </Cell>
                                    <Cell label="Date">
                                        <DatePicker
                                            id={`manual-${row.key}-date`}
                                            value={row.service_date}
                                            onChange={(value) =>
                                                onChange(row.key, {
                                                    service_date: value,
                                                })
                                            }
                                            variant={
                                                oldSystemRow
                                                    ? 'dropdown'
                                                    : 'default'
                                            }
                                            min={
                                                oldSystemRow
                                                    ? undefined
                                                    : page.earliestDate
                                            }
                                            max={page.businessDate}
                                            aria-invalid={invalid(
                                                'service_date',
                                            )}
                                        />
                                    </Cell>
                                    <Cell
                                        label={
                                            row.type === 'charge'
                                                ? 'Item'
                                                : row.type === 'payment'
                                                  ? 'Method'
                                                  : 'Payment'
                                        }
                                    >
                                        {row.type === 'charge' && (
                                            <EnumSelect
                                                aria-label={`${label} item`}
                                                options={itemOptions}
                                                placeholder="Choose a charge"
                                                value={row.charge_item_id}
                                                onValueChange={(value) => {
                                                    const next =
                                                        page.chargeItems.find(
                                                            (one) =>
                                                                String(
                                                                    one.id,
                                                                ) === value,
                                                        );

                                                    onChange(row.key, {
                                                        charge_item_id: value,
                                                        amount:
                                                            next?.defaultAmount ??
                                                            '',
                                                        tax_ids: null,
                                                    });
                                                }}
                                                aria-invalid={invalid('item')}
                                            />
                                        )}
                                        {row.type === 'payment' && (
                                            <EnumSelect
                                                aria-label={`${label} method`}
                                                options={methodOptions}
                                                placeholder="Choose a method"
                                                value={row.payment_method_id}
                                                onValueChange={(value) =>
                                                    onChange(row.key, {
                                                        payment_method_id:
                                                            value,
                                                    })
                                                }
                                                aria-invalid={invalid('item')}
                                            />
                                        )}
                                        {row.type === 'refund' && (
                                            <EnumSelect
                                                aria-label={`${label} payment to refund`}
                                                options={paymentOptions}
                                                placeholder="Choose a payment"
                                                value={row.payment_id}
                                                onValueChange={(value) => {
                                                    const next =
                                                        page.payments.find(
                                                            (one) =>
                                                                String(
                                                                    one.id,
                                                                ) === value,
                                                        );

                                                    onChange(row.key, {
                                                        payment_id: value,
                                                        amount:
                                                            next?.left ?? '',
                                                    });
                                                }}
                                                aria-invalid={invalid('item')}
                                            />
                                        )}
                                    </Cell>
                                    <Cell
                                        label={
                                            row.type === 'charge'
                                                ? 'Description'
                                                : 'Reference'
                                        }
                                    >
                                        <Input
                                            aria-label={`${label} ${row.type === 'charge' ? 'description' : 'reference'}`}
                                            maxLength={
                                                row.type === 'charge'
                                                    ? 120
                                                    : 100
                                            }
                                            placeholder={
                                                row.type === 'charge'
                                                    ? (item?.name ??
                                                      'e.g. 2 x Coke')
                                                    : oldSystemRow
                                                      ? 'e.g. OR-1203'
                                                      : 'e.g. Visa ending 4242'
                                            }
                                            value={
                                                row.type === 'charge'
                                                    ? row.description
                                                    : row.reference
                                            }
                                            onChange={(event) =>
                                                onChange(
                                                    row.key,
                                                    row.type === 'charge'
                                                        ? {
                                                              description:
                                                                  event.target
                                                                      .value,
                                                          }
                                                        : {
                                                              reference:
                                                                  event.target
                                                                      .value,
                                                          },
                                                )
                                            }
                                            aria-invalid={invalid('reference')}
                                        />
                                    </Cell>
                                    <Cell label="Qty">
                                        {row.type === 'charge' ? (
                                            <Input
                                                aria-label={`${label} quantity`}
                                                type="number"
                                                min={1}
                                                max={999}
                                                className="tabular-nums xl:w-16"
                                                value={row.quantity}
                                                onChange={(event) =>
                                                    onChange(row.key, {
                                                        quantity:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={invalid(
                                                    'quantity',
                                                )}
                                            />
                                        ) : (
                                            <Dot />
                                        )}
                                    </Cell>
                                    <Cell label="Amount">
                                        <div
                                            className="xl:min-w-(--amount-width)"
                                            style={
                                                {
                                                    '--amount-width': `${Math.max(8, row.amount.length + 8)}ch`,
                                                } as React.CSSProperties
                                            }
                                        >
                                            <MoneyInput
                                                id={`manual-${row.key}-amount`}
                                                value={row.amount}
                                                onChange={(value) =>
                                                    onChange(row.key, {
                                                        amount: value,
                                                    })
                                                }
                                                currency={currency}
                                                disabled={fixedAmount}
                                                invalid={invalid('amount')}
                                            />
                                        </div>
                                    </Cell>
                                    <Cell label="Taxes">
                                        {row.type === 'charge' ? (
                                            <div className="flex items-start gap-1">
                                                <div className="min-w-0 flex-1">
                                                    <MultiCombobox
                                                        id={`manual-${row.key}-taxes`}
                                                        name="tax_ids"
                                                        items={taxOptions}
                                                        value={taxIds}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            onChange(row.key, {
                                                                tax_ids: value,
                                                            })
                                                        }
                                                        placeholder="No taxes"
                                                        emptyLabel="No matching taxes."
                                                        invalid={invalid(
                                                            'tax_ids',
                                                        )}
                                                        maxChips={1}
                                                    />
                                                </div>
                                                {changedTaxes && (
                                                    <Tooltip>
                                                        <TooltipTrigger
                                                            render={
                                                                <span
                                                                    role="img"
                                                                    aria-label="Taxes changed from the item's default"
                                                                    tabIndex={0}
                                                                    className="mt-2 text-muted-foreground"
                                                                />
                                                            }
                                                        >
                                                            <Pencil className="size-4" />
                                                        </TooltipTrigger>
                                                        <TooltipContent>
                                                            Taxes changed from
                                                            the item&apos;s
                                                            default
                                                        </TooltipContent>
                                                    </Tooltip>
                                                )}
                                            </div>
                                        ) : (
                                            <Dot />
                                        )}
                                    </Cell>
                                    <Cell
                                        label="Total"
                                        className="xl:text-center"
                                    >
                                        {answer?.total != null &&
                                        !isIncomplete(row) ? (
                                            <span className="inline-flex items-center gap-1">
                                                <MoneyText
                                                    amount={answer.total}
                                                    currency={currency}
                                                    plain
                                                />
                                                {answer.taxes.length > 0 && (
                                                    <Popover>
                                                        <PopoverTrigger
                                                            render={
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon-xs"
                                                                    aria-label={`${label} tax details`}
                                                                />
                                                            }
                                                        >
                                                            <Info />
                                                        </PopoverTrigger>
                                                        <PopoverContent className="w-64">
                                                            <ul className="flex flex-col gap-1 text-sm">
                                                                {answer.taxes.map(
                                                                    (tax) => (
                                                                        <li
                                                                            key={
                                                                                tax.name
                                                                            }
                                                                            className="flex justify-between gap-3"
                                                                        >
                                                                            <span>
                                                                                {
                                                                                    tax.name
                                                                                }
                                                                            </span>
                                                                            <span className="tabular-nums">
                                                                                {tax.exempt
                                                                                    ? 'Exempt'
                                                                                    : tax.amount}
                                                                            </span>
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                        </PopoverContent>
                                                    </Popover>
                                                )}
                                            </span>
                                        ) : (
                                            <MoneyText
                                                amount="0.00"
                                                currency={currency}
                                                plain
                                            />
                                        )}
                                    </Cell>
                                    {showRoom && (
                                        <Cell label="Room">
                                            {row.type === 'charge' ? (
                                                <EnumSelect
                                                    aria-label={`${label} room`}
                                                    options={unitOptions}
                                                    placeholder="Choose a room"
                                                    value={row.unit_id}
                                                    onValueChange={(value) =>
                                                        onChange(row.key, {
                                                            unit_id: value,
                                                        })
                                                    }
                                                    aria-invalid={invalid(
                                                        'unit_id',
                                                    )}
                                                />
                                            ) : (
                                                <Dot />
                                            )}
                                        </Cell>
                                    )}
                                    <Cell
                                        label=""
                                        className="col-span-2 xl:col-span-1"
                                    >
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Remove ${label.toLowerCase()}`}
                                            onClick={() => onRemove(row.key)}
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
                                            colSpan={columns}
                                            className="block pt-0 whitespace-normal xl:table-cell"
                                        >
                                            <FieldError>
                                                {label}: {problem}
                                            </FieldError>
                                        </TableCell>
                                    </TableRow>
                                )}
                            </RowFragment>
                        );
                    })}
                </TableBody>
                <TableFooter>
                    <TableRow className="block xl:table-row">
                        <TableCell
                            colSpan={columns}
                            className="whitespace-normal max-xl:block"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-sm text-muted-foreground">
                                    {rows.length === 0
                                        ? 'Nothing to post yet'
                                        : shown.length === rows.length
                                          ? `${rows.length} of ${page.maxRows} lines`
                                          : `${shown.length} here · ${rows.length} of ${page.maxRows} lines in all`}
                                </span>
                                {processing && <Spinner />}
                            </div>
                        </TableCell>
                    </TableRow>
                </TableFooter>
            </Table>
            {shown.some(
                ({ row }) =>
                    row.type === 'payment' &&
                    oldSystemIds.has(row.payment_method_id),
            ) && (
                <p className="border-t px-3 py-2 text-sm text-muted-foreground">
                    An advance tied to a booking goes on its folio. Money not
                    tied to a booking goes on the company.
                </p>
            )}
        </div>
    );
}

function RowFragment({ children }: { children: React.ReactNode }) {
    return <>{children}</>;
}

function Dot() {
    return (
        <span aria-hidden="true" className="text-muted-foreground">
            ·
        </span>
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
