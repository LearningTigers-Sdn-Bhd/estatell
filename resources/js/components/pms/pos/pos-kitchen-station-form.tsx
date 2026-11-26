import { useForm } from '@inertiajs/react';
import {
    BedDouble,
    Check,
    Layers,
    Monitor,
    Printer,
    Server,
    ShoppingBag,
    Truck,
    Utensils,
    Waves,
    Wine,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import {
    store as storeStation,
    update as updateStation,
} from '@/routes/settings/pos/kitchen-stations';
import { unlessRefused } from './pos-unless-refused';

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

const MODE_META: Record<string, { icon: LucideIcon; description: string }> = {
    kds: {
        icon: Monitor,
        description:
            'Live kitchen screen where cooks track and bump fired dishes.',
    },
    browser_print: {
        icon: Printer,
        description:
            'Prints paper order dockets directly from the POS browser.',
    },
    posport_printer: {
        icon: Server,
        description:
            'Routes dockets automatically to a thermal network printer.',
    },
    both: {
        icon: Layers,
        description:
            'Shows dishes on the kitchen screen and prints a paper docket.',
    },
};

const KIND_ICONS: Record<string, LucideIcon> = {
    dine_in: Utensils,
    takeaway: ShoppingBag,
    room_service: BedDouble,
    poolside: Waves,
    minibar: Wine,
    delivery: Truck,
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

    const toggleKind = (value: string) => {
        const selected = form.data.filter_dining_kinds.includes(value);
        form.setData(
            'filter_dining_kinds',
            selected
                ? form.data.filter_dining_kinds.filter((kind) => kind !== value)
                : [...form.data.filter_dining_kinds, value],
        );
    };

    const modeChoices = modes.map((mode) => {
        const meta = MODE_META[mode.value] ?? {
            icon: Monitor,
            description: 'Receive fired dishes on this station.',
        };

        return {
            value: mode.value,
            label: mode.label,
            description: meta.description,
            icon: meta.icon,
        };
    });

    const selectedKindLabels = diningKinds
        .filter((kind) => form.data.filter_dining_kinds.includes(kind.value))
        .map((kind) => kind.label);

    const warnMin = Math.max(1, Number(form.data.warn_after_minutes) || 0);
    const alertMin = Math.max(
        warnMin + 1,
        Number(form.data.alert_after_minutes) || 0,
    );

    return (
        <FormOverlay
            variant="sheet"
            size="lg"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? `Edit ${row.name}` : 'Add kitchen station'}
            description="Set up where fired dishes go, how late tickets are flagged, and which order types this station handles."
            errors={form.errors}
            dirty={form.isDirty}
            processing={form.processing}
            submitLabel={row ? 'Save station' : 'Create station'}
            onSubmit={() => {
                const options = {
                    preserveScroll: true,
                    onSuccess: unlessRefused(onClose),
                };

                if (row) {
                    form.put(updateStation.url({ station: row.id }), options);
                } else {
                    form.post(storeStation.url({ outlet: outletId }), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="station-name">Station name</FieldLabel>
                <Input
                    id="station-name"
                    maxLength={80}
                    placeholder="e.g. Hot Kitchen, Cold Bar, or Pastry Pass"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={!!form.errors.name}
                    autoFocus
                />
                <FieldDescription>
                    Shown when opening the Kitchen Display and when assigning
                    category or item routing.
                </FieldDescription>
                <FieldError>{form.errors.name}</FieldError>
            </Field>

            <ChoiceCards
                id="station-mode"
                name="mode"
                legend="How this station receives tickets"
                columns={2}
                choices={modeChoices}
                value={form.data.mode}
                onValueChange={(value) => form.setData('mode', value)}
                error={form.errors.mode}
            />

            <section
                aria-label="Prep time alerts"
                className="flex flex-col gap-3 rounded-xl border bg-muted/20 p-4"
            >
                <div className="flex flex-col gap-0.5">
                    <h3 className="text-sm font-medium text-foreground">
                        Prep time alerts
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        Color-codes open kitchen tickets as wait time grows so
                        cooks spot delayed dishes early.
                    </p>
                </div>

                <div className="grid grid-cols-3 gap-1.5 text-xs tabular-nums">
                    <div className="flex flex-col gap-1">
                        <span className="h-1.5 rounded-full bg-success" />
                        <span className="text-muted-foreground">
                            0 to {warnMin}m on time
                        </span>
                    </div>
                    <div className="flex flex-col gap-1">
                        <span className="h-1.5 rounded-full bg-warning" />
                        <span className="text-warning-text">
                            {warnMin} to {alertMin}m warn
                        </span>
                    </div>
                    <div className="flex flex-col gap-1">
                        <span className="h-1.5 rounded-full bg-destructive" />
                        <span className="text-destructive">
                            {alertMin}m+ urgent
                        </span>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3 pt-1">
                    <Field data-invalid={!!form.errors.warn_after_minutes}>
                        <FieldLabel htmlFor="station-warn">
                            Warn after (min)
                        </FieldLabel>
                        <Input
                            id="station-warn"
                            type="number"
                            min={1}
                            className="tabular-nums"
                            value={form.data.warn_after_minutes}
                            onChange={(event) =>
                                form.setData(
                                    'warn_after_minutes',
                                    Number(event.target.value),
                                )
                            }
                            aria-invalid={!!form.errors.warn_after_minutes}
                        />
                        <FieldDescription>
                            Ticket turns amber on the display.
                        </FieldDescription>
                        <FieldError>
                            {form.errors.warn_after_minutes}
                        </FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.alert_after_minutes}>
                        <FieldLabel htmlFor="station-alert">
                            Alert after (min)
                        </FieldLabel>
                        <Input
                            id="station-alert"
                            type="number"
                            min={2}
                            className="tabular-nums"
                            value={form.data.alert_after_minutes}
                            onChange={(event) =>
                                form.setData(
                                    'alert_after_minutes',
                                    Number(event.target.value),
                                )
                            }
                            aria-invalid={!!form.errors.alert_after_minutes}
                        />
                        <FieldDescription>
                            Must be greater than warn time.
                        </FieldDescription>
                        <FieldError>
                            {form.errors.alert_after_minutes}
                        </FieldError>
                    </Field>
                </div>
            </section>

            <section
                aria-label="Dining kinds filter"
                className="flex flex-col gap-3 rounded-xl border bg-muted/20 p-4"
            >
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="flex flex-col gap-0.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-sm font-medium text-foreground">
                                Order dining kinds
                            </h3>
                            <Badge variant="outline">
                                {selectedKindLabels.length === 0
                                    ? 'All kinds'
                                    : `${selectedKindLabels.length} selected`}
                            </Badge>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {selectedKindLabels.length === 0
                                ? 'All dining kinds are included by default. Tick specific kinds below to limit this station to certain orders.'
                                : `Only ticked dining kinds appear here: ${selectedKindLabels.join(', ')}.`}
                        </p>
                    </div>
                    {selectedKindLabels.length > 0 && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="xs"
                            className="ml-auto shrink-0"
                            onClick={() =>
                                form.setData('filter_dining_kinds', [])
                            }
                        >
                            Show all kinds
                        </Button>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {diningKinds.map((kind) => {
                        const selected = form.data.filter_dining_kinds.includes(
                            kind.value,
                        );
                        const KindIcon = KIND_ICONS[kind.value] ?? Utensils;

                        return (
                            <button
                                key={kind.value}
                                type="button"
                                aria-pressed={selected}
                                onClick={() => toggleKind(kind.value)}
                                className={cn(
                                    'flex min-h-11 items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left text-sm transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none',
                                    selected
                                        ? 'border-primary bg-primary/10 font-medium text-foreground'
                                        : 'border-border bg-card text-foreground/85 hover:bg-muted/60',
                                )}
                            >
                                <span className="flex min-w-0 items-center gap-2">
                                    <KindIcon
                                        aria-hidden="true"
                                        className="size-4 shrink-0 text-muted-foreground"
                                    />
                                    <span className="truncate">
                                        {kind.label}
                                    </span>
                                </span>
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'flex size-4 shrink-0 items-center justify-center rounded-sm border transition-colors motion-reduce:transition-none',
                                        selected
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-muted-foreground/40 bg-background',
                                    )}
                                >
                                    {selected && <Check className="size-3" />}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </section>

            <section
                aria-label="Ticket display and status"
                className="divide-y rounded-xl border bg-card"
            >
                <div className="flex items-center justify-between gap-4 p-3.5">
                    <div className="flex flex-col gap-0.5">
                        <FieldLabel htmlFor="station-internal">
                            Show kitchen name on tickets
                        </FieldLabel>
                        <FieldDescription>
                            Display each dish&apos;s short kitchen name (when
                            set) instead of the guest-facing menu name.
                        </FieldDescription>
                    </div>
                    <Switch
                        id="station-internal"
                        checked={form.data.use_internal_name}
                        onCheckedChange={(checked) =>
                            form.setData('use_internal_name', checked)
                        }
                    />
                </div>
                <div className="flex items-center justify-between gap-4 p-3.5">
                    <div className="flex flex-col gap-0.5">
                        <FieldLabel htmlFor="station-active">
                            Station active
                        </FieldLabel>
                        <FieldDescription>
                            Accept newly fired dishes. Turn off to pause this
                            station without losing routing rules.
                        </FieldDescription>
                    </div>
                    <Switch
                        id="station-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                </div>
            </section>
        </FormOverlay>
    );
}
