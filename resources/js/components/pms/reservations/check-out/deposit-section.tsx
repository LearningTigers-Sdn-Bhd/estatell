import { MoneyInput } from '@/components/pms/money-input';
import { formatMoney, MoneyText } from '@/components/pms/money-text';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import type { CheckOutState } from './use-check-out-form';

/**
 * The security deposit (or credit) block of the check-out Dialog: use it toward the bill, keep part of it for damages
 * and refund the rest. Renders nothing when the user cannot refund.
 */
export function DepositSection({
    state,
    currency,
}: {
    state: CheckOutState;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const { form, preview, figures } = state;
    const { data, errors } = form;
    const {
        held,
        owing,
        deposit,
        damaging,
        damageItem,
        depositUsed,
        creditAbove,
        refundParts,
        refundAmount,
    } = figures;

    if (preview === null || !figures.canRefund) {
        return null;
    }

    return (
        <FieldSet>
            <FieldLegend variant="label">
                {held > 0 ? 'Security deposit' : 'Credit'}
            </FieldLegend>
            {depositUsed && (
                <p className="text-sm text-muted-foreground">
                    {formatMoney(held.toFixed(2), currency)}{' '}
                    {damaging ? 'kept for damages.' : 'used toward the bill.'}{' '}
                    Nothing to refund.
                </p>
            )}
            {held > 0 && owing > 0 && !damaging && (
                <Field orientation="horizontal">
                    <Checkbox
                        id="check-out-use-deposit"
                        checked={deposit === 'use'}
                        onCheckedChange={(checked) =>
                            form.setData('deposit', checked ? 'use' : 'refund')
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="check-out-use-deposit">
                            Use {formatMoney(held.toFixed(2), currency)} toward
                            the bill
                        </FieldLabel>
                    </FieldContent>
                </Field>
            )}
            {held > 0 && damageItem !== null && (
                <>
                    <Field orientation="horizontal">
                        <Checkbox
                            id="check-out-damage"
                            checked={data.keep_damage}
                            onCheckedChange={(checked) =>
                                form.setData((current) => ({
                                    ...current,
                                    keep_damage: checked,
                                    damage_amount:
                                        current.damage_amount ||
                                        (damageItem.defaultAmount ?? ''),
                                }))
                            }
                        />
                        <FieldLabel htmlFor="check-out-damage">
                            Keep part of it for damages
                        </FieldLabel>
                    </Field>
                    {damaging && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field data-invalid={!!errors.damage_amount}>
                                <FieldLabel htmlFor="check-out-damage_amount">
                                    Damage
                                </FieldLabel>
                                <MoneyInput
                                    id="check-out-damage_amount"
                                    value={data.damage_amount}
                                    onChange={(value) =>
                                        form.setData('damage_amount', value)
                                    }
                                    currency={currency}
                                    placeholder="e.g. 50.00"
                                    invalid={!!errors.damage_amount}
                                />
                                <FieldDescription>
                                    Posts to {damageItem.name}, plus its tax.
                                </FieldDescription>
                                <FieldError>{errors.damage_amount}</FieldError>
                            </Field>
                            <Field data-invalid={!!errors.damage_note}>
                                <FieldLabel htmlFor="check-out-damage_note">
                                    Note
                                </FieldLabel>
                                <Input
                                    id="check-out-damage_note"
                                    value={data.damage_note}
                                    onChange={(event) =>
                                        form.setData(
                                            'damage_note',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={100}
                                    placeholder="e.g. Broken lamp"
                                    autoComplete="off"
                                    aria-invalid={!!errors.damage_note}
                                />
                                <FieldError>{errors.damage_note}</FieldError>
                            </Field>
                        </div>
                    )}
                </>
            )}
            {deposit === 'refund' && !depositUsed && (
                <div className="flex flex-col gap-2 text-sm">
                    <span className="text-muted-foreground">
                        {creditAbove ? 'Refund credit' : 'Refund deposit'}
                        {preview.depositMethod
                            ? ` · ${preview.depositMethod}`
                            : ''}
                    </span>
                    {creditAbove ? (
                        <div className="flex flex-col gap-1">
                            {refundParts.map((part, index) => (
                                <div
                                    key={index}
                                    className="flex justify-between gap-4"
                                >
                                    <span className="text-muted-foreground">
                                        {part.purpose} · {part.method}
                                        {part.reference
                                            ? ` ref ${part.reference}`
                                            : ''}
                                    </span>
                                    <MoneyText
                                        amount={part.amount}
                                        currency={currency}
                                    />
                                </div>
                            ))}
                            <FieldError>{errors.refund_amount}</FieldError>
                        </div>
                    ) : (
                        <Field data-invalid={!!errors.refund_amount}>
                            <MoneyInput
                                id="check-out-refund_amount"
                                value={refundAmount}
                                onChange={(value) =>
                                    form.setData('refund_amount', value)
                                }
                                currency={currency}
                                invalid={!!errors.refund_amount}
                            />
                            <FieldError>{errors.refund_amount}</FieldError>
                        </Field>
                    )}
                </div>
            )}
        </FieldSet>
    );
}
