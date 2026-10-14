import { Link } from '@inertiajs/react';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { EnumSelect } from '@/components/pms/enum-select';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCanCorrectMoney } from '@/hooks/use-is-impersonating';
import { folio as folioTab } from '@/routes/reservations';

type Row = App.Data.Reservations.GroupCheckOutRowData;
type Method = App.Data.Payments.PaymentMethodOptionData;

/** What the user chose for one room. The server owns every figure; these are the typed fields. */
export type RowState = {
    ticked: boolean;
    choice: string | null;
    methodId: string;
    reference: string;
    amount: string;
    refundAmount: string;
};

export const initialRowState = (row: Row): RowState => ({
    ticked: row.ticked,
    choice: row.defaultChoice,
    methodId: row.methodId === null ? '' : String(row.methodId),
    reference: '',
    amount: row.collectAmount,
    refundAmount: row.refundAmount,
});

const statusText: Record<string, string> = {
    checked_out: 'Checked out',
    cancelled: 'Cancelled',
    no_show: 'No-show',
};

/** "Checked out 11 Oct" for a room that is out, else the plain status. The date is parsed as a local date. */
function statusLabel(row: Row): string | undefined {
    const text = statusText[row.status];

    if (text === undefined || row.checkedOutOn === null) {
        return text;
    }

    const [year, month, day] = row.checkedOutOn.split('-').map(Number);

    return `${text} ${new Date(year, month - 1, day).toLocaleDateString(
        'en-GB',
        { day: 'numeric', month: 'short' },
    )}`;
}

/**
 * One section of the group check-out page as a real table: a heading with the count, a header checkbox that ticks or
 * unticks every room of the section, and one row per room. On a narrow screen each row is a card.
 */
export function GroupCheckOutTable({
    heading,
    rows,
    states,
    onChange,
    onTickAll,
    methods,
    currency,
    reservationId,
    onePaymentOn,
    errors,
}: {
    heading: string;
    rows: Row[];
    states: Record<number, RowState>;
    onChange: (unitId: number, patch: Partial<RowState>) => void;
    onTickAll: (unitIds: number[], ticked: boolean) => void;
    methods: Method[];
    currency: App.Data.Property.CurrencyData | null;
    reservationId: number;
    onePaymentOn: boolean;
    errors: Record<string, string | undefined>;
}) {
    const tickable = rows.filter((row) => !row.folioClosed);
    const ticked = tickable.filter((row) => states[row.unitId].ticked);
    const all = tickable.length > 0 && ticked.length === tickable.length;

    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-sm font-medium">
                {heading} ({rows.length})
            </h2>
            <div className="overflow-auto lg:rounded-lg lg:border">
                <Table className="max-lg:block">
                    <TableHeader className="max-lg:block">
                        <TableRow className="max-lg:flex max-lg:items-center max-lg:gap-2">
                            <TableHead className="w-8">
                                <Checkbox
                                    checked={all}
                                    indeterminate={ticked.length > 0 && !all}
                                    disabled={tickable.length === 0}
                                    onCheckedChange={(checked) =>
                                        onTickAll(
                                            tickable.map((row) => row.unitId),
                                            checked === true,
                                        )
                                    }
                                    aria-label={`Select all in ${heading}`}
                                />
                            </TableHead>
                            <TableHead className="w-24 max-lg:hidden">
                                Room
                            </TableHead>
                            <TableHead className="w-48 max-lg:hidden">
                                Guest
                            </TableHead>
                            <TableHead className="w-28 text-right max-lg:hidden">
                                Balance
                            </TableHead>
                            <TableHead className="max-lg:hidden">
                                Settle
                            </TableHead>
                            <TableHead className="font-normal text-muted-foreground lg:hidden">
                                Select all
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody className="max-lg:block">
                        {rows.map((row) => (
                            <GroupCheckOutRow
                                key={row.unitId}
                                row={row}
                                state={states[row.unitId]}
                                onChange={(patch) =>
                                    onChange(row.unitId, patch)
                                }
                                methods={methods}
                                currency={currency}
                                reservationId={reservationId}
                                onePaymentOn={onePaymentOn}
                                error={errors[`units.${row.unitId}`]}
                            />
                        ))}
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}

/**
 * One room of a table section: a row on a wide screen, a card on a narrow one. It shows the settle choices the server
 * offered, prefilled by balance.
 */
