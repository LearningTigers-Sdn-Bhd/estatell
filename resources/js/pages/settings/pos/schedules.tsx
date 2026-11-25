import { router } from '@inertiajs/react';
import { CalendarClock, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    PosComboForm,
    PosScheduleForm,
    scheduleDaysLabel,
} from '@/components/pms/pos/pos-schedule-forms';
import type {
    ComboRow,
    ComboVariantOption,
    ScheduleRow,
} from '@/components/pms/pos/pos-schedule-forms';
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
import { index as schedulesIndex } from '@/routes/settings/pos/schedules';

type Props = {
    outlets: EnumOption[];
    outlet: { id: number; name: string } | null;
    schedules: ScheduleRow[];
    categories: EnumOption[];
    items: EnumOption[];
    mealPeriods: EnumOption[];
    combos: ComboRow[];
    variants: ComboVariantOption[];
};

export default function PosSchedules({
    outlets,
    outlet,
    schedules,
    categories,
    items,
    mealPeriods,
    combos,
    variants,
}: Props) {
    const [editing, setEditing] = useState<{ row: ScheduleRow | null } | null>(
        null,
    );
    const [comboItem, setComboItem] = useState('');
    const selected = items.find((item) => item.value === comboItem);

    return (
        <PosSettingsShell activeTab="schedules">
            <div className="flex flex-col gap-6">
                {outlets.length > 1 && (
                    <EnumSelect
                        name="outlet"
                        options={outlets}
                        value={String(outlet?.id ?? '')}
                        onValueChange={(value) =>
                            router.get(
                                schedulesIndex.url(undefined, {
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
                            <CardTitle>Menu schedules</CardTitle>
                            <CardDescription>
                                Sell breakfast only in the morning or a happy
                                hour menu only in the evening.
                            </CardDescription>
                        </div>
                        <Button
                            disabled={outlet === null}
                            onClick={() => setEditing({ row: null })}
                        >
                            <Plus data-icon="inline-start" />
                            Add schedule
                        </Button>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2">
                        {schedules.length === 0 ? (
                            <EmptyState
                                icon={CalendarClock}
                                title="No menu schedules yet"
                                description="Every item is currently on sale all day."
                            />
                        ) : (
                            schedules.map((schedule) => (
                                <div
                                    key={schedule.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                                >
                                    <div className="flex flex-col">
                                        <span className="font-medium">
                                            {schedule.name}{' '}
                                            {!schedule.active && (
                                                <Badge variant="secondary">
                                                    Off
                                                </Badge>
                                            )}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {scheduleDaysLabel(
                                                schedule.daysOfWeek,
                                            )}{' '}
                                            · {schedule.startTime} to{' '}
                                            {schedule.endTime} ·{' '}
                                            {schedule.categoryIds.length}{' '}
                                            categories,{' '}
                                            {schedule.itemIds.length} items
                                        </span>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setEditing({ row: schedule })
                                        }
                                    >
                                        Edit
                                    </Button>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Combos</CardTitle>
                        <CardDescription>
                            Pick an item and give it parts, such as a main and a
                            drink. The item&apos;s own price is the combo price,
                            and each pick can add an upcharge.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <EnumSelect
                            name="combo_item"
                            options={items}
                            value={comboItem}
                            onValueChange={setComboItem}
                            placeholder="Pick the combo item"
                        />
                        {selected !== undefined && (
                            <PosComboForm
                                key={selected.value}
                                item={selected}
                                combo={combos.find(
                                    (combo) =>
                                        String(combo.id) === selected.value,
                                )}
                                variants={variants}
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
            {editing !== null && outlet !== null && (
                <PosScheduleForm
                    key={editing.row?.id ?? 'new'}
                    outletId={outlet.id}
                    row={editing.row}
                    categories={categories}
                    items={items}
                    mealPeriods={mealPeriods}
                    onClose={() => setEditing(null)}
                />
            )}
        </PosSettingsShell>
    );
}

PosSchedules.layout = {
    breadcrumbs: posSettingsBreadcrumbs('schedules'),
};
