import { useForm } from '@inertiajs/react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { nightPrice } from '@/routes/reservations/units';
import { preview as nightPricePreview } from '@/routes/reservations/units/night-price';

type Preview = App.Data.Reservations.NightPriceChangePreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unitId: number;
    title: string;
    description?: string | null;
    initialNightIds: number[];
    currency: App.Enums.Currency | null;
};

function Dates({ dates }: { dates: string[] }) {
    return dates.map((date, index) => (
        <span key={date}>
            {index > 0 && ', '}
            <DateText date={date} />
        </span>
    ));
}

function ChangeNightPriceForm({
    open,
    onOpenChange,
    reservationId,
    unitId,
    title,
    description,
    initialNightIds,
    currency,
}: Props) {
    const key = { reservation: reservationId, unit: unitId };
    const form = useForm<{
        night_ids: number[];
        price: string;
        reason: string;
    }>({ night_ids: initialNightIds, price: '', reason: '' });
    const { data, errors } = form;
    const { preview } = useUnitPreview<Preview>(
        nightPricePreview.url(key),
        open && data.night_ids.length > 0,
        { night_ids: data.night_ids, price: data.price },
        300,
    );
    const priced = data.price !== '' && preview !== null;
    const difference = preview?.difference ?? null;
    const ready =
        data.night_ids.length > 0 &&
        data.reason.trim() !== '' &&
        priced &&
        preview.error === null;

    const toggle = (id: number, checked: boolean) =>
        form.setData(
            'night_ids',
            checked
                ? [...data.night_ids, id]
                : data.night_ids.filter((nightId) => nightId !== id),
        );

    const submit = () => {
        form.post(nightPrice.url(key), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={`Change price · ${title}`}
            description={description ?? undefined}
            errors={errors}
            onSubmit={submit}
            submitLabel="Change"
            processing={form.processing}
            submitDisabled={!ready}
            loading={preview === null}
        >
            {preview !== null && (
                <>
                    <Field data-invalid={!!errors.night_ids}>
                        <FieldLabel>Nights</FieldLabel>
                        <div className="flex flex-wrap gap-x-4 gap-y-2">
                            {preview.nights.map((night) => (
                                <label
                                    key={night.id}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        checked={data.night_ids.includes(
                                            night.id,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggle(night.id, checked)
                                        }
                                    />
                                    <DateText date={night.date} />
                                    {night.charged && (
                                        <span className="text-muted-foreground">
                                            (charged)
                                        </span>
                                    )}
                                </label>
                            ))}
                        </div>
                        <FieldError>{errors.night_ids}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.price}>
                        <FieldLabel htmlFor="night-price-price">
                            New price per night
                        </FieldLabel>
                        <Input
                            id="night-price-price"
                            inputMode="decimal"
                            value={data.price}
                            onChange={(event) =>
                                form.setData('price', event.target.value)
                            }
                            autoFocus
                            aria-invalid={!!errors.price}
                        />
                        {preview.was !== null && (
                            <FieldDescription>
                                Was{' '}
                                <MoneyText
                                    amount={preview.was}
                                    currency={currency}
                                />
                                {preview.wasHigh !== null && (
                                    <>
                                        {' '}
                                        to{' '}
                                        <MoneyText
                                            amount={preview.wasHigh}
                                            currency={currency}
                                        />
                                    </>
                                )}
                                . Price 0 is a complimentary night.
                            </FieldDescription>
                        )}
                        <FieldError>{errors.price}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.reason}>
                        <FieldLabel htmlFor="night-price-reason">
                            Reason
                        </FieldLabel>
                        <Textarea
                            id="night-price-reason"
                            value={data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            maxLength={500}
                            rows={2}
                            aria-invalid={!!errors.reason}
                        />
                        <FieldError>{errors.reason}</FieldError>
                    </Field>
                    {preview.hadDiscount && (
                        <p className="text-sm text-muted-foreground">
                            Discounts do not apply to a changed price.
                        </p>
                    )}
                    {priced && preview.error !== null && (
                        <p className="text-sm text-destructive">
                            {preview.error}
                        </p>
                    )}
                    {priced && preview.error === null && (
                        <div className="flex flex-col gap-1 text-sm">
                            {preview.correctedDates.length > 0 && (
                                <p>
                                    <Dates dates={preview.correctedDates} />{' '}
                                    {preview.correctedDates.length === 1
                                        ? 'is'
                                        : 'are'}{' '}
                                    already charged. It will be reversed and
                                    charged again at the new price with its
                                    inclusions and taxes.
                                </p>
                            )}
                            {preview.scheduledDates.length > 0 && (
                                <p className="text-muted-foreground">
                                    <Dates dates={preview.scheduledDates} />{' '}
                                    will be charged at the new price by the
                                    night audit.
                                </p>
                            )}
                            {difference !== null && (
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">
                                        Difference
                                    </span>
                                    <span className="font-medium">
                                        <MoneyText
                                            amount={difference}
                                            currency={currency}
                                        />
                                    </span>
                                </div>
                            )}
                        </div>
                    )}
                </>
            )}
        </FormOverlay>
    );
}

/**
 * Change price for one or more nights of a unit, from the night table or a posted room line. The nights, the
 * current price and the difference with tax come from the server as the form changes. A charged night is reversed
 * and charged again; a Scheduled night only gets the new price.
 */
export function ChangeNightPriceDialog(props: Props) {
    return <ChangeNightPriceForm key={useFreshOnOpen(props.open)} {...props} />;
}
