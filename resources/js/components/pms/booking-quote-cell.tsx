import { Info, Pencil } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
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
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Quote = App.Data.Reservations.BookingQuoteData;

/**
 * The rate of one line: the server's total, the night breakdown behind the info button, and the price override behind
 * the pencil. The override needs a reason, and the server logs it.
 */
export function BookingQuoteCell({
    quote,
    loading,
    currency,
    overrideTotal,
    overrideReason,
    onOverrideChange,
    errors,
    index,
    canOverride = true,
    goLiveDate = null,
}: {
    quote: Quote | null;
    loading: boolean;
    currency: App.Data.Property.CurrencyData | null;
    overrideTotal: string;
    overrideReason: string;
    onOverrideChange: (patch: {
        override_total?: string;
        override_reason?: string;
    }) => void;
    errors: Record<string, string | undefined>;
    index: number;
    /** Company portal lines never offer a price override. */
    canOverride?: boolean;
    /** Migrated stay only: a night before this date shows "before go-live" and no price. */
    goLiveDate?: string | null;
}) {
    const overrideInvalid =
        !!errors[`lines.${index}.override_total`] ||
        !!errors[`lines.${index}.override_reason`];

    return (
        <div className="flex items-center gap-1">
            <span className="min-w-24 text-right font-medium">
                {loading ? (
                    <Spinner className="ml-auto" />
                ) : quote?.lineTotal ? (
                    <MoneyText amount={quote.lineTotal} currency={currency} />
                ) : (
                    <span className="text-sm font-normal text-muted-foreground">
                        No price yet
                    </span>
                )}
            </span>
            <Popover>
                <PopoverTrigger
                    render={
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Show the price for each night"
                            disabled={!quote || quote.nights.length === 0}
                        />
                    }
                >
                    <Info />
                </PopoverTrigger>
                <PopoverContent className="w-96" align="end">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Night</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {quote?.nights.map((night) => (
                                <TableRow key={night.date}>
                                    <TableCell>
                                        <DateText date={night.date} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {goLiveDate !== null &&
                                        night.date < goLiveDate ? (
                                            <span className="text-muted-foreground">
                                                before go-live
                                            </span>
                                        ) : (
                                            <MoneyText
                                                amount={night.amount}
                                                currency={currency}
                                            />
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    {quote?.isOverridden && (
                        <p className="text-xs text-muted-foreground">
                            The price is overridden, so the nights above are the
                            rate, not the charge.
                        </p>
                    )}
                </PopoverContent>
            </Popover>
            {canOverride && (
                <Popover>
                    <PopoverTrigger
                        render={
                            <Button
                                type="button"
                                variant={
                                    overrideTotal !== '' || overrideInvalid
                                        ? 'secondary'
                                        : 'ghost'
                                }
                                size="icon-sm"
                                aria-label="Override the price"
                            />
                        }
                    >
                        <Pencil />
                    </PopoverTrigger>
                    <PopoverContent className="w-80" align="end">
                        <FieldGroup>
                            <Field
                                data-invalid={
                                    !!errors[`lines.${index}.override_total`]
                                }
                            >
                                <FieldLabel htmlFor={`override-total-${index}`}>
                                    Stay total of one unit
                                </FieldLabel>
                                <MoneyInput
                                    id={`override-total-${index}`}
                                    value={overrideTotal}
                                    onChange={(value) =>
                                        onOverrideChange({
                                            override_total: value,
                                        })
                                    }
                                    currency={currency}
                                    invalid={
                                        !!errors[
                                            `lines.${index}.override_total`
                                        ]
                                    }
                                />
                                <FieldError>
                                    {errors[`lines.${index}.override_total`]}
                                </FieldError>
                            </Field>
                            <Field
                                data-invalid={
                                    !!errors[`lines.${index}.override_reason`]
                                }
                            >
                                <FieldLabel
                                    htmlFor={`override-reason-${index}`}
                                >
                                    Reason (optional)
                                </FieldLabel>
                                <Input
                                    id={`override-reason-${index}`}
                                    value={overrideReason}
                                    onChange={(event) =>
                                        onOverrideChange({
                                            override_reason: event.target.value,
                                        })
                                    }
                                    maxLength={500}
                                    placeholder="e.g. Corporate rate"
                                    aria-invalid={
                                        !!errors[
                                            `lines.${index}.override_reason`
                                        ]
                                    }
                                />
                                <FieldError>
                                    {errors[`lines.${index}.override_reason`]}
                                </FieldError>
                            </Field>
                            {overrideTotal !== '' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        onOverrideChange({
                                            override_total: '',
                                            override_reason: '',
                                        })
                                    }
                                >
                                    Reset
                                </Button>
                            )}
                        </FieldGroup>
                    </PopoverContent>
                </Popover>
            )}
        </div>
    );
}
