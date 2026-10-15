import { Head, router, setLayoutProps, useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import BasicRateController from '@/actions/App/Http/Controllers/Onboarding/BasicRateController';
import Heading from '@/components/heading';
import { MoneyInput } from '@/components/pms/money-input';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import {
    MoreRatesTable,
    type MoreRate,
} from '@/components/pms/onboarding/more-rates-table';
import { StepForm } from '@/components/pms/onboarding/step-form';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
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
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
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
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

type Option = { value: string; label: string };
type SavedRow = App.Data.Onboarding.OnboardingBasicRateRowData;
type Preview = App.Data.Onboarding.OnboardingBasicRatePreviewData;
type Example = App.Data.Onboarding.OnboardingMoreRateExampleData;
type Previews = { rows: Preview[]; followers: Example[] };
type SavedMoreRate = App.Data.Onboarding.OnboardingMoreRateData;
type Row = {
    room_type_id: number;
    name: string;
    price_by: string;
    weekday: string;
    weekend: string;
    has_saved_prices: boolean;
    base_occupancy: number;
};
type Payload = {
    weekend_nights: string;
    same_every_night: boolean;
    rows: Row[];
    more_rates: MoreRate[];
};

const FORM_ID = 'basic-rate-form';
const WEEKEND_DAYS: Record<string, string> = {
    fri_sat: 'Fri, Sat',
    sat_sun: 'Sat, Sun',
};

export default function BasicRate({
    rows: savedRows,
    sameEveryNight,
    weekendNights,
    weekendNightsOptions,
    priceByOptions,
    moreRates: savedMoreRates,
    preview: savedPreview,
    currency,
    locked,
    blockedBy,
}: {
    rows: SavedRow[];
    sameEveryNight: boolean;
    weekendNights: string;
    weekendNightsOptions: Option[];
    priceByOptions: Option[];
    moreRates: SavedMoreRate[];
    preview: Previews;
    currency: App.Data.Property.CurrencyData | null;
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const http = useHttp<Payload, Previews>({
        weekend_nights: weekendNights,
        same_every_night: sameEveryNight,
        rows: savedRows.map((row) => ({
            room_type_id: row.roomTypeId,
            name: row.name,
            price_by: row.priceBy,
            weekday: row.weekday ?? '',
            weekend: row.weekend ?? '',
            has_saved_prices: row.hasSavedPrices,
            base_occupancy: row.baseOccupancy,
        })),
        more_rates: savedMoreRates.map((rate) => ({
            key: rate.key,
            plan_ids: rate.planIds,
            name: rate.name,
            mode: rate.mode as 'follows' | 'own',
            parent_basis: rate.parentBasis ?? '',
            direction: rate.direction,
            unit: rate.unit,
            amount: rate.amount ?? '',
            room_type_ids: rate.roomTypeIds,
            prices: rate.prices.map((line) => ({
                room_type_id: line.roomTypeId,
                name: line.name,
                price_by: line.priceBy,
                weekday: line.weekday ?? '',
                weekend: line.weekend ?? '',
            })),
        })),
    });
    const guard = useUnsavedChanges(http.isDirty);
    const [previews, setPreviews] = useState(savedPreview);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [pendingSwitch, setPendingSwitch] = useState<{
        index: number;
        value: string;
    } | null>(null);
    const {
        rows,
        more_rates: moreRates,
        same_every_night: same,
        weekend_nights: nights,
    } = http.data;
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency?.code ?? 'MYR',
    });
    const days = WEEKEND_DAYS[nights] ?? '';
    const guestRows = rows.filter((row) => row.price_by === 'per_guest');
    const roomRows = rows.filter((row) => row.price_by === 'per_room');
    const mixed = guestRows.length > 0 && roomRows.length > 0;

    const previewKey = JSON.stringify([
        rows.map((row) => [
            row.room_type_id,
            row.price_by,
            row.weekday,
            same ? row.weekday : row.weekend,
        ]),
        moreRates
            .filter((rate) => rate.mode === 'follows')
            .map((rate) => [
                rate.key,
                rate.parent_basis,
                rate.direction,
                rate.unit,
                rate.amount,
                rate.room_type_ids,
            ]),
    ]);
    const previewedKey = useRef(previewKey);

    useEffect(() => {
        if (previewedKey.current === previewKey || locked) {
            return;
        }

        const timer = window.setTimeout(() => {
            previewedKey.current = previewKey;
            http.post(BasicRateController.preview.url(), {
                onSuccess: (response) => setPreviews(response),
            }).catch(() => undefined);
        }, 250);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [previewKey]);

    setLayoutProps<OnboardingLayoutProps>({
        step: 'basic_rate',
        footer: 'auto',
        formId: FORM_ID,
        processing: saving,
        width: '8xl',
    });

    const setRow = (index: number, values: Partial<Row>) =>
        http.setData(
            'rows',
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );

    const choosePriceBy = (index: number, value: string) => {
        if (rows[index].has_saved_prices && rows[index].price_by !== value) {
            setPendingSwitch({ index, value });

            return;
        }

        setRow(index, { price_by: value });
    };

    const chooseSame = (value: boolean) => {
        http.setData({
            ...http.data,
            same_every_night: value,
            rows: rows.map((row) => ({
                ...row,
                weekend:
                    value || row.weekend !== '' ? row.weekend : row.weekday,
            })),
        });
    };

    const save = (onSaved?: () => void) =>
        router.put(BasicRateController.update.url(), http.data, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => {
                setErrors({});
                http.setDefaults();
                onSaved?.();
            },
            onError: (next) => {
                setErrors(next);
                guard.stay();
            },
        });

    const totalOf = (roomTypeId: number) =>
        previews.rows.find((item) => item.roomTypeId === roomTypeId);
    const ready =
        !locked &&
        rows.length > 0 &&
        rows.every((row) => row.weekday !== '' && (same || row.weekend !== ''));

    const guestPays = (row: Row, total: string | null | undefined) => {
        if (!total) {
            return null;
        }

        return row.price_by === 'per_guest' ? (
            <>
                <span className="block">per adult</span>
                <span className="block">
                    {row.base_occupancy} adults: {money.format(Number(total))}
                </span>
            </>
        ) : (
            <span className="block">
                guest pays {money.format(Number(total))}
            </span>
        );
    };

    return (
        <>
            <Head title="Basic rate" />
            <Heading
                title="Basic rate"
                description="What does each room type cost per night?"
            />
            <StepForm
                id={FORM_ID}
                locked={locked}
                blockedBy={blockedBy}
                error={errors.onboarding ?? errors.rows ?? null}
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <div className="flex flex-wrap gap-x-8 gap-y-4">
                    <Field className="w-auto">
                        <FieldLabel>Sell format</FieldLabel>
                        <ToggleGroup
                            variant="outline"
                            spacing={0}
                            value={[same ? 'same' : 'different']}
                            onValueChange={(value) =>
                                value[0] && chooseSame(value[0] === 'same')
                            }
                            aria-label="Sell format"
                        >
                            <ToggleGroupItem value="same">
                                Every night
                            </ToggleGroupItem>
                            <ToggleGroupItem value="different">
                                Weekdays + Weekends
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </Field>
                    {!same && (
                        <Field className="w-auto">
                            <FieldLabel>Weekend nights</FieldLabel>
                            <ToggleGroup
                                variant="outline"
                                spacing={0}
                                value={[nights]}
                                onValueChange={(value) =>
                                    value[0] &&
                                    http.setData('weekend_nights', value[0])
                                }
                                aria-label="Weekend nights"
                            >
                                {weekendNightsOptions.map((option) => (
                                    <ToggleGroupItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                            <FieldError>{errors.weekend_nights}</FieldError>
                        </Field>
                    )}
                </div>

                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader className="max-md:hidden">
                            <TableRow>
                                <TableHead>Room type</TableHead>
                                <TableHead>Price by</TableHead>
                                <TableHead>
                                    {same ? 'Every night' : 'Weekdays'}
                                </TableHead>
                                {!same && (
                                    <TableHead>Weekend ({days})</TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row, index) => {
                                const fieldError = (name: string) =>
                                    errors[`rows.${index}.${name}`];
                                const total = totalOf(row.room_type_id);
                                const placeholder =
                                    row.price_by === 'per_guest'
                                        ? 'e.g. 85.00'
                                        : 'e.g. 180.00';

                                return (
                                    <TableRow
                                        key={row.room_type_id}
                                        className="max-md:grid max-md:grid-cols-2 max-md:gap-3 max-md:p-3"
                                    >
                                        <TableCell className="align-top font-medium max-md:col-span-2 max-md:p-0">
                                            <p className="pt-2">{row.name}</p>
                                        </TableCell>
                                        <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                Price by
                                            </span>
                                            <Select
                                                items={priceByOptions}
                                                value={row.price_by}
                                                onValueChange={(value) =>
                                                    choosePriceBy(
                                                        index,
                                                        value as string,
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    aria-label={`Price by for ${row.name}`}
                                                    className="w-full"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectGroup>
                                                        {priceByOptions.map(
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
                                        </TableCell>
                                        <TableCell className="align-top max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                {same
                                                    ? 'Every night'
                                                    : 'Weekdays'}
                                            </span>
                                            <MoneyInput
                                                currency={currency}
                                                placeholder={placeholder}
                                                value={row.weekday}
                                                onChange={(value) =>
                                                    setRow(index, {
                                                        weekday: value,
                                                    })
                                                }
                                                invalid={
                                                    !!fieldError('weekday')
                                                }
                                            />
                                            <FieldError>
                                                {fieldError('weekday')}
                                            </FieldError>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {guestPays(
                                                    row,
                                                    total?.weekdayTotal,
                                                )}
                                            </p>
                                        </TableCell>
                                        {!same && (
                                            <TableCell className="align-top max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    Weekend ({days})
                                                </span>
                                                <MoneyInput
                                                    currency={currency}
                                                    placeholder={placeholder}
                                                    value={row.weekend}
                                                    onChange={(value) =>
                                                        setRow(index, {
                                                            weekend: value,
                                                        })
                                                    }
                                                    invalid={
                                                        !!fieldError('weekend')
                                                    }
                                                />
                                                <FieldError>
                                                    {fieldError('weekend')}
                                                </FieldError>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {guestPays(
                                                        row,
                                                        total?.weekendTotal,
                                                    )}
                                                </p>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>

                {rows.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {mixed
                            ? `This creates 2 rates: "Basic Rate / Room" (${roomRows.length} room ${roomRows.length === 1 ? 'type' : 'types'}) and "Basic Rate / Guest" (${guestRows.length} room ${guestRows.length === 1 ? 'type' : 'types'}).`
                            : 'This creates 1 rate: "Basic Rate".'}
                    </p>
                )}
                {guestRows.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Children on per-guest prices: infants (0–3) stay free,
                        children (4–11) pay half. After you go live, you can
                        change this in Settings, under Rate plans.
                    </p>
                )}
                <MoreRatesTable
                    rates={moreRates}
                    onChange={(next) => http.setData('more_rates', next)}
                    basicLines={rows.map((row) => ({
                        room_type_id: row.room_type_id,
                        name: row.name,
                        price_by: row.price_by,
                    }))}
                    ready={ready}
                    sameEveryNight={same}
                    weekendDays={days}
                    priceByOptions={priceByOptions}
                    currency={currency}
                    examples={previews.followers}
                    errors={errors}
                />
            </StepForm>

            <AlertDialog
                open={pendingSwitch !== null}
                onOpenChange={(open) => !open && setPendingSwitch(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Switch{' '}
                            {pendingSwitch
                                ? rows[pendingSwitch.index].name
                                : ''}
                            ?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Switching{' '}
                            {pendingSwitch
                                ? rows[pendingSwitch.index].name
                                : ''}{' '}
                            to per{' '}
                            {pendingSwitch?.value === 'per_guest'
                                ? 'guest'
                                : 'room'}{' '}
                            clears its prices.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => {
                                if (pendingSwitch) {
                                    setRow(pendingSwitch.index, {
                                        price_by: pendingSwitch.value,
                                        weekday: '',
                                        weekend: '',
                                        has_saved_prices: false,
                                    });
                                }

                                setPendingSwitch(null);
                            }}
                        >
                            Clear prices and switch
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <UnsavedChangesDialog
                guard={guard}
                name="Basic rate"
                saving={saving}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}
