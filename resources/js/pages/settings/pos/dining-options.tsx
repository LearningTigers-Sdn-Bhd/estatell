import { Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    BedDouble,
    Pencil,
    Plus,
    ShoppingBag,
    Store,
    Truck,
    Utensils,
    Waves,
    Wine,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as posOutletsIndex } from '@/routes/settings/pos-outlets';
import { index as diningIndex } from '@/routes/settings/pos/dining-options';

type Props = {
    currency: App.Data.Property.CurrencyData | null;
    outlets: EnumOption[];
    outletId: number | null;
    hasServiceChargeCode?: boolean;
    kinds: EnumOption[];
    options: DiningOptionRow[];
};

const KIND_ICONS: Record<string, LucideIcon> = {
    dine_in: Utensils,
    takeaway: ShoppingBag,
    room_service: BedDouble,
    poolside: Waves,
    minibar: Wine,
    delivery: Truck,
};

export default function PosDiningOptions({
    currency,
    outlets,
    outletId,
    hasServiceChargeCode = true,
    kinds,
    options,
}: Props) {
    const [editing, setEditing] = useState<{
        row: DiningOptionRow | null;
    } | null>(null);

    const kindLabels = useMemo(
        () => new Map(kinds.map((kind) => [kind.value, kind.label])),
        [kinds],
    );

    const activeOutletLabel =
        outlets.find((outlet) => outlet.value === String(outletId ?? ''))
            ?.label ?? null;
    const activeCount = options.filter((option) => option.active).length;
    const defaultOption = options.find((option) => option.isDefault);

    const headerActions = (
        <div className="flex flex-wrap items-center justify-end gap-2">
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
                    icon={<Store aria-hidden />}
                    aria-label="Outlet"
                    className="w-48"
                />
            )}
            <Button
                type="button"
                className="max-sm:min-h-11"
                disabled={outletId === null}
                onClick={() => setEditing({ row: null })}
            >
                <Plus data-icon="inline-start" />
                Add dining option
            </Button>
        </div>
    );

    return (
        <PosSettingsShell activeTab="dining" actions={headerActions}>
            {outletId === null ? (
                <div className="rounded-xl border bg-card p-6">
                    <EmptyState
                        icon={Store}
                        title="No POS outlet available"
                        description="Enable built-in POS on at least one outlet under Setup to configure dining options."
                        action={
                            <Link
                                href={posOutletsIndex()}
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                Go to Outlets
                            </Link>
                        }
                    />
                </div>
            ) : options.length === 0 ? (
                <div className="rounded-xl border bg-card p-6">
                    <EmptyState
                        icon={Utensils}
                        title="No dining options yet"
                        description="Add options such as Dine-in, Takeaway, or Room service to set service charge and packaging rules for this outlet."
                        action={
                            <Button
                                type="button"
                                className="max-sm:min-h-11"
                                onClick={() => setEditing({ row: null })}
                            >
                                <Plus data-icon="inline-start" />
                                Add your first dining option
                            </Button>
                        }
                    />
                </div>
            ) : (
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
                        <div className="flex flex-wrap items-center gap-2">
                            {activeOutletLabel && (
                                <Badge variant="secondary">
                                    {activeOutletLabel}
                                </Badge>
                            )}
                            <span className="tabular-nums">
                                {activeCount} of {options.length} active
                            </span>
                        </div>
                        {defaultOption && (
                            <span>
                                Default for new orders:{' '}
                                <span className="font-medium text-foreground">
                                    {defaultOption.name}
                                </span>
                            </span>
                        )}
                    </div>

                    {!defaultOption && activeCount > 0 && (
                        <Alert>
                            <AlertCircle aria-hidden />
                            <AlertTitle>
                                No default dining option set
                            </AlertTitle>
                            <AlertDescription>
                                Edit an active option below and turn on
                                &quot;Default for new orders&quot; so cashiers
                                do not have to pick one manually on every check.
                            </AlertDescription>
                        </Alert>
                    )}

                    <div className="overflow-hidden rounded-xl border bg-card">
                        <div className="hidden md:block">
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead scope="col">
                                            Option
                                        </TableHead>
                                        <TableHead scope="col">
                                            Service type
                                        </TableHead>
                                        <TableHead scope="col">
                                            Service charge
                                        </TableHead>
                                        <TableHead
                                            scope="col"
                                            className="text-right"
                                        >
                                            Packaging ({currency?.code ?? 'MYR'}
                                            )
                                        </TableHead>
                                        <TableHead scope="col">
                                            Status
                                        </TableHead>
                                        <TableHead
                                            scope="col"
                                            className="w-28 text-right"
                                        >
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {options.map((option) => {
                                        const KindIcon =
                                            KIND_ICONS[option.kind] ?? Utensils;
                                        const kindLabel =
                                            kindLabels.get(option.kind) ??
                                            option.kind;

                                        return (
                                            <TableRow key={option.id}>
                                                <TableCell className="font-medium">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span>
                                                            {option.name}
                                                        </span>
                                                        {option.isDefault && (
                                                            <Badge>
                                                                Default
                                                            </Badge>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                                        <KindIcon
                                                            aria-hidden
                                                            className="size-3.5 shrink-0"
                                                        />
                                                        <span className="text-foreground">
                                                            {kindLabel}
                                                        </span>
                                                    </span>
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {option.applyServiceCharge ? (
                                                        <div className="flex flex-wrap items-center gap-1.5">
                                                            <span>
                                                                {
                                                                    option.serviceChargeRate
                                                                }
                                                                %
                                                            </span>
                                                            {option.serviceChargeTaxable && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="font-normal text-muted-foreground"
                                                                >
                                                                    Taxable
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            None
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    <MoneyText
                                                        amount={
                                                            option.packagingFee
                                                        }
                                                        currency={currency}
                                                        plain
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={{
                                                            value: option.active
                                                                ? 'active'
                                                                : 'inactive',
                                                            label: option.active
                                                                ? 'Active'
                                                                : 'Inactive',
                                                            tone: option.active
                                                                ? 'success'
                                                                : 'neutral',
                                                        }}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex justify-end">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            aria-label={`Edit dining option ${option.name}`}
                                                            onClick={() =>
                                                                setEditing({
                                                                    row: option,
                                                                })
                                                            }
                                                        >
                                                            <Pencil data-icon="inline-start" />
                                                            Edit
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>

                        <div className="flex flex-col divide-y md:hidden">
                            {options.map((option) => {
                                const KindIcon =
                                    KIND_ICONS[option.kind] ?? Utensils;
                                const kindLabel =
                                    kindLabels.get(option.kind) ?? option.kind;

                                return (
                                    <div
                                        key={option.id}
                                        className="flex flex-col gap-3 p-4"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex min-w-0 flex-col gap-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium text-foreground">
                                                        {option.name}
                                                    </span>
                                                    {option.isDefault && (
                                                        <Badge>Default</Badge>
                                                    )}
                                                </div>
                                                <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                                                    <KindIcon
                                                        aria-hidden
                                                        className="size-3.5 shrink-0"
                                                    />
                                                    <span>{kindLabel}</span>
                                                </span>
                                            </div>
                                            <StatusBadge
                                                status={{
                                                    value: option.active
                                                        ? 'active'
                                                        : 'inactive',
                                                    label: option.active
                                                        ? 'Active'
                                                        : 'Inactive',
                                                    tone: option.active
                                                        ? 'success'
                                                        : 'neutral',
                                                }}
                                            />
                                        </div>

                                        <div className="grid grid-cols-2 gap-3 text-sm tabular-nums">
                                            <div className="flex flex-col gap-0.5">
                                                <span className="text-xs text-muted-foreground">
                                                    Service charge
                                                </span>
                                                <span className="text-foreground">
                                                    {option.applyServiceCharge
                                                        ? `${option.serviceChargeRate}%${option.serviceChargeTaxable ? ' (taxable)' : ''}`
                                                        : 'None'}
                                                </span>
                                            </div>
                                            <div className="flex flex-col gap-0.5">
                                                <span className="text-xs text-muted-foreground">
                                                    Packaging
                                                </span>
                                                <span className="text-foreground">
                                                    <MoneyText
                                                        amount={
                                                            option.packagingFee
                                                        }
                                                        currency={currency}
                                                    />
                                                </span>
                                            </div>
                                        </div>

                                        <div className="flex justify-end">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="min-h-11"
                                                aria-label={`Edit dining option ${option.name}`}
                                                onClick={() =>
                                                    setEditing({ row: option })
                                                }
                                            >
                                                <Pencil data-icon="inline-start" />
                                                Edit
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            )}

            {editing && outletId !== null && (
                <PosDiningOptionForm
                    key={editing.row?.id ?? 'new'}
                    row={editing.row}
                    currency={currency}
                    outletId={outletId}
                    hasServiceChargeCode={hasServiceChargeCode}
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
