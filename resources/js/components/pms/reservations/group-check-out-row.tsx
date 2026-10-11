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
import { cn } from '@/lib/utils';
import { folio as folioTab } from '@/routes/reservations';
import { move as moveCharges } from '@/routes/reservations/folio';

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

const headings: Record<string, string> = {
    out: 'Already checked out',
    not_arrived: 'Not arrived',
    cancelled: 'Cancelled',
};

/** The heading above the first room of a group other than the main one. */
export function groupHeading(group: string): string | null {
    return headings[group] ?? null;
}

/**
 * One room of the group check-out page: a row on a wide screen, a card on a narrow one. A room that cannot be touched
 * shows why; the others show the settle choices the server offered, prefilled by balance.
 */
export function GroupCheckOutRow({
    row,
    state,
    onChange,
    methods,
    currency,
    masterName,
    reservationId,
    onePaymentOn,
    error,
}: {
    row: Row;
    state: RowState;
    onChange: (patch: Partial<RowState>) => void;
    methods: Method[];
    currency: App.Enums.Currency | null;
    masterName: string | null;
    reservationId: number;
    onePaymentOn: boolean;
    error?: string;
}) {
    const id = `unit-${row.unitId}`;
    const method = methods.find((one) => String(one.id) === state.methodId);
    const canTick = row.selectable && !row.folioClosed;
    const choice = state.ticked ? state.choice : null;
    const moveLink =
        row.incoming.length > 0
            ? moveCharges.url({ reservation: reservationId })
            : null;
    const folioLink =
        row.folioId === null
            ? null
            : folioTab.url(
                  { reservation: reservationId },
                  { query: { folio: row.folioId } },
              );

    return (
        <div
            className={cn(
                'flex flex-col gap-3 py-3 max-lg:rounded-lg max-lg:border max-lg:p-3 lg:grid lg:grid-cols-[minmax(0,14rem)_7rem_minmax(0,1fr)] lg:items-start lg:gap-4 lg:border-b',
                !row.selectable && 'text-muted-foreground',
            )}
        >
            <div className="flex items-start gap-3">
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
                <div className="flex min-w-0 flex-col">
                    <label htmlFor={id} className="font-medium">
                        {row.label}
                    </label>
                    <span className="truncate text-sm text-muted-foreground">
                        {row.guest ?? 'No primary guest'}
                    </span>
                </div>
            </div>

            <div className="flex items-baseline justify-between gap-2 lg:block lg:text-right">
                <span className="text-sm text-muted-foreground lg:hidden">
                    Balance
                </span>
                {row.selectable && (
                    <span className="font-medium">
                        <MoneyText amount={row.balance} currency={null} plain />
                    </span>
                )}
            </div>

            <div className="flex min-w-0 flex-col gap-2 text-sm">
                {row.note !== null && (
                    <p>
                        {row.note}{' '}
                        {moveLink !== null && (
                            <Link
                                href={moveLink}
                                className="underline underline-offset-4"
                            >
                                Move charges
                            </Link>
                        )}
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
                                                                    (one) => ({
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
                                                                    onChange({
                                                                        methodId:
                                                                            value,
                                                                    })
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
                                                                    onChange({
                                                                        amount: value,
                                                                    })
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
                                                                    onChange({
                                                                        reference:
                                                                            event
                                                                                .target
                                                                                .value,
                                                                    })
                                                                }
                                                                maxLength={100}
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
                                            <FieldLabel htmlFor={`${id}-move`}>
                                                Add to Master
                                                {masterName
                                                    ? ` · ${masterName}`
                                                    : ''}
                                            </FieldLabel>
                                            {row.moveNote && (
                                                <span className="text-muted-foreground">
                                                    {row.moveNote}
                                                </span>
                                            )}
                                        </FieldContent>
                                    </Field>
                                )}
                                {row.choices.includes('refund') && (
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
                                                        onChange={(value) =>
                                                            onChange({
                                                                refundAmount:
                                                                    value,
                                                            })
                                                        }
                                                        currency={currency}
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
        </div>
    );
}
