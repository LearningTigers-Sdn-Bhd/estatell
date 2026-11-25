import { router } from '@inertiajs/react';
import { Plus, Utensils } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PosDiningOptionForm } from '@/components/pms/pos/pos-dining-option-form';
import type { DiningOptionRow } from '@/components/pms/pos/pos-dining-option-form';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as diningIndex } from '@/routes/settings/pos/dining-options';

type Props = {
    currency: App.Data.Property.CurrencyData | null;
    outlets: EnumOption[];
    outletId: number | null;
    kinds: EnumOption[];
    options: DiningOptionRow[];
};

export default function PosDiningOptions({
    currency,
    outlets,
    outletId,
    kinds,
    options,
}: Props) {
    const [editing, setEditing] = useState<{
        row: DiningOptionRow | null;
    } | null>(null);

    return (
        <PosSettingsShell activeTab="dining">
            <div className="flex flex-col gap-6">
                {outlets.length > 1 && (
                    <EnumSelect
                        name="outlet"
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
                        {options.length === 0 ? (
                            <EmptyState
                                icon={Utensils}
                                title="No dining options yet"
                                description="Add Dine-in, Takeaway, or Room service for this outlet."
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Service charge</TableHead>
                                        <TableHead className="text-right">
                                            Packaging ({currency?.code ?? 'MYR'}
                                            )
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-20">
                                            <span className="sr-only">
                                                Actions
                                            </span>
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
                                                    ? `${option.serviceChargeRate}%${option.serviceChargeTaxable ? ' (taxable)' : ''}`
                                                    : 'None'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                <MoneyText
                                                    amount={option.packagingFee}
                                                    currency={currency}
                                                    plain
                                                />
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
                                                        setEditing({
                                                            row: option,
                                                        })
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
            {editing && outletId !== null && (
                <PosDiningOptionForm
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

PosDiningOptions.layout = {
    breadcrumbs: posSettingsBreadcrumbs('dining'),
};
