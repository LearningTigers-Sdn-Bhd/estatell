import { useForm } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';
import { useMemo } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { store, update } from '@/routes/settings/pos/dining-options';
import { unlessRefused } from './pos-unless-refused';

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

const KIND_DESCRIPTIONS: Record<string, string> = {
    dine_in: 'Seated dining with optional floor plan table assignment.',
    takeaway: 'Counter pickup with no table required.',
    room_service: 'Delivered to an in-house guest room.',
    poolside: 'Outdoor or pool deck service.',
    minibar: 'In-room minibar consumption.',
    delivery: 'Off-property delivery order.',
};

function summarizeFees(
    data: {
        apply_service_charge: boolean;
        service_charge_rate: string;
        service_charge_taxable: boolean;
        packaging_fee: string;
    },
    currency: App.Data.Property.CurrencyData | null,
): string {
    const parts: string[] = [];
    const rate = Number(data.service_charge_rate) || 0;
    const packaging = Number(data.packaging_fee) || 0;
    const currencyCode = currency?.code ?? 'MYR';

    if (data.apply_service_charge && rate > 0) {
        parts.push(
            `${data.service_charge_rate}% service charge${data.service_charge_taxable ? ' (taxable)' : ''}`,
        );
    }

    if (packaging > 0) {
        parts.push(`${currencyCode} ${data.packaging_fee} packaging fee`);
    }

    if (parts.length === 0) {
        return 'Orders using this option have no service charge or packaging fee.';
    }

    return `Orders using this option add ${parts.join(' and ')}.`;
}

