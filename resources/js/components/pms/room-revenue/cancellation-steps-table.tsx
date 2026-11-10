import { Plus, Trash2 } from 'lucide-react';
import { MoneyInput } from '@/components/pms/money-input';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

export type CancellationStepRow = {
    days_before: string;
    amount: string;
    percent: string;
};

export const emptyStep: CancellationStepRow = {
    days_before: '',
    amount: '',
    percent: '',
};

/** Most days first; a row with no days yet stays at the end. */
export function sortSteps(rows: CancellationStepRow[]): CancellationStepRow[] {
    const days = (row: CancellationStepRow) =>
        row.days_before === '' ? -1 : Number(row.days_before);

    return [...rows].sort((first, second) => days(second) - days(first));
}

function dayWord(days: string): string {
    return days === '1' ? 'day' : 'days';
}

/**
 * The charge steps of a paid cancellation: "N or more days before arrival → charge". The last step also covers a
 * cancellation closer to arrival, so a read-only row repeats it. The server checks the steps and works out the charge.
 */
export function CancellationStepsTable({
    rows,
    mode,
    currency,
    errors,
    onChange,
}: {
    rows: CancellationStepRow[];
    mode: 'fixed_amount' | 'percentage';
    currency: App.Data.Property.CurrencyData;
    errors: Record<string, string | undefined>;
    onChange: (rows: CancellationStepRow[]) => void;
}) {
    const isFixed = mode === 'fixed_amount';
    const last = rows[rows.length - 1];
    const showBelowLast =
        last !== undefined &&
        last.days_before !== '' &&
        Number(last.days_before) > 0;
    const lastCharge = last
        ? isFixed
            ? last.amount !== ''
                ? `${currency.code} ${last.amount}`
                : '—'
            : last.percent !== ''
              ? `${last.percent} %`
              : '—'
        : '—';

    const setRow = (index: number, values: Partial<CancellationStepRow>) => {
        onChange(
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );
    };

    const removeRow = (index: number) => {
        onChange(rows.filter((_, position) => position !== index));
    };

    const sort = () => {
        const sorted = sortSteps(rows);

        if (sorted.some((row, index) => row !== rows[index])) {
            onChange(sorted);
        }
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Cancelled</TableHead>
                            <TableHead>
                                {isFixed ? 'Amount' : 'Charge'}
                            </TableHead>
                            <TableHead>
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row, index) => {
                            const daysError =
                                errors[`steps.${index}.days_before`];
                            const chargeError =
                                errors[
                                    `steps.${index}.${isFixed ? 'amount' : 'percent'}`
                                ];

                            return (
                                <TableRow key={index}>
                                    <TableCell className="align-top">
                                        <div className="flex items-center gap-2">
                                            <Input
                                                aria-label={`Days before arrival of step ${index + 1}`}
                                                placeholder="e.g. 30"
                                                type="number"
                                                min={0}
                                                max={365}
                                                step={1}
                                                inputMode="numeric"
                                                className="w-20 tabular-nums"
                                                value={row.days_before}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        days_before:
                                                            event.target.value,
                                                    })
                                                }
                                                onBlur={sort}
                                                aria-invalid={!!daysError}
                                            />
                                            <span className="text-sm whitespace-nowrap text-muted-foreground">
                                                {dayWord(row.days_before)} or
                                                more before
                                            </span>
                                        </div>
                                        <FieldError>{daysError}</FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <div className="w-36">
                                            {isFixed ? (
                                                <MoneyInput
                                                    aria-label={`Amount of step ${index + 1}`}
                                                    placeholder="e.g. 100.00"
                                                    value={row.amount}
                                                    onChange={(value) =>
                                                        setRow(index, {
                                                            amount: value,
                                                        })
                                                    }
                                                    currency={currency}
                                                    invalid={!!chargeError}
                                                />
                                            ) : (
                                                <InputGroup>
                                                    <InputGroupInput
                                                        aria-label={`Percent of step ${index + 1}`}
                                                        placeholder="e.g. 50"
                                                        inputMode="decimal"
                                                        autoComplete="off"
                                                        className="tabular-nums"
                                                        value={row.percent}
                                                        onChange={(event) =>
                                                            setRow(index, {
                                                                percent:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                        aria-invalid={
                                                            !!chargeError
                                                        }
                                                    />
                                                    <InputGroupAddon align="inline-end">
                                                        <InputGroupText>
                                                            %
                                                        </InputGroupText>
                                                    </InputGroupAddon>
                                                </InputGroup>
                                            )}
                                        </div>
                                        <FieldError>{chargeError}</FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <div className="flex justify-end">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label={`Remove step ${index + 1}`}
                                                disabled={rows.length === 1}
                                                focusableWhenDisabled
                                                onClick={() => removeRow(index)}
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                        {showBelowLast && (
                            <TableRow>
                                <TableCell className="text-muted-foreground">
                                    Less than {last.days_before}{' '}
                                    {dayWord(last.days_before)}
                                </TableCell>
                                <TableCell className="text-muted-foreground tabular-nums">
                                    {lastCharge}
                                </TableCell>
                                <TableCell />
                            </TableRow>
                        )}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={3}>
                                <div className="flex justify-end">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            onChange([...rows, emptyStep])
                                        }
                                    >
                                        <Plus data-icon="inline-start" />
                                        Add step
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </div>
            <p className="text-sm text-muted-foreground">
                A step with {isFixed ? '0.00' : '0 %'} is free. The guest pays
                the step that matches the day they cancel.
            </p>
            <FieldError>{errors.steps}</FieldError>
        </div>
    );
}
