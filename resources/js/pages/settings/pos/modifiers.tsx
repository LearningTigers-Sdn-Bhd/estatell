import { router } from '@inertiajs/react';
import { Plus, Search, SlidersHorizontal, X } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { StatusBadge } from '@/components/pms/status-badge';
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
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { toast } from '@/components/ui/toast';
import { availability } from '@/routes/settings/pos/modifier-options';

const FILTER_OPTIONS: EnumOption[] = [
    { value: 'all', label: 'All groups' },
    { value: 'required', label: 'Required only' },
    { value: 'optional', label: 'Optional only' },
    { value: 'unavailable', label: 'Has unavailable choices' },
];

function selectionRuleSummary(group: ModifierGroupRow): string {
    const parts: string[] = [];

    if (group.minSelect > 0 && group.maxSelect === group.minSelect) {
        parts.push(`Pick exactly ${group.minSelect}`);
    } else if (group.minSelect > 0 && group.maxSelect !== null) {
        parts.push(`Pick ${group.minSelect} to ${group.maxSelect}`);
    } else if (group.minSelect > 0) {
        parts.push(`Pick at least ${group.minSelect}`);
    } else if (group.maxSelect !== null) {
        parts.push(`Up to ${group.maxSelect}`);
    } else {
        parts.push('Any number');
    }

    if (group.freeIncludedCount > 0) {
        parts.push(`${group.freeIncludedCount} included free`);
    }

    if (group.maxPerOption > 1) {
        parts.push(`up to ${group.maxPerOption} per choice`);
    }

    return parts.join(' · ');
}

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
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState('all');
    const [savingOptionId, setSavingOptionId] = useState<number | null>(null);

    const itemNameById = useMemo(
        () => new Map(items.map((item) => [Number(item.value), item.label])),
        [items],
    );

    const totalOptions = useMemo(
        () => groups.reduce((sum, group) => sum + group.options.length, 0),
        [groups],
    );

    const unavailableCount = useMemo(
        () =>
            groups.reduce(
                (sum, group) =>
                    sum +
                    group.options.filter(
                        (option) => option.availability === 'none',
                    ).length,
                0,
            ),
        [groups],
    );

    const hasActiveFilter = search.trim() !== '' || filter !== 'all';

    const filteredGroups = useMemo(() => {
        const normalized = search.trim().toLocaleLowerCase();

        return groups.filter((group) => {
            if (filter === 'required' && group.minSelect === 0) {
                return false;
            }

            if (filter === 'optional' && group.minSelect > 0) {
                return false;
            }

            if (
                filter === 'unavailable' &&
                !group.options.some((option) => option.availability === 'none')
            ) {
                return false;
            }

            if (normalized === '') {
                return true;
            }

            return [
                group.name,
                ...group.options.map((option) => option.name),
                ...group.itemIds.map((id) => itemNameById.get(id) ?? ''),
            ].some((value) => value.toLocaleLowerCase().includes(normalized));
        });
    }, [groups, filter, search, itemNameById]);

    const addButton = (
        <Button
            type="button"
            className="max-sm:min-h-11"
            onClick={() => setEditing({ row: null })}
        >
            <Plus data-icon="inline-start" />
            Add modifier group
        </Button>
    );

    return (
        <PosSettingsShell activeTab="modifiers" actions={addButton}>
            {groups.length === 0 ? (
                <div className="rounded-xl border bg-card p-6">
                    <EmptyState
                        icon={SlidersHorizontal}
                        title="No modifier groups yet"
                        description="Use Add modifier group above to create choices like cooking temperature, milk type, or toppings."
                    />
                </div>
            ) : (
                <div className="flex flex-col gap-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex flex-1 flex-col gap-2 sm:max-w-xl sm:flex-row sm:items-center">
                            <InputGroup className="w-full sm:flex-1">
                                <InputGroupAddon>
                                    <Search aria-hidden />
                                </InputGroupAddon>
                                <InputGroupInput
                                    type="search"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Search groups, choices, or linked items"
                                    aria-label="Search modifier groups"
                                />
                                {search !== '' && (
                                    <InputGroupAddon align="inline-end">
                                        <InputGroupButton
                                            size="icon-xs"
                                            aria-label="Clear search"
                                            onClick={() => setSearch('')}
                                        >
                                            <X />
                                        </InputGroupButton>
                                    </InputGroupAddon>
                                )}
                            </InputGroup>
                            <EnumSelect
                                name="modifier_filter"
                                options={FILTER_OPTIONS}
                                value={filter}
                                onValueChange={setFilter}
                                icon={<SlidersHorizontal aria-hidden />}
                                aria-label="Filter modifier groups"
                                className="w-full sm:w-52"
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground tabular-nums">
                            <span>
                                {hasActiveFilter
                                    ? `Showing ${filteredGroups.length} of ${groups.length} ${groups.length === 1 ? 'group' : 'groups'}`
                                    : `${groups.length} ${groups.length === 1 ? 'group' : 'groups'} · ${totalOptions} ${totalOptions === 1 ? 'choice' : 'choices'}`}
                            </span>
                            {unavailableCount > 0 && (
                                <StatusBadge
                                    status={{
                                        value: 'unavailable',
                                        label: `${unavailableCount} unavailable`,
                                        tone: 'warning',
                                    }}
                                />
                            )}
                        </div>
                    </div>

                    {filteredGroups.length === 0 ? (
                        <div className="rounded-xl border bg-card p-6">
                            <EmptyState
                                icon={Search}
                                title="No matching modifier groups"
                                description="No modifier groups match the current search or filter."
                                action={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="max-sm:min-h-11"
                                        onClick={() => {
                                            setSearch('');
                                            setFilter('all');
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-2">
                            {filteredGroups.map((group) => {
                                const linkedNames = group.itemIds
                                    .map((id) => itemNameById.get(id))
                                    .filter((name): name is string =>
                                        Boolean(name),
                                    );

                                return (
                                    <Card key={group.id} className="gap-0 py-0">
                                        <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 border-b bg-muted/40 py-3.5">
                                            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <CardTitle className="truncate">
                                                        {group.name}
                                                    </CardTitle>
                                                    <Badge variant="outline">
                                                        {group.minSelect > 0
                                                            ? 'Required'
                                                            : 'Optional'}
                                                    </Badge>
                                                    {!group.active && (
                                                        <StatusBadge
                                                            status={{
                                                                value: 'inactive',
                                                                label: 'Inactive',
                                                                tone: 'neutral',
                                                            }}
                                                        />
                                                    )}
                                                </div>
                                                <CardDescription className="tabular-nums">
                                                    {selectionRuleSummary(
                                                        group,
                                                    )}
                                                </CardDescription>
                                                <p className="text-xs text-muted-foreground">
                                                    {linkedNames.length === 0
                                                        ? 'Not linked to any menu items yet'
                                                        : linkedNames.length <=
                                                            3
                                                          ? `Linked to: ${linkedNames.join(', ')}`
                                                          : `Linked to ${linkedNames.length} items: ${linkedNames.slice(0, 3).join(', ')} +${linkedNames.length - 3} more`}
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="ml-auto shrink-0 max-sm:min-h-11"
                                                aria-label={`Edit modifier group ${group.name}`}
                                                onClick={() =>
                                                    setEditing({ row: group })
                                                }
                                            >
                                                Edit
                                            </Button>
                                        </CardHeader>
                                        <CardContent className="divide-y px-0">
                                            {group.options.length === 0 ? (
                                                <div className="px-4 py-4 text-sm text-muted-foreground">
                                                    No choices configured yet.
                                                </div>
                                            ) : (
                                                group.options.map((option) => {
                                                    const delta = Number(
                                                        option.priceDelta,
                                                    );
                                                    const optionId =
                                                        option.id ?? 0;

                                                    return (
                                                        <div
                                                            key={optionId}
                                                            className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-3"
                                                        >
                                                            <div className="flex min-w-0 flex-col gap-0.5">
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <span className="font-medium text-foreground">
                                                                        {
                                                                            option.name
                                                                        }
                                                                    </span>
                                                                    {!option.active && (
                                                                        <Badge variant="secondary">
                                                                            Off
                                                                        </Badge>
                                                                    )}
                                                                    {option.availability ===
                                                                        'none' && (
                                                                        <StatusBadge
                                                                            status={{
                                                                                value: 'none',
                                                                                label: '86d',
                                                                                tone: 'warning',
                                                                            }}
                                                                        />
                                                                    )}
                                                                </div>
                                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                                    {delta ===
                                                                    0 ? (
                                                                        'Included'
                                                                    ) : (
                                                                        <>
                                                                            +
                                                                            <MoneyText
                                                                                amount={
                                                                                    option.priceDelta
                                                                                }
                                                                                currency={
                                                                                    currency
                                                                                }
                                                                            />
                                                                        </>
                                                                    )}
                                                                </span>
                                                            </div>
                                                            <div className="flex justify-end">
                                                                <EnumSelect
                                                                    name={`availability-${optionId}`}
                                                                    options={
                                                                        availabilityOptions
                                                                    }
                                                                    value={
                                                                        option.availability
                                                                    }
                                                                    disabled={
                                                                        savingOptionId ===
                                                                        optionId
                                                                    }
                                                                    onValueChange={(
                                                                        value,
                                                                    ) => {
                                                                        setSavingOptionId(
                                                                            optionId,
                                                                        );
                                                                        router.post(
                                                                            availability.url(
                                                                                {
                                                                                    option: optionId,
                                                                                },
                                                                            ),
                                                                            {
                                                                                availability:
                                                                                    value,
                                                                            },
                                                                            {
                                                                                preserveScroll: true,
                                                                                onFinish:
                                                                                    () =>
                                                                                        setSavingOptionId(
                                                                                            null,
                                                                                        ),
                                                                                onError:
                                                                                    () =>
                                                                                        toast.add(
                                                                                            {
                                                                                                title: 'Could not update availability.',
                                                                                                type: 'error',
                                                                                            },
                                                                                        ),
                                                                            },
                                                                        );
                                                                    }}
                                                                    aria-label={`${option.name} availability`}
                                                                    className="w-full sm:w-44"
                                                                />
                                                            </div>
                                                        </div>
                                                    );
                                                })
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    )}
                </div>
            )}

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
