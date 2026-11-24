import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    index as diningIndex,
    store,
    update,
} from '@/routes/settings/pos/dining-options';

type Option = {
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

type Props = {
    outlets: EnumOption[];
    outletId: number | null;
    kinds: EnumOption[];
    options: Option[];
};

export default function PosDiningOptions({
    outlets,
    outletId,
    kinds,
    options,
}: Props) {
    const [editing, setEditing] = useState<{ row: Option | null } | null>(null);

    return (
        <PosSettingsShell activeTab="dining">
            <div className="flex flex-col gap-6">
                {outlets.length > 1 && (
                    <EnumSelect
                        options={outlets}
                        value={String(outletId ?? '')}
                        onValueChange={(value) =>
                            router.get(
                                diningIndex.url(undefined, {
                                    query: { outlet: value },
                                }),
                            )
                        }
                        aria-label="Outlet"
                    />
                )}
                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="flex flex-col gap-1">
                            <CardTitle>Dining options</CardTitle>
                            <CardDescription>
                                Dine-in, takeaway and room service, each with
                                its own service charge and packaging fee.
                            </CardDescription>
                        </div>
                        <Button
                            disabled={outletId === null}
                            onClick={() => setEditing({ row: null })}
                        >
                            <Plus data-icon="inline-start" />
                            Add option
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Service charge</TableHead>
                                    <TableHead>Packaging</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="w-20">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {options.map((option) => (
                                    <TableRow key={option.id}>
                                        <TableCell className="font-medium">
                                            {option.name}{' '}
                                            {option.isDefault && (
                                                <Badge variant="secondary">
                                                    Default
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {option.applyServiceCharge
                                                ? `${option.serviceChargeRate}%`
                                                : 'None'}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {option.packagingFee}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="secondary">
                                                {option.active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setEditing({ row: option })
                                                }
                                            >
                                                Edit
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
            {editing && outletId !== null && (
                <OptionForm
                    key={editing.row?.id ?? 'new'}
                    row={editing.row}
                    outletId={outletId}
                    kinds={kinds}
                    onClose={() => setEditing(null)}
                />
            )}
        </PosSettingsShell>
    );
}

function OptionForm({
    row,
    outletId,
    kinds,
    onClose,
}: {
    row: Option | null;
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
            variant="dialog"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? 'Edit dining option' : 'Add dining option'}
            errors={form.errors}
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
                    autoFocus
                />
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
                </Field>
            )}
            <Field>
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

PosDiningOptions.layout = {
    breadcrumbs: posSettingsBreadcrumbs('dining'),
};
