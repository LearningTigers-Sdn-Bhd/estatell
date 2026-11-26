import { useForm } from '@inertiajs/react';
import { Plus, Search, Store, Utensils, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { suggestCode } from '@/lib/charge-code';
import { store, update } from '@/routes/settings/pos-outlets';

type Outlet = App.Data.Pos.PosOutletRowData;
type Option = { value: string; label: string };

type FormData = {
    name: string;
    code: string;
    pos_enabled: boolean;
    default_charge_item_id: string;
    active: boolean;
};

const empty: FormData = {
    name: '',
    code: '',
    pos_enabled: true,
    default_charge_item_id: '',
    active: true,
};

function outletModeLabel(outlet: Outlet): string {
    if (outlet.posEnabled && outlet.defaultChargeItemId !== null) {
        return 'POS + API';
    }

    if (outlet.posEnabled) {
        return 'Built-in POS';
    }

    return 'POS API';
}

export default function PosOutletsIndex({
    outlets,
    chargeItems,
}: {
    outlets: Outlet[];
    chargeItems: Option[];
}) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Outlet | null>(null);
    const [codeEdited, setCodeEdited] = useState(false);
    const [search, setSearch] = useState('');
    const form = useForm<FormData>(empty);

    function openForm(outlet: Outlet | null) {
        setEditing(outlet);
        setCodeEdited(outlet !== null);
        form.clearErrors();
        form.setData(
            outlet
                ? {
                      name: outlet.name,
                      code: outlet.code,
                      pos_enabled: outlet.posEnabled,
                      default_charge_item_id:
                          outlet.defaultChargeItemId !== null
                              ? String(outlet.defaultChargeItemId)
                              : '',
                      active: outlet.active,
                  }
                : empty,
        );
        setOpen(true);
    }

    function submit() {
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        form.transform((data) => ({
            ...data,
            default_charge_item_id:
                data.default_charge_item_id === ''
                    ? null
                    : Number(data.default_charge_item_id),
        }));

        if (editing) {
            form.put(update.url({ outlet: editing.id }), options);
        } else {
            form.post(store.url(), options);
        }
    }

    const filteredOutlets = useMemo(() => {
        const needle = search.trim().toLocaleLowerCase();

        if (needle === '') {
            return outlets;
        }

        return outlets.filter((outlet) =>
            `${outlet.name} ${outlet.code} ${outlet.chargeItemName ?? ''} ${outletModeLabel(outlet)}`
                .toLocaleLowerCase()
                .includes(needle),
        );
    }, [outlets, search]);

    const activeCount = useMemo(
        () => outlets.filter((outlet) => outlet.active).length,
        [outlets],
    );
    const builtInCount = useMemo(
        () => outlets.filter((outlet) => outlet.posEnabled).length,
        [outlets],
    );

    const headerActions = (
        <Button
            type="button"
            className="max-sm:min-h-11"
            onClick={() => openForm(null)}
        >
            <Plus data-icon="inline-start" />
            Add outlet
        </Button>
    );

    return (
        <>
            <PosSettingsShell activeTab="outlets" actions={headerActions}>
                <section className="@container overflow-hidden rounded-xl border bg-card">
                    <header className="flex flex-col gap-3 border-b bg-muted/40 px-4 py-3 @xl:flex-row @xl:items-center @xl:justify-between">
                        {outlets.length > 0 ? (
                            <InputGroup className="w-full @xl:max-w-xs">
                                <InputGroupAddon>
                                    <Search aria-hidden />
                                </InputGroupAddon>
                                <InputGroupInput
                                    type="search"
                                    aria-label="Search outlets"
                                    placeholder="Search name, code, or mode"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                                {search !== '' && (
                                    <InputGroupAddon align="inline-end">
                                        <InputGroupButton
                                            size="icon-xs"
                                            aria-label="Clear outlet search"
                                            onClick={() => setSearch('')}
                                        >
                                            <X />
                                        </InputGroupButton>
                                    </InputGroupAddon>
                                )}
                            </InputGroup>
                        ) : (
                            <span className="text-sm font-medium text-foreground">
                                Configured outlets
                            </span>
                        )}

                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground tabular-nums">
                            <Badge variant="outline">
                                {activeCount} active
                            </Badge>
                            <Badge variant="secondary">
                                {builtInCount} built-in POS
                            </Badge>
                        </div>
                    </header>

                    {outlets.length === 0 ? (
                        <div className="p-8">
                            <EmptyState
                                icon={Utensils}
                                title="No outlets yet"
                                description="Use Add outlet above to set up your first restaurant, bar, cafe, or external POS counter."
                            />
                        </div>
                    ) : filteredOutlets.length === 0 ? (
                        <div className="p-8">
                            <EmptyState
                                icon={Search}
                                title="No matching outlets"
                                description="No outlet matches your current search."
                                action={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setSearch('')}
                                    >
                                        Clear search
                                    </Button>
                                }
                            />
                        </div>
                    ) : (
                        <>
                            <div className="hidden overflow-x-auto md:block">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead scope="col">
                                                Outlet
                                            </TableHead>
                                            <TableHead scope="col">
                                                Mode
                                            </TableHead>
                                            <TableHead scope="col">
                                                API charge item
                                            </TableHead>
                                            <TableHead scope="col">
                                                Status
                                            </TableHead>
                                            <TableHead
                                                scope="col"
                                                className="w-24 text-end"
                                            >
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filteredOutlets.map((outlet) => (
                                            <TableRow key={outlet.id}>
                                                <TableCell>
                                                    <div className="flex items-center gap-3">
                                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-muted/50 text-foreground">
                                                            <Store
                                                                aria-hidden="true"
                                                                className="size-4"
                                                            />
                                                        </span>
                                                        <div className="flex flex-col gap-0.5">
                                                            <span className="font-medium text-foreground">
                                                                {outlet.name}
                                                            </span>
                                                            <span className="font-mono text-xs text-muted-foreground">
                                                                {outlet.code}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="secondary">
                                                        {outletModeLabel(
                                                            outlet,
                                                        )}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {outlet.chargeItemName ?? (
                                                        <span className="text-muted-foreground">
                                                            Not set
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={{
                                                            value: outlet.active
                                                                ? 'active'
                                                                : 'inactive',
                                                            label: outlet.active
                                                                ? 'Active'
                                                                : 'Inactive',
                                                            tone: outlet.active
                                                                ? 'success'
                                                                : 'neutral',
                                                        }}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-end">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        aria-label={`Edit ${outlet.name}`}
                                                        onClick={() =>
                                                            openForm(outlet)
                                                        }
                                                    >
                                                        Edit
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>

                            <div className="flex flex-col divide-y md:hidden">
                                {filteredOutlets.map((outlet) => (
                                    <div
                                        key={outlet.id}
                                        className="flex flex-col gap-3 p-4"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex min-w-0 flex-col gap-0.5">
                                                <span className="truncate font-medium text-foreground">
                                                    {outlet.name}
                                                </span>
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    {outlet.code}
                                                </span>
                                            </div>
                                            <StatusBadge
                                                status={{
                                                    value: outlet.active
                                                        ? 'active'
                                                        : 'inactive',
                                                    label: outlet.active
                                                        ? 'Active'
                                                        : 'Inactive',
                                                    tone: outlet.active
                                                        ? 'success'
                                                        : 'neutral',
                                                }}
                                            />
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            <Badge variant="secondary">
                                                {outletModeLabel(outlet)}
                                            </Badge>
                                            <span>
                                                API charge:{' '}
                                                {outlet.chargeItemName ??
                                                    'Not set'}
                                            </span>
                                        </div>
                                        <div className="flex justify-end">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="min-h-11"
                                                aria-label={`Edit ${outlet.name}`}
                                                onClick={() => openForm(outlet)}
                                            >
                                                Edit
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </>
                    )}
                </section>
            </PosSettingsShell>

            <FormOverlay
                variant="sheet"
                size="lg"
                open={open}
                onOpenChange={setOpen}
                title={editing ? `Edit ${editing.name}` : 'Add outlet'}
                description="Choose whether this outlet runs Estatell's built-in registers, receives external POS API room charges, or both."
                errors={form.errors}
                processing={form.processing}
                submitLabel={editing ? 'Save outlet' : 'Create outlet'}
                onSubmit={submit}
            >
                <div className="flex flex-col gap-5">
                    <div className="flex flex-col gap-4 rounded-xl border bg-card p-4">
                        <div className="flex flex-col gap-0.5">
                            <h3 className="text-sm font-semibold text-foreground">
                                Outlet identity
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                Shown on receipts, reports, and the POS register
                                selector.
                            </p>
                        </div>

                        <Field data-invalid={!!form.errors.name}>
                            <FieldLabel htmlFor="outlet-name">Name</FieldLabel>
                            <Input
                                id="outlet-name"
                                maxLength={100}
                                placeholder="e.g. Main Restaurant"
                                value={form.data.name}
                                onChange={(event) => {
                                    const name = event.target.value;

                                    form.setData((data) => ({
                                        ...data,
                                        name,
                                        code: codeEdited
                                            ? data.code
                                            : suggestCode(name),
                                    }));
                                }}
                                aria-invalid={!!form.errors.name}
                                autoFocus
                            />
                            <FieldError>{form.errors.name}</FieldError>
                        </Field>

                        <Field data-invalid={!!form.errors.code}>
                            <FieldLabel htmlFor="outlet-code">Code</FieldLabel>
                            <Input
                                id="outlet-code"
                                maxLength={30}
                                className="font-mono"
                                placeholder="e.g. MAIN_RESTAURANT"
                                value={form.data.code}
                                onChange={(event) => {
                                    setCodeEdited(true);
                                    form.setData(
                                        'code',
                                        event.target.value.toUpperCase(),
                                    );
                                }}
                                aria-invalid={!!form.errors.code}
                            />
                            <FieldDescription>
                                Letters, numbers, dashes, and underscores. Saved
                                in capitals and used by external API calls.
                            </FieldDescription>
                            <FieldError>{form.errors.code}</FieldError>
                        </Field>
                    </div>

                    <div className="flex flex-col gap-4 rounded-xl border bg-card p-4">
                        <div className="flex flex-col gap-0.5">
                            <h3 className="text-sm font-semibold text-foreground">
                                How this outlet operates
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                Turn on built-in POS to sell from Estatell, or
                                link a folio charge item for third-party POS API
                                postings.
                            </p>
                        </div>

                        <div className="flex items-center justify-between gap-4 rounded-lg border bg-muted/20 p-3.5">
                            <div className="flex flex-col gap-0.5">
                                <FieldLabel
                                    htmlFor="outlet-pos-enabled"
                                    className="text-sm font-medium text-foreground"
                                >
                                    Use built-in POS registers &amp; menu
                                </FieldLabel>
                                <p className="text-xs text-muted-foreground">
                                    Enables registers, menu pricing, floor
                                    plans, and kitchen displays inside Estatell.
                                </p>
                            </div>
                            <Switch
                                id="outlet-pos-enabled"
                                checked={form.data.pos_enabled}
                                onCheckedChange={(checked) =>
                                    form.setData('pos_enabled', checked)
                                }
                            />
                        </div>

                        <Field
                            data-invalid={!!form.errors.default_charge_item_id}
                        >
                            <div className="flex items-center justify-between gap-2">
                                <FieldLabel htmlFor="outlet-item">
                                    API charge item
                                </FieldLabel>
                                <Badge variant="outline">
                                    {form.data.pos_enabled
                                        ? 'Optional'
                                        : 'Required for API'}
                                </Badge>
                            </div>
                            <EnumSelect
                                id="outlet-item"
                                name="default_charge_item_id"
                                allLabel={
                                    form.data.pos_enabled
                                        ? 'None (built-in POS only)'
                                        : undefined
                                }
                                options={chargeItems}
                                value={form.data.default_charge_item_id}
                                onValueChange={(value) =>
                                    form.setData(
                                        'default_charge_item_id',
                                        value,
                                    )
                                }
                                placeholder="Pick an inclusive-tax charge item"
                                aria-invalid={
                                    !!form.errors.default_charge_item_id
                                }
                            />
                            <FieldDescription>
                                Used only when an external POS posts room
                                charges via the POS API. Taxes on this charge
                                item must be inclusive.
                            </FieldDescription>
                            <FieldError>
                                {form.errors.default_charge_item_id}
                            </FieldError>
                        </Field>
                    </div>

                    <div className="flex items-center justify-between gap-4 rounded-xl border bg-card p-4">
                        <div className="flex flex-col gap-0.5">
                            <FieldLabel
                                htmlFor="outlet-active"
                                className="text-sm font-medium text-foreground"
                            >
                                Outlet active
                            </FieldLabel>
                            <p className="text-xs text-muted-foreground">
                                Inactive outlets cannot open new shifts or
                                accept new POS orders.
                            </p>
                        </div>
                        <Switch
                            id="outlet-active"
                            checked={form.data.active}
                            onCheckedChange={(checked) =>
                                form.setData('active', checked)
                            }
                        />
                    </div>
                </div>
            </FormOverlay>
        </>
    );
}

PosOutletsIndex.layout = {
    breadcrumbs: posSettingsBreadcrumbs('outlets'),
};
