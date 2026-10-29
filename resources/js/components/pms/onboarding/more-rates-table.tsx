import { Plus, Trash2 } from 'lucide-react';
import { Fragment, useRef, useState } from 'react';
import { MoneyInput } from '@/components/pms/money-input';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

type Option = { value: string; label: string };

/** A room type row of the Basic rate table, as far as More rates needs it. */
export type BasicLine = {
    room_type_id: number;
    name: string;
    price_by: string;
};

export type PriceLine = {
    room_type_id: number;
    name: string;
    price_by: string;
    weekday: string;
    weekend: string;
};

export type MoreRate = {
    key: string;
    plan_ids: number[];
    name: string;
    mode: 'follows' | 'own';
    parent_basis: string;
    direction: string;
    unit: string;
    amount: string;
    room_type_ids: number[];
    prices: PriceLine[];
};

type Example = {
    key: string;
    parentAmount: string | null;
    result: string | null;
};

const MAX_ROWS = 20;
const addonTrigger =
    'h-6 w-auto border-0 bg-transparent px-1.5 shadow-none focus-visible:ring-0 dark:bg-transparent dark:hover:bg-transparent';

/**
 * The optional More rates table of the Basic rate step: rates that follow a Basic Rate plan (more or less, by an amount or
 * a percent) or have prices of their own. It holds no state: the page owns the rows and sends them with the Basic rate table.
 */
