import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { PosApprovalFields, noApproval } from './pos-approval-fields';
import type { PosPaymentMethod, PosStaff } from './pos-types';
import { unlessRefused } from './pos-unless-refused';

type Order = App.Data.Pos.PosOrderData;

/**
 * Fix a tender keyed wrongly on a paid order: swap one payment for another tender of the same amount. Only works while the
 * shift and business date are still open; the server says why when it is not. A manager approves it.
 */
export function PosChangeTenderDialog({
    open,
    onClose,
    order,
    tenders,
    methods = [],
    url,
    staff,
    requiresApproval = true,
}: {
    open: boolean;
    onClose: () => void;
    order: Order;
    tenders: EnumOption[];
    methods?: PosPaymentMethod[];
    url: string;
    /** At the register a manager approves with their PIN; in the back office the signed-in manager is the approver. */
    staff?: PosStaff[];
    requiresApproval?: boolean;
}) {
    const payments = order.payments.filter(
        (payment) =>
            payment.direction === 'in' &&
            payment.status === 'captured' &&
            payment.tenderType !== 'room_charge',
    );
    const form = useForm({
        payment_id: String(payments[0]?.id ?? ''),
        tender_type: '',
        payment_method_id: '',
        reference: '',
        ...noApproval,
    });
    const matchingMethods = methods.filter(
        (method) => method.tenderType === form.data.tender_type,
    );

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title="Change how this was paid"
            description="The old payment is kept and marked replaced. Only possible while the shift and business date are still open."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Change tender"
            submitDisabled={
                form.data.payment_id === '' || form.data.tender_type === ''
            }
            onSubmit={() => {
                form.transform((data) => ({
                    ...data,
                    payment_method_id:
                        data.payment_method_id === ''
                            ? null
                            : Number(data.payment_method_id),
                }));
                form.post(url, {
                    preserveScroll: true,
                    onSuccess: unlessRefused(onClose),
                });
            }}
        >
            <Field data-invalid={!!form.errors.payment_id}>
                <FieldLabel htmlFor="swap-payment">Payment</FieldLabel>
                <EnumSelect
                    id="swap-payment"
                    name="payment_id"
                    options={payments.map((payment) => ({
                        value: String(payment.id),
                        label: `${payment.tender} ${payment.amount}`,
                    }))}
                    value={form.data.payment_id}
                    onValueChange={(value) => form.setData('payment_id', value)}
                />
                <FieldError>{form.errors.payment_id}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.tender_type}>
                <FieldLabel htmlFor="swap-tender">New tender</FieldLabel>
                <EnumSelect
                    id="swap-tender"
                    name="tender_type"
                    options={tenders}
                    value={form.data.tender_type}
                    onValueChange={(value) =>
                        form.setData((data) => ({
                            ...data,
                            tender_type: value,
                            payment_method_id: '',
                        }))
                    }
                    placeholder="Pick a tender"
                />
                <FieldError>{form.errors.tender_type}</FieldError>
            </Field>
            {matchingMethods.length > 1 && (
                <Field data-invalid={!!form.errors.payment_method_id}>
                    <FieldLabel htmlFor="swap-method">
                        Payment method
                    </FieldLabel>
                    <EnumSelect
                        id="swap-method"
                        name="payment_method_id"
                        options={matchingMethods.map((method) => ({
                            value: String(method.paymentMethodId),
                            label: method.name,
                        }))}
                        value={form.data.payment_method_id}
                        onValueChange={(value) =>
                            form.setData('payment_method_id', value)
                        }
                        placeholder="Pick a payment method"
                    />
                    <FieldError>{form.errors.payment_method_id}</FieldError>
                </Field>
            )}
            <Field>
                <FieldLabel htmlFor="swap-reference">Reference</FieldLabel>
                <FieldDescription>
                    Card approval or wallet reference, if any.
                </FieldDescription>
                <Input
                    id="swap-reference"
                    maxLength={120}
                    value={form.data.reference}
                    onChange={(event) =>
                        form.setData('reference', event.target.value)
                    }
                />
            </Field>
            {staff !== undefined && (
                <PosApprovalFields
                    required={requiresApproval}
                    staff={staff}
                    data={{
                        approver_id: form.data.approver_id,
                        approver_pin: form.data.approver_pin,
                    }}
                    onChange={(next) =>
                        form.setData((data) => ({ ...data, ...next }))
                    }
                />
            )}
        </FormOverlay>
    );
}
