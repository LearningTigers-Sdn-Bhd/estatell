import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { GroupPreviewPanel } from '@/components/pms/reservations/group-preview-panel';
import { MultiSelectWithAll } from '@/components/pms/multi-select-with-all';
import type { Choice } from '@/components/pms/multi-select-with-all';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { nightPrice } from '@/routes/reservations';
import { preview as nightPricePreview } from '@/routes/reservations/night-price';

type Preview = App.Data.Reservations.GroupNightPricePreviewData;
type Currency = App.Data.Property.CurrencyData | null;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    /** The units that can change their price: Confirmed, CheckedIn or CheckedOut, with nights. All are picked at open. */
    units: Choice[];
    currency: Currency;
};

function Was({
    line,
    currency,
}: {
    line: App.Data.Reservations.GroupNightPricePreviewLineData;
    currency: Currency;
}) {
    if (line.was === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <>
            <MoneyText amount={line.was} currency={currency} plain />
            {line.wasHigh !== null && (
                <>
                    –
                    <MoneyText
                        amount={line.wasHigh}
                        currency={currency}
                        plain
                    />
                </>
            )}
        </>
    );
}

function ChangeGroupPriceForm({
    open,
    onOpenChange,
    reservationId,
    units,
    currency,
}: Props) {
    const key = { reservation: reservationId };
    const form = useForm<{ unit_ids: number[]; price: string; reason: string }>(
        { unit_ids: units.map((unit) => unit.id), price: '', reason: '' },
    );
    const { data, errors } = form;
    const unitIds = data.unit_ids;
    const { preview } = useUnitPreview<Preview>(
        nightPricePreview.url(key),
        open && unitIds.length > 0,
        { unit_ids: unitIds, price: data.price },
        300,
    );
    const lines = preview?.lines ?? [];
    const refused = lines.filter((line) => line.error !== null);
    const charged = lines.reduce((sum, line) => sum + line.charged, 0);
    const ready =
        unitIds.length > 0 &&
        data.price !== '' &&
        data.reason.trim() !== '' &&
        preview?.difference != null &&
        refused.length === 0;

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
            size="lg"
            open={open}
            onOpenChange={onOpenChange}
            title="Change price"
            description="One price per night for every night of the picked units."
            errors={errors}
            onSubmit={submit}
            submitLabel={
                unitIds.length > 1 ? `Change ${unitIds.length} units` : 'Change'
            }
            processing={form.processing}
            submitDisabled={!ready}
        >
            <div className="grid gap-4 lg:min-h-80 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)] lg:gap-6">
                <div className="flex min-w-0 flex-col gap-4">
                    <Field data-invalid={!!errors.unit_ids}>
                        <FieldLabel htmlFor="group-price-unit_ids">
                            Units
                        </FieldLabel>
                        <MultiSelectWithAll
                            id="group-price-unit_ids"
                            name="unit_ids"
                            choices={units}
                            allLabel="All units"
                            placeholder="Choose the units, e.g. F1, F2"
                            emptyLabel="No matching units."
                            value={unitIds}
                            onValueChange={(value) =>
                                form.setData('unit_ids', value)
                            }
                            invalid={!!errors.unit_ids}
                        />
                        <FieldError>{errors.unit_ids}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.price}>
                        <FieldLabel htmlFor="group-price-price">
                            New price per night
                        </FieldLabel>
                        <Input
                            id="group-price-price"
                            inputMode="decimal"
                            placeholder="e.g. 600.00"
                            value={data.price}
                            onChange={(event) =>
                                form.setData('price', event.target.value)
                            }
                            autoFocus
                            aria-invalid={!!errors.price}
                        />
                        <FieldError>{errors.price}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.reason}>
                        <FieldLabel htmlFor="group-price-reason">
                            Reason
                        </FieldLabel>
                        <Textarea
                            id="group-price-reason"
                            placeholder="e.g. Agent group rate"
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
                    <p className="text-sm text-muted-foreground">
                        Price 0 is a complimentary night.
                    </p>
                    {preview?.hadDiscount && (
                        <p className="text-sm text-muted-foreground">
                            Discounts do not apply to a changed price.
                        </p>
                    )}
                    {data.price !== '' && refused.length > 0 && (
                        <ul className="flex flex-col gap-1 text-sm text-destructive">
                            {refused.map((line) => (
                                <li key={line.unitId}>
                                    {line.label}: {line.error}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <GroupPreviewPanel
                    columns="grid-cols-[minmax(0,1fr)_3rem_6rem_6rem]"
                    head={['Unit', 'Nights', 'Was', 'Difference']}
                    rows={
                        preview === null || unitIds.length === 0
                            ? null
                            : lines.map((line) => ({
                                  key: line.unitId,
                                  cells: [
                                      line.label,
                                      line.nights,
                                      <Was
                                          key="was"
                                          line={line}
                                          currency={currency}
                                      />,
                                      line.difference === null ? (
                                          <span className="text-muted-foreground">
                                              —
                                          </span>
                                      ) : (
                                          <MoneyText
                                              amount={line.difference}
                                              currency={currency}
                                              plain
                                          />
                                      ),
                                  ],
                              }))
                    }
                    total={{
                        label: 'Total difference',
                        value:
                            preview?.difference == null ? (
                                <span className="text-muted-foreground">—</span>
                            ) : (
                                <MoneyText
                                    amount={preview.difference}
                                    currency={currency}
                                />
                            ),
                    }}
                    emptyDescription="Pick the units to see each one's current price."
                    note={
                        <>
                            The difference includes tax.
                            {charged > 0 &&
                                ' Charged nights are reversed and charged again.'}
                        </>
                    }
                />
            </div>
        </FormOverlay>
    );
}

/**
 * Change price for every night of several units of a group, from Change price on the Units tab All view. Each unit's current price
 * and difference with tax come from the server as the price changes. See
 * docs/12-reservations/09-change-night-price.md#change-the-price-of-a-group.
 */
export function ChangeGroupPriceDialog(props: Props) {
    return <ChangeGroupPriceForm key={useFreshOnOpen(props.open)} {...props} />;
}
