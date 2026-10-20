import { useForm } from '@inertiajs/react';
import { taxLabel } from '@/components/pms/charge-item-taxes-field';
import {
    postOptions,
    ReasonField,
    useBatchKey,
} from '@/components/pms/folio/manual-line-shared';
import { useLinePreview } from '@/components/pms/folio/use-line-preview';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { edit } from '@/routes/reservations/folios/lines';
import { preview as editPreview } from '@/routes/reservations/folios/lines/edit';
import { nightPrice } from '@/routes/reservations/units';
import { preview as nightPricePreview } from '@/routes/reservations/units/night-price';

type Preview = App.Data.Folio.EditLinePreviewData;
type NightPreview = App.Data.Reservations.NightPriceChangePreviewData;

type Props = {
    open: boolean;
    onClose: () => void;
    context: {
        reservationId: number;
        folioId: number;
        currency: App.Data.Property.CurrencyData | null;
        taxes: App.Data.Catalogues.ChargeItemTaxData[];
    };
    reason: string;
    row: App.Data.Folio.FolioRowData;
    line: App.Data.Folio.ManualLineData;
};

/**
 * One Dialog for a posted charge. A live room night changes only its price, through Change price, so the unit price,
 * the stay total and the folio agree. Any other charge changes its description, amount, quantity and taxes, and posts a
 * reversal and a new line. Before and After come from the server. See docs/13-folio/08-manual-folio.md#edit-line.
 */
