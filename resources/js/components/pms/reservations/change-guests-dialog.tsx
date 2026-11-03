import { useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { party } from '@/routes/reservations/units';
import { preview as partyPreview } from '@/routes/reservations/units/party';

type Preview = App.Data.Reservations.UnitPartyPreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: App.Data.Reservations.ReservationUnitData;
    canChangePrice: boolean;
    currency: App.Data.Property.CurrencyData | null;
};

function ChangeGuestsForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    canChangePrice,
    currency,
}: Props) {
    const key = { reservation: reservationId, unit: unit.id };
    const form = useForm<{
        adults: string;
        child_ages: string[];
        reprice_charged: boolean;
    }>({
        adults: String(unit.adults),
        child_ages: unit.childAges.map(String),
        reprice_charged: false,
    });
    const { data, errors } = form;
    const valid =
        /^\d+$/.test(data.adults) &&
        Number(data.adults) > 0 &&
        data.child_ages.every((age) => /^\d+$/.test(age));
    const { preview } = useUnitPreview<Preview>(
        partyPreview.url(key),
        open && valid,
        {
            adults: data.adults,
            child_ages: data.child_ages.map(Number),
            reprice_charged: data.reprice_charged ? '1' : '0',
        },
        300,
    );
    const ready = valid && preview !== null && preview.error === null;

    const setAge = (index: number, value: string) =>
        form.setData(
            'child_ages',
            data.child_ages.map((age, position) =>
                position === index ? value : age,
            ),
        );

    const submit = () => {
        form.transform((values) => ({
            ...values,
            child_ages: values.child_ages.map(Number),
        }));
        form.put(party.url(key), {
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
            title={`Change guests · ${unit.roomType}`}
            description={unit.primaryGuest ?? undefined}
            errors={errors}
            onSubmit={submit}
            submitLabel="Change"
            processing={form.processing}
            submitDisabled={!ready}
        >
            <Field data-invalid={!!errors.adults}>
                <FieldLabel htmlFor="party-adults">Adults</FieldLabel>
                <Input
                    id="party-adults"
                    className="w-24"
                    type="number"
                    min="1"
                    inputMode="numeric"
                    value={data.adults}
                    onChange={(event) =>
                        form.setData('adults', event.target.value)
                    }
                    aria-invalid={!!errors.adults}
                    autoFocus
                />
                <FieldError>{errors.adults}</FieldError>
            </Field>
            <Field data-invalid={!!errors.child_ages}>
                <FieldLabel>Children</FieldLabel>
                <div className="flex flex-wrap items-center gap-2">
                    {data.child_ages.map((age, index) => (
                        <div key={index} className="flex items-center gap-1">
                            <Input
                                aria-label={`Age of child ${index + 1}`}
                                className="w-20"
                                type="number"
                                min="0"
                                max="17"
                                inputMode="numeric"
                                placeholder="Age"
                                value={age}
                                onChange={(event) =>
                                    setAge(index, event.target.value)
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={`Remove child ${index + 1}`}
                                onClick={() =>
                                    form.setData(
                                        'child_ages',
                                        data.child_ages.filter(
                                            (_, position) => position !== index,
                                        ),
                                    )
                                }
                            >
                                <X />
                            </Button>
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            form.setData('child_ages', [...data.child_ages, ''])
                        }
                    >
                        <Plus data-icon="inline-start" />
                        Add child
                    </Button>
                </div>
                <FieldDescription>
                    Ages decide the child price and free infants.
                </FieldDescription>
                <FieldError>
                    {errors.child_ages ??
                        Object.entries(errors).find(([field]) =>
                            field.startsWith('child_ages.'),
                        )?.[1]}
                </FieldError>
            </Field>
            {canChangePrice && preview?.hasCharged && (
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={data.reprice_charged}
                        onCheckedChange={(checked) =>
                            form.setData('reprice_charged', checked)
                        }
                    />
                    Also re-price nights already charged
                </label>
            )}
            {preview?.error && (
                <p className="text-sm text-destructive">{preview.error}</p>
            )}
            {preview !== null && preview.error === null && (
                <div className="flex flex-col gap-2 text-sm">
                    {preview.fromChannel && (
                        <p className="text-muted-foreground">
                            Booked on a channel: the price will not change.
                            Per-guest extras and boat fares follow the guests.
                        </p>
                    )}
                    <ul className="flex flex-col gap-1">
                        {preview.nights.map((night) => (
                            <li
                                key={night.date}
                                className="flex justify-between gap-4"
                            >
                                <span>
                                    <DateText date={night.date} />
                                    {night.charged && !night.changes && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · charged, keeps its price
                                        </span>
                                    )}
                                    {night.overridden && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · price set by hand
                                        </span>
                                    )}
                                </span>
                                <span>
                                    {night.changes && (
                                        <span className="text-muted-foreground line-through">
                                            <MoneyText
                                                amount={night.was}
                                                currency={currency}
                                            />
                                        </span>
                                    )}{' '}
                                    <MoneyText
                                        amount={night.will}
                                        currency={currency}
                                    />
                                </span>
                            </li>
                        ))}
                    </ul>
                    <div className="flex justify-between gap-4 border-t pt-2">
                        <span className="text-muted-foreground">
                            Room total
                        </span>
                        <span className="font-medium">
                            <MoneyText
                                amount={preview.after}
                                currency={currency}
                            />{' '}
                            <span className="text-muted-foreground">
                                ({Number(preview.difference) >= 0 ? '+' : ''}
                                <MoneyText
                                    amount={preview.difference}
                                    currency={currency}
                                />
                                )
                            </span>
                        </span>
                    </div>
                    <p className="text-muted-foreground">
                        Per-guest extras not charged yet, such as a jetty fee,
                        follow the new guests. Extras already charged do not
                        change.
                    </p>
                </div>
            )}
        </FormOverlay>
    );
}

/**
 * Change the adults and child ages of one room. The server prices the stay again for the new guests and shows each
 * night before and after; charged nights keep their price unless re-pricing is ticked.
 */
export function ChangeGuestsDialog(props: Props) {
    return <ChangeGuestsForm key={useFreshOnOpen(props.open)} {...props} />;
}
