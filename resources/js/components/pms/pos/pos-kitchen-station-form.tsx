import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    store as storeStation,
    update as updateStation,
} from '@/routes/settings/pos/kitchen-stations';

export type KitchenStationRow = {
    id: number;
    name: string;
    mode: string;
    useInternalName: boolean;
    warnAfterMinutes: number;
    alertAfterMinutes: number;
    diningKinds: string[];
    sortOrder: number;
    active: boolean;
};

/**
 * Add a kitchen station to an outlet, or change one. The alert time must come after the warn time; the Action says so if not.
 */
export function KitchenStationForm({
    outletId,
    row,
    modes,
    diningKinds,
    onClose,
}: {
    outletId: number;
    row: KitchenStationRow | null;
    modes: EnumOption[];
    diningKinds: EnumOption[];
    onClose: () => void;
}) {
    const form = useForm({
        name: row?.name ?? '',
        mode: row?.mode ?? 'kds',
        use_internal_name: row?.useInternalName ?? false,
        warn_after_minutes: row?.warnAfterMinutes ?? 10,
        alert_after_minutes: row?.alertAfterMinutes ?? 20,
        filter_dining_kinds: row?.diningKinds ?? ([] as string[]),
        active: row?.active ?? true,
    });

    const toggleKind = (value: string, checked: boolean) => {
        form.setData(
            'filter_dining_kinds',
            checked
                ? [...form.data.filter_dining_kinds, value]
                : form.data.filter_dining_kinds.filter(
                      (kind) => kind !== value,
                  ),
        );
    };

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? 'Edit kitchen station' : 'Add kitchen station'}
            description="A station shows the fired dishes routed to it on its kitchen screen."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Save station"
            onSubmit={() => {
                const options = { preserveScroll: true, onSuccess: onClose };

                if (row) {
                    form.put(updateStation.url({ station: row.id }), options);
                } else {
                    form.post(storeStation.url({ outlet: outletId }), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="station-name">Name</FieldLabel>
                <Input
                    id="station-name"
                    maxLength={80}
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    autoFocus
                />
            </Field>
            <Field>
                <FieldLabel htmlFor="station-mode">Mode</FieldLabel>
                <EnumSelect
                    id="station-mode"
                    name="mode"
                    options={modes}
                    value={form.data.mode}
                    onValueChange={(value) => form.setData('mode', value)}
                />
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field data-invalid={!!form.errors.warn_after_minutes}>
                    <FieldLabel htmlFor="station-warn">
                        Warn after (min)
                    </FieldLabel>
                    <Input
                        id="station-warn"
                        type="number"
                        min={1}
                        value={form.data.warn_after_minutes}
                        onChange={(event) =>
                            form.setData(
                                'warn_after_minutes',
                                Number(event.target.value),
                            )
                        }
                    />
                </Field>
                <Field data-invalid={!!form.errors.alert_after_minutes}>
                    <FieldLabel htmlFor="station-alert">
                        Alert after (min)
                    </FieldLabel>
                    <Input
                        id="station-alert"
                        type="number"
                        min={2}
                        value={form.data.alert_after_minutes}
                        onChange={(event) =>
                            form.setData(
                                'alert_after_minutes',
                                Number(event.target.value),
                            )
                        }
                    />
                </Field>
            </div>
            <Field>
                <FieldLabel>Dining kinds shown here</FieldLabel>
                <div className="flex flex-wrap gap-3">
                    {diningKinds.map((kind) => (
                        <Field key={kind.value} orientation="horizontal">
                            <Switch
                                id={`station-kind-${kind.value}`}
                                checked={form.data.filter_dining_kinds.includes(
                                    kind.value,
                                )}
                                onCheckedChange={(checked) =>
                                    toggleKind(kind.value, checked)
                                }
                            />
                            <FieldLabel htmlFor={`station-kind-${kind.value}`}>
                                {kind.label}
                            </FieldLabel>
                        </Field>
                    ))}
                </div>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="station-internal"
                    checked={form.data.use_internal_name}
                    onCheckedChange={(checked) =>
                        form.setData('use_internal_name', checked)
                    }
                />
                <FieldLabel htmlFor="station-internal">
                    Show the internal name on tickets
                </FieldLabel>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="station-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
                <FieldLabel htmlFor="station-active">Active</FieldLabel>
            </Field>
        </FormOverlay>
    );
}