export function MoreRatesTable({
    rates,
    onChange,
    basicLines,
    ready,
    sameEveryNight,
    weekendDays,
    priceByOptions,
    currency,
    examples,
    errors,
}: {
    rates: MoreRate[];
    onChange: (rates: MoreRate[]) => void;
    basicLines: BasicLine[];
    ready: boolean;
    sameEveryNight: boolean;
    weekendDays: string;
    priceByOptions: Option[];
    currency: App.Data.Property.CurrencyData | null;
    examples: Example[];
    errors: Record<string, string | undefined>;
}) {
    const counter = useRef(0);
    const [pendingOwn, setPendingOwn] = useState<{
        index: number;
        value: string;
    } | null>(null);
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency?.code ?? 'MYR',
    });
    const bases = ['per_room', 'per_guest'].filter((basis) =>
        basicLines.some((line) => line.price_by === basis),
    );
    const basisLabel = (basis: string) =>
        basis === 'per_guest' ? 'Guest' : 'Room';
    const parentOptions: Option[] = [
        ...bases.map((basis) => ({
            value: `follows:${basis}`,
            label:
                bases.length > 1
                    ? `Follows Basic Rate / ${basisLabel(basis)}`
                    : 'Follows Basic Rate',
        })),
        { value: 'own', label: 'Own prices' },
    ];
    const changeOptions: Option[] = [
        { value: 'up', label: 'More' },
        { value: 'down', label: 'Less' },
    ];
    const unitOptions: Option[] = [
        { value: 'amount', label: currency?.code ?? 'MYR' },
        { value: 'percent', label: '%' },
    ];

    const eligible = (basis: string) =>
        basicLines.filter((line) => line.price_by === basis);

    const setRate = (index: number, values: Partial<MoreRate>) =>
        onChange(
            rates.map((rate, position) =>
                position === index ? { ...rate, ...values } : rate,
            ),
        );

    const addRate = () => {
        const basis = bases[0] ?? 'per_room';

        onChange([
            ...rates,
            {
                key: `new-${++counter.current}-${Date.now()}`,
                plan_ids: [],
                name: '',
                mode: 'follows',
                parent_basis: basis,
                direction: 'up',
                unit: 'amount',
                amount: '',
                room_type_ids: eligible(basis).map((line) => line.room_type_id),
                prices: [],
            },
        ]);
    };

    const priceLine = (line: BasicLine): PriceLine => ({
        room_type_id: line.room_type_id,
        name: line.name,
        price_by: line.price_by,
        weekday: '',
        weekend: '',
    });

    const chooseKind = (index: number, value: string, confirmed = false) => {
        const rate = rates[index];

        if (value === 'own') {
            setRate(index, {
                mode: 'own',
                prices: basicLines
                    .filter((line) =>
                        rate.room_type_ids.includes(line.room_type_id),
                    )
                    .map(priceLine),
            });

            return;
        }

        const basis = value.replace('follows:', '');

        if (rate.mode === 'own' && rate.plan_ids.length > 0 && !confirmed) {
            setPendingOwn({ index, value });

            return;
        }

        const ids =
            rate.mode === 'own'
                ? rate.prices
                      .map((line) => line.room_type_id)
                      .filter((id) =>
                          eligible(basis).some(
                              (line) => line.room_type_id === id,
                          ),
                      )
                : rate.room_type_ids.filter((id) =>
                      eligible(basis).some((line) => line.room_type_id === id),
                  );

        setRate(index, {
            mode: 'follows',
            parent_basis: basis,
            room_type_ids: ids.length
                ? ids
                : eligible(basis).map((line) => line.room_type_id),
            prices: [],
        });
    };

    const chooseRoomTypes = (index: number, values: string[]) => {
        const ids = values.map(Number);
        const rate = rates[index];

        if (rate.mode === 'follows') {
            setRate(index, { room_type_ids: ids });

            return;
        }

        setRate(index, {
            prices: ids.map(
                (id) =>
                    rate.prices.find((line) => line.room_type_id === id) ??
                    priceLine(
                        basicLines.find((line) => line.room_type_id === id)!,
                    ),
            ),
        });
    };

    const setLine = (
        index: number,
        lineIndex: number,
        values: Partial<PriceLine>,
    ) =>
        setRate(index, {
            prices: rates[index].prices.map((line, position) =>
                position === lineIndex ? { ...line, ...values } : line,
            ),
        });

    const example = (key: string) => examples.find((item) => item.key === key);

    return (
        <div className="flex flex-col gap-3">
            <div>
                <h2 className="text-base font-medium">More rates</h2>
                <p className="text-sm text-muted-foreground">
                    Optional. Sell other prices, like a breakfast or
                    non-refundable rate.
                </p>
            </div>
            <div className="overflow-hidden rounded-lg border">
                <Table>
                    <TableHeader className="max-md:hidden">
                        <TableRow>
                            <TableHead className="md:w-1/4">Name</TableHead>
                            <TableHead className="md:w-[30%]">Price</TableHead>
                            <TableHead>Room types</TableHead>
                            <TableHead>
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rates.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={4}
                                    className="text-center text-sm text-muted-foreground"
                                >
                                    Sell other prices, like a breakfast or
                                    non-refundable rate.
                                </TableCell>
                            </TableRow>
                        )}
                        {rates.map((rate, index) => {
                            const fieldError = (name: string) =>
                                errors[`more_rates.${index}.${name}`];
                            const shown = example(rate.key);
                            const roomTypeItems =
                                rate.mode === 'follows'
                                    ? eligible(rate.parent_basis)
                                    : basicLines;
                            const selected =
                                rate.mode === 'follows'
                                    ? rate.room_type_ids
                                    : rate.prices.map(
                                          (line) => line.room_type_id,
                                      );

                            const showPrices =
                                rate.mode === 'own' && rate.prices.length > 0;

                            return (
                                <Fragment key={rate.key}>
                                    <TableRow
                                        className={cn(
                                            'max-md:grid max-md:grid-cols-1 max-md:gap-3 max-md:p-3',
                                            showPrices && 'border-b-0',
                                        )}
                                    >
                                        <TableCell className="align-top max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                Name
                                            </span>
                                            <Input
                                                aria-label={`Name of rate ${index + 1}`}
                                                placeholder="e.g. Room with breakfast"
                                                maxLength={100}
                                                value={rate.name}
                                                disabled={!ready}
                                                aria-invalid={
                                                    !!fieldError('name')
                                                }
                                                onChange={(event) =>
                                                    setRate(index, {
                                                        name: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            <FieldError>
                                                {fieldError('name')}
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                Price
                                            </span>
                                            <Select
                                                items={parentOptions}
                                                value={
                                                    rate.mode === 'own'
                                                        ? 'own'
                                                        : `follows:${rate.parent_basis}`
                                                }
                                                disabled={!ready}
                                                onValueChange={(value) =>
                                                    chooseKind(
                                                        index,
                                                        value as string,
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    aria-label={`Price of rate ${index + 1}`}
                                                    className="w-full"
                                                    aria-invalid={
                                                        !!fieldError(
                                                            'parent_basis',
                                                        )
                                                    }
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectGroup>
                                                        {parentOptions.map(
                                                            (option) => (
                                                                <SelectItem
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.value
                                                                    }
                                                                >
                                                                    {
                                                                        option.label
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectGroup>
                                                </SelectContent>
                                            </Select>
                                            <FieldError>
                                                {fieldError('parent_basis')}
                                            </FieldError>
                                            {rate.mode === 'follows' && (
                                                <div className="mt-2 flex flex-col gap-1">
                                                    <InputGroup>
                                                        <InputGroupAddon align="inline-start">
                                                            <Select
                                                                items={
                                                                    changeOptions
                                                                }
                                                                value={
                                                                    rate.direction
                                                                }
                                                                disabled={
                                                                    !ready
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    setRate(
                                                                        index,
                                                                        {
                                                                            direction:
                                                                                value as string,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                <SelectTrigger
                                                                    aria-label={`More or less for rate ${index + 1}`}
                                                                    className={
                                                                        addonTrigger
                                                                    }
                                                                >
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectGroup>
                                                                        {changeOptions.map(
                                                                            (
                                                                                option,
                                                                            ) => (
                                                                                <SelectItem
                                                                                    key={
                                                                                        option.value
                                                                                    }
                                                                                    value={
                                                                                        option.value
                                                                                    }
                                                                                >
                                                                                    {
                                                                                        option.label
                                                                                    }
                                                                                </SelectItem>
                                                                            ),
                                                                        )}
                                                                    </SelectGroup>
                                                                </SelectContent>
                                                            </Select>
                                                        </InputGroupAddon>
                                                        <InputGroupInput
                                                            aria-label={`Amount for rate ${index + 1}`}
                                                            inputMode="decimal"
                                                            autoComplete="off"
                                                            placeholder={
                                                                rate.unit ===
                                                                'percent'
                                                                    ? 'e.g. 10'
                                                                    : 'e.g. 30.00'
                                                            }
                                                            className="tabular-nums"
                                                            value={rate.amount}
                                                            disabled={!ready}
                                                            aria-invalid={
                                                                !!fieldError(
                                                                    'amount',
                                                                )
                                                            }
                                                            onChange={(event) =>
                                                                setRate(index, {
                                                                    amount: event
                                                                        .target
                                                                        .value,
                                                                })
                                                            }
                                                        />
                                                        <InputGroupAddon align="inline-end">
                                                            <Select
                                                                items={
                                                                    unitOptions
                                                                }
                                                                value={
                                                                    rate.unit
                                                                }
                                                                disabled={
                                                                    !ready
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    setRate(
                                                                        index,
                                                                        {
                                                                            unit: value as string,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                <SelectTrigger
                                                                    aria-label={`Unit for rate ${index + 1}`}
                                                                    className={
                                                                        addonTrigger
                                                                    }
                                                                >
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectGroup>
                                                                        {unitOptions.map(
                                                                            (
                                                                                option,
                                                                            ) => (
                                                                                <SelectItem
                                                                                    key={
                                                                                        option.value
                                                                                    }
                                                                                    value={
                                                                                        option.value
                                                                                    }
                                                                                >
                                                                                    {
                                                                                        option.label
                                                                                    }
                                                                                </SelectItem>
                                                                            ),
                                                                        )}
                                                                    </SelectGroup>
                                                                </SelectContent>
                                                            </Select>
                                                        </InputGroupAddon>
                                                    </InputGroup>
                                                    <FieldError>
                                                        {fieldError('amount')}
                                                    </FieldError>
                                                    {rate.parent_basis ===
                                                        'per_guest' && (
                                                        <p className="text-xs text-muted-foreground">
                                                            The change is per
                                                            guest.
                                                        </p>
                                                    )}
                                                    {shown?.result &&
                                                        shown.parentAmount && (
                                                            <p className="text-xs text-muted-foreground">
                                                                Example:{' '}
                                                                {money.format(
                                                                    Number(
                                                                        shown.parentAmount,
                                                                    ),
                                                                )}{' '}
                                                                becomes{' '}
                                                                {money.format(
                                                                    Number(
                                                                        shown.result,
                                                                    ),
                                                                )}
                                                            </p>
                                                        )}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="align-top max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                Room types
                                            </span>
                                            <MultiCombobox
                                                id={`more-rate-room-types-${rate.key}`}
                                                name={`more_rates[${index}][room_types]`}
                                                items={roomTypeItems.map(
                                                    (line) => ({
                                                        value: String(
                                                            line.room_type_id,
                                                        ),
                                                        label: line.name,
                                                    }),
                                                )}
                                                value={selected.map(String)}
                                                onValueChange={(values) =>
                                                    chooseRoomTypes(
                                                        index,
                                                        values,
                                                    )
                                                }
                                                placeholder="Pick room types"
                                                emptyLabel="No matching room types."
                                                disabled={!ready}
                                                maxChips={2}
                                                invalid={
                                                    !!fieldError(
                                                        'room_type_ids',
                                                    ) || !!fieldError('prices')
                                                }
                                            />
                                            <FieldError>
                                                {fieldError('room_type_ids') ??
                                                    fieldError('prices')}
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top max-md:p-0">
                                            <div className="flex justify-end">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Remove ${rate.name || `rate ${index + 1}`}`}
                                                    onClick={() =>
                                                        onChange(
                                                            rates.filter(
                                                                (_, position) =>
                                                                    position !==
                                                                    index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                    {showPrices && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="bg-muted/30 max-md:p-3"
                                            >
                                                <div className="flex flex-col gap-3">
                                                    {rate.prices.map(
                                                        (line, lineIndex) => (
                                                            <div
                                                                key={
                                                                    line.room_type_id
                                                                }
                                                                className="grid gap-3 md:grid-cols-[1fr_8rem_1fr_1fr] md:items-start"
                                                            >
                                                                <p className="pt-2 text-sm font-medium">
                                                                    {line.name}
                                                                </p>
                                                                <Select
                                                                    items={
                                                                        priceByOptions
                                                                    }
                                                                    value={
                                                                        line.price_by
                                                                    }
                                                                    onValueChange={(
                                                                        value,
                                                                    ) =>
                                                                        setLine(
                                                                            index,
                                                                            lineIndex,
                                                                            {
                                                                                price_by:
                                                                                    value as string,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    <SelectTrigger
                                                                        aria-label={`Price by for ${line.name} in ${rate.name || `rate ${index + 1}`}`}
                                                                        className="w-full"
                                                                    >
                                                                        <SelectValue />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        <SelectGroup>
                                                                            {priceByOptions.map(
                                                                                (
                                                                                    option,
                                                                                ) => (
                                                                                    <SelectItem
                                                                                        key={
                                                                                            option.value
                                                                                        }
                                                                                        value={
                                                                                            option.value
                                                                                        }
                                                                                    >
                                                                                        {
                                                                                            option.label
                                                                                        }
                                                                                    </SelectItem>
                                                                                ),
                                                                            )}
                                                                        </SelectGroup>
                                                                    </SelectContent>
                                                                </Select>
                                                                <div>
                                                                    <MoneyInput
                                                                        currency={
                                                                            currency
                                                                        }
                                                                        placeholder={
                                                                            line.price_by ===
                                                                            'per_guest'
                                                                                ? 'e.g. 70.00'
                                                                                : 'e.g. 150.00'
                                                                        }
                                                                        value={
                                                                            line.weekday
                                                                        }
                                                                        invalid={
                                                                            !!fieldError(
                                                                                `prices.${lineIndex}.weekday`,
                                                                            )
                                                                        }
                                                                        onChange={(
                                                                            value,
                                                                        ) =>
                                                                            setLine(
                                                                                index,
                                                                                lineIndex,
                                                                                {
                                                                                    weekday:
                                                                                        value,
                                                                                },
                                                                            )
                                                                        }
                                                                    />
                                                                    <FieldError>
                                                                        {fieldError(
                                                                            `prices.${lineIndex}.weekday`,
                                                                        )}
                                                                    </FieldError>
                                                                </div>
                                                                {!sameEveryNight && (
                                                                    <div>
                                                                        <MoneyInput
                                                                            currency={
                                                                                currency
                                                                            }
                                                                            placeholder={
                                                                                line.price_by ===
                                                                                'per_guest'
                                                                                    ? 'e.g. 80.00'
                                                                                    : 'e.g. 170.00'
                                                                            }
                                                                            value={
                                                                                line.weekend
                                                                            }
                                                                            invalid={
                                                                                !!fieldError(
                                                                                    `prices.${lineIndex}.weekend`,
                                                                                )
                                                                            }
                                                                            onChange={(
                                                                                value,
                                                                            ) =>
                                                                                setLine(
                                                                                    index,
                                                                                    lineIndex,
                                                                                    {
                                                                                        weekend:
                                                                                            value,
                                                                                    },
                                                                                )
                                                                            }
                                                                        />
                                                                        <FieldError>
                                                                            {fieldError(
                                                                                `prices.${lineIndex}.weekend`,
                                                                            )}
                                                                        </FieldError>
                                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                                            Weekend
                                                                            (
                                                                            {
                                                                                weekendDays
                                                                            }
                                                                            )
                                                                        </p>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </Fragment>
                            );
                        })}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={4}>
                                <div className="flex items-center justify-end gap-3">
                                    {!ready && (
                                        <p className="text-sm text-muted-foreground">
                                            Fill in the Basic rate prices first.
                                        </p>
                                    )}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={
                                            !ready || rates.length >= MAX_ROWS
                                        }
                                        focusableWhenDisabled
                                        onClick={addRate}
                                    >
                                        <Plus data-icon="inline-start" />
                                        Add rate
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </div>
            <p className="text-sm text-muted-foreground">
                After you go live, you can include meals in Settings, under Rate
                plans.
            </p>

            <AlertDialog
                open={pendingOwn !== null}
                onOpenChange={(open) => !open && setPendingOwn(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Switch{' '}
                            {pendingOwn !== null
                                ? rates[pendingOwn.index].name || 'this rate'
                                : ''}{' '}
                            to follow a Basic Rate?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Switching to Follows clears its own prices.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => {
                                if (pendingOwn !== null) {
                                    chooseKind(
                                        pendingOwn.index,
                                        pendingOwn.value,
                                        true,
                                    );
                                }

                                setPendingOwn(null);
                            }}
                        >
                            Clear prices and switch
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
