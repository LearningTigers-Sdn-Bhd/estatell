import { EnumSelect } from '@/components/pms/enum-select';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

type Method = App.Data.Payments.PaymentMethodOptionData;

const REFUND_TYPES = ['cash', 'card', 'bank_transfer', 'e_wallet'];

/**
 * The "Refund to" field of a refund Dialog, and the Reason it asks for once another method is picked. Only a user with
 * `payment.refund-other-method` sees it. Cash is greyed out unless every payment being refunded was paid in cash. The
 * server checks all of it again.
 */
export function RefundMethodFields({
    idPrefix,
    methods,
    sourceIsCash,
    methodId,
    reason,
    onMethodId,
    onReason,
    errors,
}: {
    idPrefix: string;
    methods: Method[];
    sourceIsCash: boolean;
    methodId: string;
    reason: string;
    onMethodId: (value: string) => void;
    onReason: (value: string) => void;
    errors: Partial<Record<'payment_method_id' | 'reason', string>>;
}) {
    const options = [
        { value: '', label: 'Original method' },
        ...methods
            .filter(
                (method) =>
                    method.type !== null && REFUND_TYPES.includes(method.type),
            )
            .map((method) => ({
                value: String(method.id),
                label: method.name,
                disabled: method.type === 'cash' && !sourceIsCash,
                description:
                    method.type === 'cash' && !sourceIsCash
                        ? 'Only a cash payment is refunded in cash.'
                        : undefined,
            })),
    ];

    return (
        <>
            <Field data-invalid={!!errors.payment_method_id}>
                <FieldLabel htmlFor={`${idPrefix}-method`}>
                    Refund to
                </FieldLabel>
                <EnumSelect
                    id={`${idPrefix}-method`}
                    options={options}
                    value={methodId}
                    onValueChange={onMethodId}
                    aria-invalid={!!errors.payment_method_id}
                />
                <FieldError>{errors.payment_method_id}</FieldError>
            </Field>
            {methodId !== '' && (
                <Field data-invalid={!!errors.reason}>
                    <FieldLabel htmlFor={`${idPrefix}-reason`}>
                        Reason
                    </FieldLabel>
                    <Input
                        id={`${idPrefix}-reason`}
                        maxLength={255}
                        placeholder="e.g. Guest asked for a bank transfer"
                        value={reason}
                        onChange={(event) => onReason(event.target.value)}
                        aria-invalid={!!errors.reason}
                    />
                    <FieldError>{errors.reason}</FieldError>
                </Field>
            )}
        </>
    );
}