export function EditLineForm({
    open,
    onClose,
    context,
    reason,
    row,
    line,
}: Props) {
    const batchKey = useBatchKey();
    const night = line.night;
    const form = useForm({
        description: line.description,
        amount: night?.price ?? line.unitAmount,
        quantity: String(line.quantity),
        tax_ids: line.taxIds.map(String),
        reason,
    });
    const { data } = form;
    const errors = form.errors as Partial<Record<string, string>>;
    const taxOptions = context.taxes
        .filter((tax) => tax.enabled)
        .map((tax) => ({
            value: String(tax.id),
            label: context.currency
                ? taxLabel(tax, context.currency)
                : tax.name,
        }));
    const lineRoute = {
        reservation: context.reservationId,
        folio: context.folioId,
        line: line.lineId,
    };
    const preview = useLinePreview<Preview>(
        editPreview.url(lineRoute),
        open && night === null,
        {
            description: data.description,
            amount: data.amount,
            quantity: data.quantity,
            tax_ids: data.tax_ids.map(Number),
        },
    );
    const nightPreview = useLinePreview<NightPreview>(
        nightPricePreview.url({
            reservation: context.reservationId,
            unit: night?.unitId ?? 0,
        }),
        open && night !== null,
        { night_ids: night ? [night.nightId] : [], price: data.amount },
    );
    const priceReady =
        night !== null
            ? night.canChangePrice &&
              nightPreview !== null &&
              nightPreview.error === null &&
              data.amount !== night.price
            : preview !== null && preview.refusal === null;
    const reasonText = data.reason.trim();
    const message =
        night !== null ? nightPreview?.error : (preview?.refusal ?? null);

    const submit = () => {
        if (night !== null) {
            form.transform((values) => ({
                night_ids: [night.nightId],
                price: values.amount,
                reason: values.reason,
            }));
            form.post(
                nightPrice.url({
                    reservation: context.reservationId,
                    unit: night.unitId,
                }),
                postOptions(onClose),
            );

            return;
        }

        form.transform((values) => ({
            ...values,
            tax_ids: values.tax_ids.map(Number),
            idempotency_key: batchKey,
        }));
        form.post(edit.url(lineRoute), postOptions(onClose));
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title={`Edit line · ${row.description}`}
            description={
                night !== null
                    ? 'A room night changes its price here. The unit price and the folio both follow.'
                    : 'The line is reversed and posted again with the new values. It keeps its service date.'
            }
            errors={errors}
            onSubmit={submit}
            submitLabel="Save changes"
            processing={form.processing}
            submitDisabled={!priceReady || reasonText === ''}
        >
            <Field data-invalid={!!errors.description}>
                <FieldLabel htmlFor="edit-line-description">
                    Description
                </FieldLabel>
                <Input
                    id="edit-line-description"
                    maxLength={120}
                    placeholder="e.g. Minibar, 2 drinks"
                    value={data.description}
                    disabled={night !== null}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                    aria-invalid={!!errors.description}
                />
                <FieldError>{errors.description}</FieldError>
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field data-invalid={!!errors.unit_amount || !!errors.price}>
                    <FieldLabel htmlFor="edit-line-amount">
                        {night !== null ? 'Price per night' : 'Rate'}
                    </FieldLabel>
                    <MoneyInput
                        id="edit-line-amount"
                        value={data.amount}
                        onChange={(value) => form.setData('amount', value)}
                        currency={context.currency}
                        disabled={night !== null && !night.canChangePrice}
                        invalid={!!errors.unit_amount || !!errors.price}
                    />
                    <FieldError>
                        {errors.unit_amount ?? errors.price}
                    </FieldError>
                    {night?.hasDiscount && (
                        <FieldDescription>
                            This night&apos;s discount is replaced by the new
                            price.
                        </FieldDescription>
                    )}
                    {night !== null && !night.canChangePrice && (
                        <FieldDescription>
                            You cannot change the price of a booked night.
                        </FieldDescription>
                    )}
                </Field>
                <Field data-invalid={!!errors.quantity}>
                    <FieldLabel htmlFor="edit-line-quantity">Qty</FieldLabel>
                    <Input
                        id="edit-line-quantity"
                        type="number"
                        min={1}
                        max={999}
                        className="tabular-nums"
                        value={data.quantity}
                        disabled={night !== null}
                        onChange={(event) =>
                            form.setData('quantity', event.target.value)
                        }
                        aria-invalid={!!errors.quantity}
                    />
                    <FieldError>{errors.quantity}</FieldError>
                </Field>
            </div>
            {night === null && (
                <Field data-invalid={!!errors.tax_ids}>
                    <FieldLabel htmlFor="edit-line-taxes">Taxes</FieldLabel>
                    <MultiCombobox
                        id="edit-line-taxes"
                        name="tax_ids"
                        items={taxOptions}
                        value={data.tax_ids}
                        onValueChange={(value) =>
                            form.setData('tax_ids', value)
                        }
                        placeholder="No taxes"
                        emptyLabel="No matching taxes."
                        invalid={!!errors.tax_ids}
                    />
                    <FieldError>{errors.tax_ids}</FieldError>
                </Field>
            )}
            <ReasonField
                id="edit-line-reason"
                value={data.reason}
                onChange={(value) => form.setData('reason', value)}
                error={errors.reason}
            />
            {night === null && preview !== null && preview.refusal === null && (
                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm tabular-nums">
                    <dt className="text-muted-foreground">Before</dt>
                    <dd>
                        <MoneyText
                            amount={preview.beforeNet}
                            currency={context.currency}
                            plain
                        />{' '}
                        + tax{' '}
                        <MoneyText
                            amount={preview.beforeTax}
                            currency={context.currency}
                            plain
                        />{' '}
                        ={' '}
                        <MoneyText
                            amount={preview.beforeTotal}
                            currency={context.currency}
                            plain
                        />
                    </dd>
                    <dt className="text-muted-foreground">After</dt>
                    <dd>
                        <MoneyText
                            amount={preview.afterNet ?? '0.00'}
                            currency={context.currency}
                            plain
                        />{' '}
                        + tax{' '}
                        <MoneyText
                            amount={preview.afterTax ?? '0.00'}
                            currency={context.currency}
                            plain
                        />{' '}
                        ={' '}
                        <MoneyText
                            amount={preview.afterTotal ?? '0.00'}
                            currency={context.currency}
                            plain
                        />{' '}
                        (
                        <MoneyText
                            amount={preview.difference ?? '0.00'}
                            currency={context.currency}
                            plain
                        />
                        )
                    </dd>
                </dl>
            )}
            {night !== null && nightPreview?.difference != null && (
                <p className="text-sm tabular-nums">
                    <span className="text-muted-foreground">Difference </span>
                    <MoneyText
                        amount={nightPreview.difference}
                        currency={context.currency}
                        plain
                    />
                </p>
            )}
            {message && (
                <p className="text-sm text-muted-foreground">{message}</p>
            )}
        </FormOverlay>
    );
}
