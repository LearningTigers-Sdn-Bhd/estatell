import { router } from '@inertiajs/react';
import { Plus, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import type { MultiComboboxOption } from '@/components/pms/multi-combobox';
import { PosModifierGroupForm } from '@/components/pms/pos/pos-modifier-group-form';
import type { ModifierGroupRow } from '@/components/pms/pos/pos-modifier-group-form';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { availability } from '@/routes/settings/pos/modifier-options';

export default function PosModifiers({
    currency,
    availabilityOptions,
    groups,
    items,
}: {
    currency: App.Data.Property.CurrencyData | null;
    availabilityOptions: EnumOption[];
    groups: ModifierGroupRow[];
    items: MultiComboboxOption[];
}) {
    const [editing, setEditing] = useState<{
        row: ModifierGroupRow | null;
    } | null>(null);

    return (
        <PosSettingsShell activeTab="modifiers">
            <div className="flex flex-col gap-6">
                <div>
                    <Button onClick={() => setEditing({ row: null })}>
                        <Plus data-icon="inline-start" />
                        Add modifier group
                    </Button>
                </div>
                {groups.length === 0 ? (
                    <EmptyState
                        icon={SlidersHorizontal}
                        title="No modifier groups yet"
                        description="Add choices like cooking temperature, milk type, or toppings."
                    />
                ) : (
                    groups.map((group) => (
                        <Card key={group.id}>
                            <CardHeader className="flex flex-row items-start justify-between gap-4">
                                <div className="flex flex-col gap-1">
                                    <CardTitle>{group.name}</CardTitle>
                                    <CardDescription>
                                        Pick {group.minSelect}
                                        {group.maxSelect !== null &&
                                            ` to ${group.maxSelect}`}{' '}
                                        · {group.freeIncludedCount} included
                                        free
                                    </CardDescription>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setEditing({ row: group })}
                                >
                                    Edit
                                </Button>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2">
                                {group.options.map((option) => (
                                    <div
                                        key={option.id}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <span>
                                            {option.name}{' '}
                                            <span className="text-muted-foreground tabular-nums">
                                                +
                                                <MoneyText
                                                    amount={option.priceDelta}
                                                    currency={currency}
                                                />
                                            </span>
                                        </span>
                                        <EnumSelect
                                            name={`availability-${option.id}`}
                                            options={availabilityOptions}
                                            value={option.availability}
                                            onValueChange={(value) =>
                                                router.post(
                                                    availability.url({
                                                        option: option.id ?? 0,
                                                    }),
                                                    { availability: value },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            aria-label={`${option.name} availability`}
                                        />
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    ))
                )}
            </div>
            {editing && (
                <PosModifierGroupForm
                    key={editing.row?.id ?? 'new'}
                    row={editing.row}
                    items={items}
                    onClose={() => setEditing(null)}
                />
            )}
        </PosSettingsShell>
    );
}

PosModifiers.layout = {
    breadcrumbs: posSettingsBreadcrumbs('modifiers'),
};
