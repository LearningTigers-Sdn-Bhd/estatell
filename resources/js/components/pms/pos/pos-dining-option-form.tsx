import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { store, update } from '@/routes/settings/pos/dining-options';

export type DiningOptionRow = {
    id: number;
    name: string;
    kind: string;
    applyServiceCharge: boolean;
    serviceChargeRate: string;
    serviceChargeTaxable: boolean;
    packagingFee: string;
    isDefault: boolean;
    active: boolean;
};

export function PosDiningOptionForm({
    row,
    outletId,
    kinds,
    onClose,
}: {
    row: DiningOptionRow | null;
    outletId: number;
    kinds: EnumOption[];
    onClose: () => void;
}) {
    const form = useForm({
        name: row?.name ?? '',
        kind: row?.kind ?? 'dine_in',
        apply_service_charge: row?.applyServiceCharge ?? true,
        service_charge_rate: row?.serviceChargeRate ?? '10.00',
        service_charge_taxable: row?.serviceChargeTaxable ?? false,
        packaging_fee: row?.packagingFee ?? '0.00',
        is_default: row?.isDefault ?? false,
        active: row?.active ?? true,
    });

    return (
        <FormOverlay
            variant="sheet"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? 'Edit dining option' : 'Add dining option'}
            errors={form.errors}
            dirty={form.isDirty}
            processing={form.processing}
            submitLabel="Save option"
            onSubmit={() => {
                const options = { preserveScroll: true, onSuccess: onClose };

                if (row) {
                    form.put(
                        update.url({ outlet: outletId, option: row.id }),
                        options,
                    );
                } else {
                    form.post(store.url({ outlet: outletId }), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="dining-name">Name</FieldLabel>
                <Input
                    id="dining-name"
                    maxLength={60}
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={!!form.errors.name}
                    autoFocus
                />
                <FieldError>{form.errors.name}</FieldError>
            </Field>
            <Field>
                <FieldLabel htmlFor="dining-kind">Kind</FieldLabel>
                <EnumSelect
                    id="dining-kind"
                    name="kind"
                    options={kinds}
                    value={form.data.kind}
                    onValueChange={(value) => form.setData('kind', value)}
                />
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="dining-sc"
                    checked={form.data.apply_service_charge}
                    onCheckedChange={(checked) =>
                        form.setData('apply_service_charge', checked)
                    }
                />
                <FieldLabel htmlFor="dining-sc">Add service charge</FieldLabel>
            </Field>
            {form.data.apply_service_charge && (
                <>
                    <Field data-invalid={!!form.errors.service_charge_rate}>
                        <FieldLabel htmlFor="dining-rate">
                            Service charge %
                        </FieldLabel>
                        <Input
                            id="dining-rate"
                            inputMode="decimal"
                            className="tabular-nums"
                            value={form.data.service_charge_rate}
                            onChange={(event) =>
                                form.setData(
                                    'service_charge_rate',
                                    event.target.value,
                                )
                            }
                        />
                        <FieldError>
                            {form.errors.service_charge_rate}
                        </FieldError>
                    </Field>
                    <Field orientation="horizontal">
                        <Switch
                            id="dining-sc-taxable"
                            checked={form.data.service_charge_taxable}
                            onCheckedChange={(checked) =>
                                form.setData('service_charge_taxable', checked)
                            }
                        />
                        <FieldLabel htmlFor="dining-sc-taxable">
                            Service charge is subject to tax
                        </FieldLabel>
                    </Field>
                </>
            )}
            <Field data-invalid={!!form.errors.packaging_fee}>
                <FieldLabel htmlFor="dining-pack">Packaging fee</FieldLabel>
                <Input
                    id="dining-pack"
                    inputMode="decimal"
                    className="tabular-nums"
                    value={form.data.packaging_fee}
                    onChange={(event) =>
                        form.setData('packaging_fee', event.target.value)
                    }
                />
                <FieldError>{form.errors.packaging_fee}</FieldError>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="dining-default"
                    checked={form.data.is_default}
                    onCheckedChange={(checked) =>
                        form.setData('is_default', checked)
                    }
                />
                <FieldLabel htmlFor="dining-default">
                    Default for new orders
                </FieldLabel>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="dining-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
                <FieldLabel htmlFor="dining-active">Active</FieldLabel>
            </Field>
        </FormOverlay>
    );
}