function GroupCheckOutRow({
    row,
    state,
    onChange,
    methods,
    currency,
    reservationId,
    onePaymentOn,
    error,
}: {
    row: Row;
    state: RowState;
    onChange: (patch: Partial<RowState>) => void;
    methods: Method[];
    currency: App.Data.Property.CurrencyData | null;
    reservationId: number;
    onePaymentOn: boolean;
    error?: string;
}) {
    const canCorrectMoney = useCanCorrectMoney();
    const id = `unit-${row.unitId}`;
    const method = methods.find((one) => String(one.id) === state.methodId);
    const canTick = row.selectable && !row.folioClosed;
    const choice = state.ticked ? state.choice : null;
    const folioLink =
        row.folioId === null
            ? null
            : folioTab.url(
                  { reservation: reservationId },
                  { query: { folio: row.folioId } },
              );

    return (
        <TableRow className="align-top max-lg:mb-3 max-lg:flex max-lg:flex-wrap max-lg:items-center max-lg:gap-x-3 max-lg:rounded-lg max-lg:border">
            <TableCell className="w-8 align-top">
                <Checkbox
                    id={id}
                    checked={state.ticked}
                    disabled={!canTick}
                    onCheckedChange={(checked) =>
                        onChange({ ticked: checked === true })
                    }
                    aria-label={`Include ${row.label}`}
                    className="mt-0.5"
                />
            </TableCell>
            <TableCell className="w-24 align-top font-medium">
                <label htmlFor={id}>{row.label}</label>
            </TableCell>
            <TableCell className="w-48 align-top text-muted-foreground max-lg:flex-1">
                {row.guest ?? 'No primary guest'}
            </TableCell>
            <TableCell className="w-28 text-right align-top font-medium tabular-nums">
                <MoneyText amount={row.balance} currency={null} plain />
            </TableCell>
            <TableCell className="align-top text-sm whitespace-normal max-lg:basis-full lg:min-w-72">
                <div className="flex min-w-0 flex-col gap-2">
                    {statusLabel(row) && (
                        <p className="text-muted-foreground">
                            {statusLabel(row)}
                        </p>
                    )}
                    {row.note !== null && (
                        <p>
                            {row.note}
                            {row.folioClosed && folioLink !== null && (
                                <>
                                    {' '}
                                    {row.canReopen ? (
                                        <Link
                                            href={folioLink}
                                            className="underline underline-offset-4"
                                        >
                                            Open folio
                                        </Link>
                                    ) : (
                                        <span>A manager can reopen it.</span>
                                    )}
                                </>
                            )}
                        </p>
                    )}

                    {row.selectable && !row.folioClosed && (
                        <>
                            {row.choices.length === 0 && (
                                <p className="text-muted-foreground">
                                    {Number(row.balance) === 0
                                        ? row.status === 'checked_in'
                                            ? 'Nothing to settle'
                                            : 'Checked out · nothing to settle'
                                        : 'You may not settle this balance here.'}
                                </p>
                            )}
                            {row.choices.length > 0 && (
                                <RadioGroup
                                    value={state.choice ?? ''}
                                    onValueChange={(value) =>
                                        onChange({ choice: value })
                                    }
                                    disabled={!state.ticked}
                                >
                                    {row.choices.includes('collect') && (
                                        <Field orientation="horizontal">
                                            <RadioGroupItem
                                                id={`${id}-collect`}
                                                value="collect"
                                            />
                                            <FieldContent>
                                                <FieldLabel
                                                    htmlFor={`${id}-collect`}
                                                >
                                                    Collect
                                                    {onePaymentOn &&
                                                        ' · in the one payment'}
                                                </FieldLabel>
                                                {choice === 'collect' &&
                                                    !onePaymentOn && (
                                                        <div className="flex flex-wrap gap-2">
                                                            <div className="w-40">
                                                                <EnumSelect
                                                                    options={methods.map(
                                                                        (
                                                                            one,
                                                                        ) => ({
                                                                            value: String(
                                                                                one.id,
                                                                            ),
                                                                            label: one.name,
                                                                        }),
                                                                    )}
                                                                    value={
                                                                        state.methodId
                                                                    }
                                                                    onValueChange={(
                                                                        value,
                                                                    ) =>
                                                                        onChange(
                                                                            {
                                                                                methodId:
                                                                                    value,
                                                                            },
                                                                        )
                                                                    }
                                                                    aria-label={`Method for ${row.label}`}
                                                                />
                                                            </div>
                                                            <div className="w-40">
                                                                <MoneyInput
                                                                    value={
                                                                        state.amount
                                                                    }
                                                                    onChange={(
                                                                        value,
                                                                    ) =>
                                                                        onChange(
                                                                            {
                                                                                amount: value,
                                                                            },
                                                                        )
                                                                    }
                                                                    currency={
                                                                        currency
                                                                    }
                                                                />
                                                            </div>
                                                            {method?.requiresReference && (
                                                                <Input
                                                                    className="w-40"
                                                                    value={
                                                                        state.reference
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        onChange(
                                                                            {
                                                                                reference:
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                    maxLength={
                                                                        100
                                                                    }
                                                                    autoComplete="off"
                                                                    placeholder="Reference"
                                                                    aria-label={`Reference for ${row.label}`}
                                                                />
                                                            )}
                                                        </div>
                                                    )}
                                            </FieldContent>
                                        </Field>
                                    )}
                                    {row.choices.includes('move') && (
                                        <Field orientation="horizontal">
                                            <RadioGroupItem
                                                id={`${id}-move`}
                                                value="move"
                                            />
                                            <FieldContent>
                                                <FieldLabel
                                                    htmlFor={`${id}-move`}
                                                >
                                                    Add to group bill
                                                </FieldLabel>
                                                {row.moveNote && (
                                                    <span className="text-muted-foreground">
                                                        {row.moveNote}
                                                    </span>
                                                )}
                                            </FieldContent>
                                        </Field>
                                    )}
                                    {row.choices.includes('refund') &&
                                        canCorrectMoney && (
                                            <Field orientation="horizontal">
                                                <RadioGroupItem
                                                    id={`${id}-refund`}
                                                    value="refund"
                                                />
                                                <FieldContent>
                                                    <FieldLabel
                                                        htmlFor={`${id}-refund`}
                                                    >
                                                        Refund deposit
                                                    </FieldLabel>
                                                    {choice === 'refund' && (
                                                        <div className="w-40">
                                                            <MoneyInput
                                                                value={
                                                                    state.refundAmount
                                                                }
                                                                onChange={(
                                                                    value,
                                                                ) =>
                                                                    onChange({
                                                                        refundAmount:
                                                                            value,
                                                                    })
                                                                }
                                                                currency={
                                                                    currency
                                                                }
                                                            />
                                                        </div>
                                                    )}
                                                </FieldContent>
                                            </Field>
                                        )}
                                </RadioGroup>
                            )}
                        </>
                    )}
                    {error && <FieldError>{error}</FieldError>}
                </div>
            </TableCell>
        </TableRow>
    );
}
