import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store } from '@/routes/reservations/folios/charges';

type Folio = App.Data.Folio.FolioData;
type ChargeItem = App.Data.Reservations.ChargeItemOptionData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    folio: Folio;
    chargeItems?: ChargeItem[];
    currency: App.Enums.Currency | null;
};

function PostChargeForm({
    open,
    onOpenChange,
    reservationId,
    folio,
    chargeItems = [],
    currency,
    loading,
}: Props & { loading: boolean }) {
    useFormOptions(open, !loading, ['chargeItems']);

    const [key] = useState(() => `manual:${crypto.randomUUID()}`);
    const form = useForm({
        unit_id: String(folio.postUnits[0]?.id ?? ''),
        charge_item_id: '',
        description: '',
        quantity: '1',
        unit_amount: '',
    });
    const { data, errors } = form;
    const item = chargeItems.find(
        (one) => String(one.id) === data.charge_item_id,
    );
    const picked = item !== undefined;
    const showAmount =
        item !== undefined &&
        (item.allowOverride || item.defaultAmount === null);

    const submit = () => {
        form.transform((values) => ({
            unit_id: folio.postUnits.length > 1 ? values.unit_id : '',
            charge_item_id: values.charge_item_id,
            description: values.description,
            quantity: values.quantity,
            unit_amount: showAmount
                ? values.unit_amount
                : (item?.defaultAmount ?? ''),
            idempotency_key: key,
        }));
        form.post(
            store.url({
                reservation: reservationId,
                folio: Number(folio.key),
            }),
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Post charge"
            description="Add a charge from the catalogue. For a one-off, pick the closest item and describe it."
            errors={errors}
            onSubmit={submit}
            submitLabel="Post charge"
            processing={form.processing}
            submitDisabled={!picked}
            loading={loading}
        >
            {folio.postUnits.length > 1 && (
                <Field data-invalid={!!errors.unit_id}>
                    <FieldLabel htmlFor="charge-unit_id">Unit</FieldLabel>
                    <EnumSelect
                        id="charge-unit_id"
                        options={folio.postUnits.map((one) => ({
                            value: String(one.id),
                            label: one.label,
                        }))}
                        value={data.unit_id}
                        onValueChange={(value) =>
                            form.setData('unit_id', value)
                        }
                        aria-invalid={!!errors.unit_id}
                    />
                    <FieldDescription>
                        Routing rules decide which folio receives the charge.
                    </FieldDescription>
                    <FieldError>{errors.unit_id}</FieldError>
                </Field>
            )}
            <Field data-invalid={!!errors.charge_item_id}>
                <FieldLabel htmlFor="charge-choice">Charge</FieldLabel>
                <EnumSelect
                    id="charge-choice"
                    options={chargeItems.map((one) => ({
                        value: String(one.id),
                        label: one.name,
                    }))}
                    placeholder="Choose a charge"
                    value={data.charge_item_id}
                    onValueChange={(value) => {
                        const next = chargeItems.find(
                            (one) => String(one.id) === value,
                        );

                        form.setData((current) => ({
                            ...current,
                            charge_item_id: value,
                            unit_amount: next?.defaultAmount ?? '',
                        }));
                    }}
                    aria-invalid={!!errors.charge_item_id}
                />
                <FieldError>{errors.charge_item_id}</FieldError>
            </Field>
            {picked && (
                <Field data-invalid={!!errors.description}>
                    <FieldLabel htmlFor="charge-description">
                        Description
                    </FieldLabel>
                    <Input
                        id="charge-description"
                        maxLength={120}
                        placeholder={item.name}
                        value={data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                        aria-invalid={!!errors.description}
                    />
                    <FieldDescription>
                        Optional. Replaces the name on the folio line.
                    </FieldDescription>
                    <FieldError>{errors.description}</FieldError>
                </Field>
            )}
            {picked && (
                <Field data-invalid={!!errors.quantity}>
                    <FieldLabel htmlFor="charge-quantity">Quantity</FieldLabel>
                    <Input
                        id="charge-quantity"
                        type="number"
                        min={1}
                        max={999}
                        value={data.quantity}
                        onChange={(event) =>
                            form.setData('quantity', event.target.value)
                        }
                        aria-invalid={!!errors.quantity}
                    />
                    <FieldError>{errors.quantity}</FieldError>
                </Field>
            )}
            {showAmount && (
                <Field data-invalid={!!errors.unit_amount}>
                    <FieldLabel htmlFor="charge-unit_amount">
                        Unit amount
                    </FieldLabel>
                    <MoneyInput
                        id="charge-unit_amount"
                        value={data.unit_amount}
                        onChange={(value) => form.setData('unit_amount', value)}
                        currency={currency}
                        invalid={!!errors.unit_amount}
                    />
                    {item?.defaultAmount != null && (
                        <FieldDescription>
                            The catalogue price is {item.defaultAmount}.
                        </FieldDescription>
                    )}
                    <FieldError>{errors.unit_amount}</FieldError>
                </Field>
            )}
            {item !== undefined && !showAmount && (
                <p className="text-sm text-muted-foreground">
                    The price is {item.defaultAmount} for each.
                </p>
            )}
        </FormOverlay>
    );
}

/**
 * Posts a charge to a folio. A Unit folio uses its own unit. A Master or Third party folio picks the unit, and routing
 * rules decide the folio. A refusal shows in the Dialog.
 */
export function PostChargeDialog(props: Props) {
    return (
        <PostChargeForm
            key={useFreshOnOpen(props.open)}
            {...props}
            loading={props.chargeItems === undefined}
        />
    );
}
