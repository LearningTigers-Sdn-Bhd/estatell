import { useForm } from '@inertiajs/react';
import { MoreHorizontal, Plus, Utensils } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
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

    const addButton = (
        <Button onClick={() => openForm(null)}>
            <Plus data-icon="inline-start" />
            Add outlet
        </Button>
    );

    return (
        <>
            <PosSettingsShell activeTab="outlets">
                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="flex flex-col gap-1">
                            <CardTitle>Outlets</CardTitle>
                            <CardDescription>
                                Restaurants, cafes, bars and retail counters.
                                Turn on built-in POS to run registers inside
                                Estatell, or assign an inclusive-tax charge item
                                to receive room charges from the POS API.
                            </CardDescription>
                        </div>
                        {outlets.length > 0 && addButton}
                    </CardHeader>
                    <CardContent>
                        {outlets.length === 0 ? (
                            <EmptyState
                                icon={Utensils}
                                title="No outlets yet"
                                description="Add an outlet to start setting up registers, menu items, or POS API room charges."
                                action={addButton}
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Code</TableHead>
                                        <TableHead>Mode</TableHead>
                                        <TableHead>API charge item</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-10">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {outlets.map((outlet) => (
                                        <TableRow key={outlet.id}>
                                            <TableCell className="font-medium">
                                                {outlet.name}
                                            </TableCell>
                                            <TableCell className="font-mono">
                                                {outlet.code}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="secondary">
                                                    {outlet.posEnabled &&
                                                    outlet.defaultChargeItemId !==
                                                        null
                                                        ? 'POS + API'
                                                        : outlet.posEnabled
                                                          ? 'Built-in POS'
                                                          : 'POS API'}
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
                                                <Badge
                                                    variant="secondary"
                                                    className={
                                                        outlet.active
                                                            ? 'bg-success/10 text-success'
                                                            : 'bg-muted text-muted-foreground'
                                                    }
                                                >
                                                    {outlet.active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        render={
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon-sm"
                                                                aria-label={`Actions for ${outlet.name}`}
                                                            />
                                                        }
                                                    >
                                                        <MoreHorizontal />
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                openForm(outlet)
                                                            }
                                                        >
                                                            Edit outlet
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </PosSettingsShell>

            <FormOverlay
                variant="sheet"
                open={open}
                onOpenChange={setOpen}
                title={editing ? 'Edit outlet' : 'Add outlet'}
                description="Built-in POS outlets price and tax items per category. Add a default charge item only if an external POS posts room charges here through the API."
                errors={form.errors}
                processing={form.processing}
                submitLabel={editing ? 'Save outlet' : 'Add outlet'}
                onSubmit={submit}
            >
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
                        Letters, numbers, dashes and underscores. Saved in
                        capitals.
                    </FieldDescription>
                    <FieldError>{form.errors.code}</FieldError>
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="outlet-pos-enabled"
                        checked={form.data.pos_enabled}
                        onCheckedChange={(checked) =>
                            form.setData('pos_enabled', checked)
                        }
                    />
                    <FieldLabel htmlFor="outlet-pos-enabled">
                        Use built-in POS registers &amp; menu
                    </FieldLabel>
                </Field>
                <Field data-invalid={!!form.errors.default_charge_item_id}>
                    <FieldLabel htmlFor="outlet-item">
                        API charge item
                        {form.data.pos_enabled ? ' (optional)' : ''}
                    </FieldLabel>
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
                            form.setData('default_charge_item_id', value)
                        }
                        placeholder="Pick an inclusive-tax charge item"
                        aria-invalid={!!form.errors.default_charge_item_id}
                    />
                    <FieldDescription>
                        Required only for external POS API room charges. Taxes
                        on this charge item must be inclusive.
                    </FieldDescription>
                    <FieldError>
                        {form.errors.default_charge_item_id}
                    </FieldError>
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="outlet-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                    <FieldLabel htmlFor="outlet-active">Active</FieldLabel>
                </Field>
            </FormOverlay>
        </>
    );
}

PosOutletsIndex.layout = {
    breadcrumbs: posSettingsBreadcrumbs('outlets'),
};
