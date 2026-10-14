import { useHttp } from '@inertiajs/react';
import { CalendarDays, CalendarRange, Moon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { preview as previewRoute } from '@/routes/settings/rate-plans/discounts';

type Preview = App.Data.Rates.StayDiscountPreviewData;
type PreviewRow = App.Data.Rates.StayDiscountPreviewRowData;
type AgeGroup = App.Data.Property.PropertyAgeGroupData;
type Rule = {
    min_nights: string;
    discount_type: string;
    value: string;
    from_night: string;
};
type View = 'night' | 'length';
type SampleStay = {
    arrival: string;
    nights: number | null;
    adults: number | null;
    children: { age_group_id: number; count: number }[];
    discounts: Rule[];
};

/** Parse `2026-09-12` as a local date so it never shifts a day. */
function toDate(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function toValue(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

function StartDatePicker({
    id,
    value,
    onChange,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const date = toDate(value);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                render={
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        className="w-full justify-start font-normal"
                    />
                }
            >
                <CalendarDays data-icon="inline-start" />
                <span className="tabular-nums">
                    {date.toLocaleDateString('en-GB', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric',
                    })}
                </span>
            </PopoverTrigger>
            <PopoverContent className="w-auto" align="start">
                <Calendar
                    mode="single"
                    selected={date}
                    defaultMonth={date}
                    required
                    onSelect={(next) => {
                        onChange(toValue(next));
                        setOpen(false);
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}

/** `['475.00', '375.00', '375.00']` becomes `475.00 + 2 × 375.00`. Display only: the server sends the amounts. */
function nightsLine(amounts: string[], number: Intl.NumberFormat): string {
    const runs: { amount: string; count: number }[] = [];

    for (const amount of amounts) {
        const last = runs.at(-1);

        if (last && last.amount === amount) {
            last.count++;
        } else {
            runs.push({ amount, count: 1 });
        }
    }

    return runs
        .map(({ amount, count }) =>
            count > 1
                ? `${count} × ${number.format(Number(amount))}`
                : number.format(Number(amount)),
        )
        .join(' + ');
}

/** The longest By night stay, the same as `StayDiscountPreview::MAX_NIGHTS`. */
const maxNights = 14;

function PriceCell({
    amount,
    discount,
    detail,
    className,
    money,
    number,
}: {
    amount: string;
    discount: string;
    detail?: string;
    className?: string;
    money: Intl.NumberFormat;
    number: Intl.NumberFormat;
}) {
    return (
        <TableCell
            className={cn('text-right align-top tabular-nums', className)}
        >
            <div className="font-medium whitespace-nowrap">
                {money.format(Number(amount))}
                {Number(discount) > 0 && (
                    <span className="font-normal text-muted-foreground">
                        {' '}
                        (−{number.format(Number(discount))})
                    </span>
                )}
            </div>
            {detail && (
                <div className="text-xs text-muted-foreground">{detail}</div>
            )}
        </TableCell>
    );
}

function partyLabel(row: PreviewRow): string {
    const adults = `${row.adults} ${row.adults === 1 ? 'adult' : 'adults'}`;

    if (row.children === 0) {
        return adults;
    }

    return `${adults} · ${row.children} ${row.children === 1 ? 'child' : 'children'}`;
}

/**
 * Sample stay on the left, the price of every linked room type on the right. The server prices the unsaved rules.
 */
export function DiscountPreview({
    ratePlanId,
    rules,
    preview: initialPreview,
    ageGroups,
    currency,
}: {
    ratePlanId: number;
    rules: Rule[];
    preview: Preview;
    ageGroups: AgeGroup[];
    currency: App.Data.Property.CurrencyData;
}) {
    const [arrival, setArrival] = useState(initialPreview.arrival);
    const [custom, setCustom] = useState(false);
    const [adults, setAdults] = useState('2');
    const [children, setChildren] = useState<Record<number, string>>({});
    const [preview, setPreview] = useState(initialPreview);
    const [view, setView] = useState<View>('night');
    const [nights, setNights] = useState<string | null>(null);
    const payload: SampleStay = {
        arrival,
        nights:
            nights === null || nights === ''
                ? null
                : Math.min(maxNights, Math.max(1, Number(nights) || 1)),
        adults: custom ? Math.max(1, Number(adults) || 1) : null,
        children: custom
            ? ageGroups
                  .map((group) => ({
                      age_group_id: group.id,
                      count: Math.max(0, Number(children[group.id]) || 0),
                  }))
                  .filter((child) => child.count > 0)
            : [],
        discounts: rules,
    };
    const http = useHttp<SampleStay, Preview>(payload);
    const key = JSON.stringify(payload);
    const previewedKey = useRef(key);
    const payloadRef = useRef(payload);
    payloadRef.current = payload;
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.code,
    });
    const number = new Intl.NumberFormat(undefined, {
        minimumFractionDigits: currency.decimals,
        maximumFractionDigits: currency.decimals,
    });
    const columnCount =
        view === 'night' ? preview.nights + 1 : preview.stayLengths.length;

    useEffect(() => {
        if (previewedKey.current === key) {
            return;
        }

        const timer = window.setTimeout(() => {
            previewedKey.current = key;
            http.transform(() => payloadRef.current);
            http.post(previewRoute.url({ ratePlan: ratePlanId }), {
                onSuccess: (response) => setPreview(response),
            }).catch(() => undefined);
        }, 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    return (
        <div className="grid items-start gap-8 lg:grid-cols-3">
            <FieldSet className="min-w-0">
                <FieldLegend variant="label">Sample stay</FieldLegend>
                <FieldGroup className="gap-4">
                    <Field>
                        <FieldLabel htmlFor="preview-arrival">
                            Start date
                        </FieldLabel>
                        <StartDatePicker
                            id="preview-arrival"
                            value={arrival}
                            onChange={setArrival}
                        />
                    </Field>
                    {view === 'night' && (
                        <Field orientation="horizontal">
                            <FieldLabel
                                htmlFor="preview-nights"
                                className="flex-1"
                            >
                                Nights
                            </FieldLabel>
                            <Input
                                id="preview-nights"
                                className="w-20"
                                type="number"
                                min="1"
                                max={maxNights}
                                inputMode="numeric"
                                value={nights ?? String(preview.nights)}
                                onChange={(event) =>
                                    setNights(event.target.value)
                                }
                            />
                        </Field>
                    )}
                    <FieldSet>
                        <FieldLegend variant="label">Guests</FieldLegend>
                        <RadioGroup
                            value={custom ? 'custom' : 'included'}
                            onValueChange={(value) =>
                                setCustom(value === 'custom')
                            }
                        >
                            <Field orientation="horizontal">
                                <RadioGroupItem
                                    id="preview-guests-included"
                                    value="included"
                                />
                                <FieldContent>
                                    <FieldLabel htmlFor="preview-guests-included">
                                        Guests included
                                    </FieldLabel>
                                    <FieldDescription>
                                        Each room type with its own guests
                                        included.
                                    </FieldDescription>
                                </FieldContent>
                            </Field>
                            <Field orientation="horizontal">
                                <RadioGroupItem
                                    id="preview-guests-custom"
                                    value="custom"
                                />
                                <FieldContent>
                                    <FieldLabel htmlFor="preview-guests-custom">
                                        Custom
                                    </FieldLabel>
                                    <FieldDescription>
                                        The same party in every room type.
                                    </FieldDescription>
                                </FieldContent>
                            </Field>
                        </RadioGroup>
                    </FieldSet>
                    {custom && (
                        <FieldGroup className="gap-3">
                            <Field orientation="horizontal">
                                <FieldLabel
                                    htmlFor="preview-adults"
                                    className="flex-1"
                                >
                                    Adults
                                </FieldLabel>
                                <Input
                                    id="preview-adults"
                                    className="w-20"
                                    type="number"
                                    min="1"
                                    max="50"
                                    inputMode="numeric"
                                    value={adults}
                                    onChange={(event) =>
                                        setAdults(event.target.value)
                                    }
                                />
                            </Field>
                            {ageGroups.map((group) => (
                                <Field key={group.id} orientation="horizontal">
                                    <FieldLabel
                                        htmlFor={`preview-children-${group.id}`}
                                        className="flex-1"
                                    >
                                        {group.label}
                                        <span className="font-normal text-muted-foreground">
                                            {group.minAge}–{group.maxAge}
                                        </span>
                                    </FieldLabel>
                                    <Input
                                        id={`preview-children-${group.id}`}
                                        className="w-20"
                                        type="number"
                                        min="0"
                                        max="20"
                                        inputMode="numeric"
                                        value={children[group.id] ?? '0'}
                                        onChange={(event) =>
                                            setChildren({
                                                ...children,
                                                [group.id]: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            ))}
                        </FieldGroup>
                    )}
                    <p className="text-sm text-muted-foreground">
                        Nothing here is saved.
                    </p>
                </FieldGroup>
            </FieldSet>

            <section
                aria-labelledby="discount-preview"
                aria-busy={http.processing}
                className="flex w-full min-w-0 flex-col gap-3 lg:col-span-2"
            >
                <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <h2
                            id="discount-preview"
                            className="text-sm font-medium"
                        >
                            Price preview
                        </h2>
                        {http.processing && <Spinner />}
                    </div>
                    <ToggleGroup
                        variant="outline"
                        value={[view]}
                        onValueChange={(value) =>
                            value[0] && setView(value[0] as View)
                        }
                        aria-label="Preview view"
                    >
                        <ToggleGroupItem value="night">
                            <Moon data-icon="inline-start" />
                            By night
                        </ToggleGroupItem>
                        <ToggleGroupItem value="length">
                            <CalendarRange data-icon="inline-start" />
                            By stay length
                        </ToggleGroupItem>
                    </ToggleGroup>
                </div>
                {preview.rows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No room types sell this plan yet.
                    </p>
                ) : (
                    <div className="overflow-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Room type</TableHead>
                                    {view === 'night'
                                        ? Array.from(
                                              { length: preview.nights },
                                              (_, index) => (
                                                  <TableHead
                                                      key={index}
                                                      className="text-right"
                                                  >
                                                      Night {index + 1}
                                                  </TableHead>
                                              ),
                                          )
                                        : preview.stayLengths.map((length) => (
                                              <TableHead
                                                  key={length}
                                                  className="text-right"
                                              >
                                                  {length}{' '}
                                                  {length === 1
                                                      ? 'night'
                                                      : 'nights'}
                                              </TableHead>
                                          ))}
                                    {view === 'night' && (
                                        <TableHead className="border-l text-right">
                                            Total
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {preview.rows.map((row) => (
                                    <TableRow key={row.roomTypeId}>
                                        <TableCell className="align-top">
                                            <div className="font-medium">
                                                {row.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {partyLabel(row)}
                                            </div>
                                        </TableCell>
                                        {row.refusal ? (
                                            <TableCell
                                                colSpan={columnCount}
                                                className="align-top text-destructive"
                                            >
                                                {row.refusal}
                                            </TableCell>
                                        ) : view === 'night' && row.byNight ? (
                                            <>
                                                {row.byNight.nights.map(
                                                    (night, index) => (
                                                        <PriceCell
                                                            key={index}
                                                            amount={
                                                                night.amount
                                                            }
                                                            discount={
                                                                night.discount
                                                            }
                                                            detail={`${number.format(Number(night.unitAmount))} per ${row.unit}`}
                                                            money={money}
                                                            number={number}
                                                        />
                                                    ),
                                                )}
                                                <PriceCell
                                                    className="border-l"
                                                    amount={row.byNight.total}
                                                    discount={
                                                        row.byNight.discount
                                                    }
                                                    money={money}
                                                    number={number}
                                                />
                                            </>
                                        ) : (
                                            row.stays.map((stay) => (
                                                <PriceCell
                                                    key={stay.nights}
                                                    amount={stay.total}
                                                    discount={stay.discount}
                                                    detail={`${nightsLine(stay.nightAmounts, number)} per ${row.unit}`}
                                                    money={money}
                                                    number={number}
                                                />
                                            ))
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
                {preview.ignoredRules > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {preview.ignoredRules === 1
                            ? '1 row with errors is not in the preview.'
                            : `${preview.ignoredRules} rows with errors are not in the preview.`}
                    </p>
                )}
            </section>
        </div>
    );
}