export function PosDiningOptionForm({
    row,
    currency,
    outletId,
    hasServiceChargeCode = true,
    kinds,
    onClose,
}: {
    row: DiningOptionRow | null;
    currency: App.Data.Property.CurrencyData | null;
    outletId: number;
    hasServiceChargeCode?: boolean;
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

    const kindOptions = useMemo<EnumOption[]>(
        () =>
            kinds.map((kind) => ({
                ...kind,
                description: KIND_DESCRIPTIONS[kind.value],
            })),
        [kinds],
    );

    return (
        <FormOverlay
            variant="sheet"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? 'Edit dining option' : 'Add dining option'}
            description="Configure how orders using this option handle service charge, packaging fees, and register defaults."
            errors={form.errors}
            dirty={form.isDirty}
            discardTitle={
                row
                    ? 'Discard changes to this dining option?'
                    : 'Discard this new dining option?'
            }
            processing={form.processing}
            submitLabel={row ? 'Save dining option' : 'Create dining option'}
            onSubmit={() => {
                const options = {
                    preserveScroll: true,
                    onSuccess: unlessRefused(onClose),
                };

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
            <FieldSet>
                <FieldLegend>Option details</FieldLegend>
                <Field data-invalid={!!form.errors.name}>
                    <FieldLabel htmlFor="dining-name">Option name</FieldLabel>
                    <FieldDescription>
                        Shown on the POS register and printed on guest receipts.
                    </FieldDescription>
                    <Input
                        id="dining-name"
                        maxLength={60}
                        placeholder="e.g. Dine-in, Takeaway, Room service"
                        required
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                        autoFocus
                    />
                    <FieldError>{form.errors.name}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.kind}>
                    <FieldLabel htmlFor="dining-kind">Service type</FieldLabel>
                    <FieldDescription>
                        Controls order behavior such as table assignment or room
                        delivery.
                    </FieldDescription>
                    <EnumSelect
                        id="dining-kind"
                        name="kind"
                        options={kindOptions}
                        value={form.data.kind}
                        onValueChange={(value) => form.setData('kind', value)}
                        aria-invalid={!!form.errors.kind}
                    />
                    <FieldError>{form.errors.kind}</FieldError>
                </Field>
            </FieldSet>

            <Separator />

            <FieldSet>
                <FieldLegend>Charges and fees</FieldLegend>

                <Field
                    orientation="horizontal"
                    className="items-start rounded-lg border bg-muted/20 p-3"
                >
                    <Switch
                        id="dining-sc"
                        checked={form.data.apply_service_charge}
                        onCheckedChange={(checked) =>
                            form.setData('apply_service_charge', checked)
                        }
                        className="mt-0.5"
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="dining-sc">
                            Add service charge
                        </FieldLabel>
                        <FieldDescription>
                            Apply a percentage service charge to eligible items
                            on orders with this dining option.
                        </FieldDescription>
                    </FieldContent>
                </Field>

                {form.data.apply_service_charge && !hasServiceChargeCode && (
                    <Alert>
                        <AlertCircle aria-hidden />
                        <AlertDescription>
                            This outlet does not have a service charge account
                            code configured yet. Set one under Setup &gt;
                            Registers &amp; accounts before saving a service
                            charge.
                        </AlertDescription>
                    </Alert>
                )}

                {form.data.apply_service_charge && (
                    <div className="flex flex-col gap-3 rounded-lg border bg-muted/10 p-3">
                        <Field data-invalid={!!form.errors.service_charge_rate}>
                            <FieldLabel htmlFor="dining-rate">
                                Service charge rate
                            </FieldLabel>
                            <InputGroup>
                                <InputGroupInput
                                    id="dining-rate"
                                    inputMode="decimal"
                                    className="tabular-nums"
                                    placeholder="10.00"
                                    value={form.data.service_charge_rate}
                                    onChange={(event) =>
                                        form.setData(
                                            'service_charge_rate',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        !!form.errors.service_charge_rate
                                    }
                                />
                                <InputGroupAddon align="inline-end">
                                    <InputGroupText>%</InputGroupText>
                                </InputGroupAddon>
                            </InputGroup>
                            <FieldError>
                                {form.errors.service_charge_rate}
                            </FieldError>
                        </Field>
                        <Field
                            orientation="horizontal"
                            className="items-start rounded-lg border bg-card p-3"
                        >
                            <Switch
                                id="dining-sc-taxable"
                                checked={form.data.service_charge_taxable}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'service_charge_taxable',
                                        checked,
                                    )
                                }
                                className="mt-0.5"
                            />
                            <FieldContent>
                                <FieldLabel htmlFor="dining-sc-taxable">
                                    Service charge is subject to tax
                                </FieldLabel>
                                <FieldDescription>
                                    Include the service charge amount when
                                    calculating sales or service tax.
                                </FieldDescription>
                            </FieldContent>
                        </Field>
                    </div>
                )}

                <Field data-invalid={!!form.errors.packaging_fee}>
                    <FieldLabel htmlFor="dining-pack">Packaging fee</FieldLabel>
                    <FieldDescription>
                        Flat fee added once per order (for takeaway containers
                        or delivery bags). Enter 0.00 for none.
                    </FieldDescription>
                    <MoneyInput
                        id="dining-pack"
                        currency={currency}
                        value={form.data.packaging_fee}
                        invalid={!!form.errors.packaging_fee}
                        onChange={(value) =>
                            form.setData('packaging_fee', value)
                        }
                    />
                    <FieldError>{form.errors.packaging_fee}</FieldError>
                </Field>

                <p className="rounded-lg border bg-muted/20 px-3 py-2 text-xs text-muted-foreground tabular-nums">
                    {summarizeFees(form.data, currency)}
                </p>
            </FieldSet>

            <Separator />

            <FieldSet>
                <FieldLegend>Register visibility</FieldLegend>
                <Field
                    orientation="horizontal"
                    className="items-start rounded-lg border bg-muted/20 p-3"
                >
                    <Switch
                        id="dining-default"
                        checked={form.data.is_default}
                        onCheckedChange={(checked) =>
                            form.setData('is_default', checked)
                        }
                        className="mt-0.5"
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="dining-default">
                            Default for new orders
                        </FieldLabel>
                        <FieldDescription>
                            Pre-selected automatically whenever a cashier starts
                            a new order. Replaces any existing default on this
                            outlet.
                        </FieldDescription>
                    </FieldContent>
                </Field>
                <Field
                    orientation="horizontal"
                    className="items-start rounded-lg border bg-muted/20 p-3"
                >
                    <Switch
                        id="dining-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                        className="mt-0.5"
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="dining-active">
                            Active on register
                        </FieldLabel>
                        <FieldDescription>
                            Turn off to hide this dining option from cashiers
                            without removing past orders.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            </FieldSet>
        </FormOverlay>
    );
}
