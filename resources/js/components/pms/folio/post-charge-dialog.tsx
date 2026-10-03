import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyInput } from '@/components/pms/money-input';
import { FormError } from '@/components/pms/reservations/form-error';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/reservations/use-form-options';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/reservations/folios/charges';

type Folio = App.Data.Folio.FolioData;
type ChargeItem = App.Data.Reservations.ChargeItemOptionData;
type ChargeCode = App.Data.Reservations.ChargeCodeOptionData;

const OTHER = 'other';

function PostChargeForm({
    reservationId,
    folio,
    chargeItems,
    chargeCodes,
    currency,
    onDone,
}: {
    reservationId: number;
    folio: Folio;
    chargeItems: ChargeItem[];
    chargeCodes: ChargeCode[];
    currency: App.Enums.Currency | null;
    onDone: () => void;
}) {
    const [key] = useState(() => `manual:${crypto.randomUUID()}`);
    const form = useForm({
        unit_id: String(folio.postUnits[0]?.id ?? ''),
        charge_item_id: '',
        transaction_code_id: '',
        description: '',
        quantity: '1',
        unit_amount: '',
    });
    const { data, errors } = form;
    const isOther = data.charge_item_id === OTHER;
    const item = chargeItems.find(
        (one) => String(one.id) === data.charge_item_id,
    );
    const picked = isOther || item !== undefined;
    const showAmount =
        isOther ||
        (item !== undefined &&
            (item.allowOverride || item.defaultAmount === null));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            unit_id: folio.postUnits.length > 1 ? values.unit_id : '',
            charge_item_id: isOther ? '' : values.charge_item_id,
            transaction_code_id: isOther ? values.transaction_code_id : '',
            description: isOther ? values.description : '',
            quantity: values.quantity,
            unit_amount:
                !isOther && !showAmount
                    ? (item?.defaultAmount ?? '')
                    : values.unit_amount,
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
                onSuccess: onDone,
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <FormError errors={errors} />
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
                            Routing rules decide which folio receives the
                            charge.
                        </FieldDescription>
                        <FieldError>{errors.unit_id}</FieldError>
                    </Field>
                )}
                <Field data-invalid={!!errors.charge_item_id}>
                    <FieldLabel htmlFor="charge-choice">Charge</FieldLabel>
                    <EnumSelect
                        id="charge-choice"
                        options={[
                            ...chargeItems.map((one) => ({
                                value: String(one.id),
                                label: one.name,
                            })),
                            { value: OTHER, label: 'Other amount' },
                        ]}
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
                {isOther && (
                    <>
                        <Field data-invalid={!!errors.transaction_code_id}>
                            <FieldLabel htmlFor="charge-code">Code</FieldLabel>
                            <EnumSelect
                                id="charge-code"
                                options={chargeCodes.map((one) => ({
                                    value: String(one.id),
                                    label: `${one.code} · ${one.name}`,
                                }))}
                                value={data.transaction_code_id}
                                onValueChange={(value) =>
                                    form.setData('transaction_code_id', value)
                                }
                                aria-invalid={!!errors.transaction_code_id}
                            />
                            <FieldError>
                                {errors.transaction_code_id}
                            </FieldError>
                        </Field>
                        <Field data-invalid={!!errors.description}>
                            <FieldLabel htmlFor="charge-description">
                                Description
                            </FieldLabel>
                            <Input
                                id="charge-description"
                                maxLength={120}
                                value={data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={!!errors.description}
                            />
                            <FieldError>{errors.description}</FieldError>
                        </Field>
                    </>
                )}
                {picked && (
                    <Field data-invalid={!!errors.quantity}>
                        <FieldLabel htmlFor="charge-quantity">
                            Quantity
                        </FieldLabel>
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
                            onChange={(value) =>
                                form.setData('unit_amount', value)
                            }
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
            </FieldGroup>
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="submit"
                    disabled={form.processing || !picked}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Post charge
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * Posts a charge to a folio. A Unit folio uses its own unit. A Master or Third party folio picks the unit, and routing
 * rules decide the folio. A refusal shows in the Dialog.
 */
export function PostChargeDialog({
    open,
    onOpenChange,
    reservationId,
    folio,
    chargeItems,
    chargeCodes,
    currency,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    folio: Folio;
    chargeItems?: ChargeItem[];
    chargeCodes?: ChargeCode[];
    currency: App.Enums.Currency | null;
}) {
    const loaded = chargeItems !== undefined && chargeCodes !== undefined;

    useFormOptions(open, loaded, ['chargeItems', 'chargeCodes']);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Post charge</DialogTitle>
                    <DialogDescription>
                        Add a charge from the catalogue, or any amount with a
                        charge code.
                    </DialogDescription>
                </DialogHeader>
                {!loaded ? (
                    <div className="flex justify-center py-6">
                        <Spinner />
                    </div>
                ) : (
                    <PostChargeForm
                        reservationId={reservationId}
                        folio={folio}
                        chargeItems={chargeItems}
                        chargeCodes={chargeCodes}
                        currency={currency}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
