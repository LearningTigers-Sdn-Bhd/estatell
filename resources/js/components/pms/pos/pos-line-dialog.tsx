import { useMemo, useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import type { PosCurrency, PosItem } from './pos-types';

export type LineChoice = {
    variant_id: number;
    quantity: number;
    modifier_option_ids: number[];
    note: string;
    unit_price: string;
    cover_with_entitlement: boolean;
    weight?: string;
    combo_selections?: Record<number, number[]>;
};

/**
 * Choose the size, the add-ons and the quantity of one item. The server checks min and max picks and prices the line.
 */
export function PosLineDialog({
    item,
    currency,
    canCover,
    onClose,
    onAdd,
}: {
    item: PosItem | null;
    currency: PosCurrency;
    canCover: boolean;
    onClose: () => void;
    onAdd: (choice: LineChoice) => void;
}) {
    return (
        <FormOverlay
            variant="dialog"
            open={item !== null}
            onOpenChange={(open) => !open && onClose()}
            title={item?.name ?? ''}
            description={item?.description ?? undefined}
            errors={{}}
            processing={false}
            submitLabel="Add to order"
            onSubmit={() => undefined}
            footer={<span />}
        >
            {item !== null && (
                <LineFields
                    key={item.id}
                    item={item}
                    currency={currency}
                    canCover={canCover}
                    onAdd={onAdd}
                />
            )}
        </FormOverlay>
    );
}

function LineFields({
    item,
    currency,
    canCover,
    onAdd,
}: {
    item: PosItem;
    currency: PosCurrency;
    canCover: boolean;
    onAdd: (choice: LineChoice) => void;
}) {
    const [variantId, setVariantId] = useState(item.variants[0]?.id ?? 0);
    const [quantity, setQuantity] = useState(1);
    const [note, setNote] = useState('');
    const [options, setOptions] = useState<number[]>([]);
    const [cover, setCover] = useState(false);
    const [weight, setWeight] = useState('');
    const [picks, setPicks] = useState<Record<number, number[]>>({});
    const variant = useMemo(
        () => item.variants.find((row) => row.id === variantId),
        [item.variants, variantId],
    );
    const needsPrice = variant?.pricingType !== 'fixed';
    const [price, setPrice] = useState('');

    const missing = item.modifierGroups.some(
        (group) =>
            options.filter((id) =>
                group.options.some((option) => option.id === id),
            ).length < group.minSelect,
    );

    const comboMissing = item.comboSlots.some(
        (slot) => (picks[slot.id]?.length ?? 0) < slot.min,
    );
    const weightMissing = item.soldByWeight && !(Number(weight) > 0);

    function pickCombo(slotId: number, variantId: number, max: number) {
        setPicks((current) => {
            const chosen = current[slotId] ?? [];

            if (chosen.includes(variantId)) {
                return {
                    ...current,
                    [slotId]: chosen.filter((id) => id !== variantId),
                };
            }

            return max === 1
                ? { ...current, [slotId]: [variantId] }
                : chosen.length >= max
                  ? current
                  : { ...current, [slotId]: [...chosen, variantId] };
        });
    }

    function toggle(groupId: number, optionId: number, max: number | null) {
        setOptions((current) => {
            if (current.includes(optionId)) {
                return current.filter((id) => id !== optionId);
            }

            const group = item.modifierGroups.find((row) => row.id === groupId);
            const picked = current.filter((id) =>
                group?.options.some((option) => option.id === id),
            );

            return max !== null && picked.length >= max
                ? current
                : [...current, optionId];
        });
    }

    return (
        <div className="flex flex-col gap-4">
            {item.variants.length > 1 && (
                <Field>
                    <FieldLabel>Size</FieldLabel>
                    <RadioGroup
                        value={String(variantId)}
                        onValueChange={(value) => setVariantId(Number(value))}
                    >
                        {item.variants.map((row) => (
                            <label
                                key={row.id}
                                className="flex items-center gap-3 rounded-lg border p-3"
                            >
                                <RadioGroupItem
                                    value={String(row.id)}
                                    disabled={row.soldOut}
                                />
                                <span className="flex-1">{row.name}</span>
                                <MoneyText
                                    amount={row.price}
                                    currency={currency}
                                />
                            </label>
                        ))}
                    </RadioGroup>
                </Field>
            )}
            {item.soldByWeight && (
                <Field>
                    <FieldLabel htmlFor="line-weight">
                        Weight ({item.weightUnit})
                    </FieldLabel>
                    <FieldDescription>
                        The price is for {Number(item.weightUnitDivisor)}{' '}
                        {item.weightUnit}.
                    </FieldDescription>
                    <Input
                        id="line-weight"
                        inputMode="decimal"
                        className="tabular-nums"
                        value={weight}
                        onChange={(event) => setWeight(event.target.value)}
                    />
                </Field>
            )}
            {item.comboSlots.map((slot) => (
                <Field key={slot.id}>
                    <FieldLabel>{slot.name}</FieldLabel>
                    <FieldDescription>
                        {slot.min === slot.max
                            ? `Pick ${slot.max}.`
                            : `Pick ${slot.min} to ${slot.max}.`}
                    </FieldDescription>
                    <div className="flex flex-col gap-2">
                        {slot.options.map((option) => (
                            <label
                                key={option.variantId}
                                className="flex items-center gap-3 rounded-lg border p-3"
                            >
                                <Checkbox
                                    checked={(picks[slot.id] ?? []).includes(
                                        option.variantId,
                                    )}
                                    onCheckedChange={() =>
                                        pickCombo(
                                            slot.id,
                                            option.variantId,
                                            slot.max,
                                        )
                                    }
                                />
                                <span className="flex-1">{option.name}</span>
                                {Number(option.upcharge) !== 0 && (
                                    <MoneyText
                                        amount={option.upcharge}
                                        currency={currency}
                                    />
                                )}
                            </label>
                        ))}
                    </div>
                </Field>
            ))}
            {needsPrice && (
                <Field>
                    <FieldLabel htmlFor="line-price">Price</FieldLabel>
                    <Input
                        id="line-price"
                        inputMode="decimal"
                        className="tabular-nums"
                        value={price}
                        onChange={(event) => setPrice(event.target.value)}
                    />
                </Field>
            )}
            {item.modifierGroups.map((group) => (
                <Field key={group.id}>
                    <FieldLabel>{group.name}</FieldLabel>
                    <FieldDescription>
                        {group.minSelect > 0
                            ? `Pick at least ${group.minSelect}. `
                            : 'Optional. '}
                        {group.maxSelect !== null &&
                            `Up to ${group.maxSelect}. `}
                        {group.freeIncludedCount > 0 &&
                            `${group.freeIncludedCount} included free.`}
                    </FieldDescription>
                    <div className="flex flex-col gap-2">
                        {group.options.map((option) => (
                            <label
                                key={option.id}
                                className="flex items-center gap-3 rounded-lg border p-3"
                            >
                                <Checkbox
                                    checked={options.includes(option.id)}
                                    onCheckedChange={() =>
                                        toggle(
                                            group.id,
                                            option.id,
                                            group.maxSelect,
                                        )
                                    }
                                />
                                <span className="flex-1">{option.name}</span>
                                {Number(option.priceDelta) !== 0 && (
                                    <MoneyText
                                        amount={option.priceDelta}
                                        currency={currency}
                                    />
                                )}
                            </label>
                        ))}
                    </div>
                </Field>
            ))}
            <div className="grid grid-cols-2 gap-3">
                <Field className={item.soldByWeight ? 'hidden' : undefined}>
                    <FieldLabel htmlFor="line-qty">Quantity</FieldLabel>
                    <Input
                        id="line-qty"
                        type="number"
                        min={1}
                        max={999}
                        className="tabular-nums"
                        value={quantity}
                        onChange={(event) =>
                            setQuantity(
                                Math.max(1, Number(event.target.value) || 1),
                            )
                        }
                    />
                </Field>
                <Field>
                    <FieldLabel htmlFor="line-note">Note</FieldLabel>
                    <Input
                        id="line-note"
                        maxLength={255}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                        placeholder="No ice"
                    />
                </Field>
            </div>
            {canCover && (
                <label className="flex items-center gap-3">
                    <Checkbox
                        checked={cover}
                        onCheckedChange={(value) => setCover(value === true)}
                    />
                    <span>Cover with the guest&apos;s included meal</span>
                </label>
            )}
            <Button
                type="button"
                className="h-11"
                disabled={
                    missing ||
                    comboMissing ||
                    weightMissing ||
                    variant === undefined ||
                    (needsPrice && price === '')
                }
                onClick={() =>
                    onAdd({
                        variant_id: variantId,
                        quantity,
                        modifier_option_ids: options,
                        note,
                        unit_price: needsPrice ? price : '',
                        cover_with_entitlement: cover,
                        weight: item.soldByWeight ? weight : undefined,
                        combo_selections: item.isCombo ? picks : undefined,
                    })
                }
            >
                Add to order
            </Button>
        </div>
    );
}
