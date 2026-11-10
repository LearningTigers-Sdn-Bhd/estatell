import { useForm } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import { DatePicker } from '@/components/pms/date-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    preview as groupPreview,
    store as groupStore,
} from '@/routes/reservations/add-ons';
import { store } from '@/routes/reservations/units/add-ons';

type Unit = App.Data.Reservations.ReservationUnitOptionData;
type ChargeItem = App.Data.Reservations.ChargeItemOptionData;
type Preview = App.Data.Reservations.GroupAddOnPreviewData;

const NIGHTLY = ['per_night', 'per_guest_night'];
const ALL = 'all';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    /** The units that can take an add-on (Confirmed and CheckedIn). */
    units: Unit[];
    /** The units picked when the Dialog opens. Every unit when left out. */
    initialUnitIds?: number[];
    chargeItems?: ChargeItem[];
    currency: App.Data.Property.CurrencyData | null;
};

function PreviewPanel({
    preview,
    currency,
}: {
    preview: Preview | null;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const lines = preview?.lines ?? [];

    return (
        <div className="flex min-w-0 flex-col gap-2 rounded-lg border bg-muted p-3">
            <div className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-medium">Preview</span>
                {preview !== null && (
                    <span className="text-xs text-muted-foreground">
                        {lines.length} {lines.length === 1 ? 'unit' : 'units'}
                    </span>
                )}
            </div>
            {preview === null ? (
                <div className="flex flex-1 items-center justify-center">
                    <EmptyState
                        icon={Receipt}
                        title="No preview yet"
                        description="Pick a charge item to see what each unit gets."
                    />
                </div>
            ) : (
                <div className="flex flex-col overflow-hidden rounded-md border bg-background text-sm">
                    <div className="grid grid-cols-[minmax(0,1fr)_3.5rem_3.5rem_5rem] gap-2 border-b px-2 py-1.5 font-medium">
                        <span>Unit</span>
                        <span className="text-right">Guests</span>
                        <span className="text-right">Nights</span>
                        <span className="text-right">Total</span>
                    </div>
                    <div className="max-h-56 divide-y overflow-y-auto">
                        {lines.map((line) => (
                            <div
                                key={line.unitId}
                                className="grid grid-cols-[minmax(0,1fr)_3.5rem_3.5rem_5rem] gap-2 px-2 py-1.5"
                            >
                                <span className="truncate">{line.label}</span>
                                <span className="text-right tabular-nums">
                                    {line.guests}
                                </span>
                                <span className="text-right tabular-nums">
                                    {line.nights ?? '—'}
                                </span>
                                <span className="text-right tabular-nums">
                                    {line.total === null ? (
                                        <span className="text-muted-foreground">
                                            {line.note ?? '—'}
                                        </span>
                                    ) : (
                                        <MoneyText
                                            amount={line.total}
                                            currency={currency}
                                            plain
                                        />
                                    )}
                                </span>
                            </div>
                        ))}
                    </div>
                    <div className="grid grid-cols-[minmax(0,1fr)_5rem] gap-2 border-t bg-muted/50 px-2 py-1.5 font-medium">
                        <span>Total before tax</span>
                        <span className="text-right tabular-nums">
                            <MoneyText
                                amount={preview.total}
                                currency={currency}
                            />
                        </span>
                    </div>
                </div>
            )}
            <p className="text-xs text-muted-foreground">
                Tax is added when it posts to the folio.
            </p>
        </div>
    );
}

function AddAddOnForm({
    open,
    onOpenChange,
    reservationId,
    units,
    initialUnitIds,
    chargeItems = [],
    currency,
    loading,
}: Props & { loading: boolean }) {
    useFormOptions(open, !loading, 'chargeItems');

    const isGroup = units.length > 1;
    const unitIds = units.map((one) => one.id);
    const form = useForm({
        unit_ids: (initialUnitIds ?? unitIds)
            .filter((id) => unitIds.includes(id))
            .map(String),
        charge_item_id: '',
        quantity: '1',
        service_date: '',
        unit_amount: '',
    });
    const { data, errors } = form;
    const item = chargeItems.find(
        (one) => String(one.id) === data.charge_item_id,
    );
    const picked = units.filter((one) =>
        data.unit_ids.includes(String(one.id)),
    );
    const allPicked = isGroup && picked.length === units.length;
    const isNightly = item !== undefined && NIGHTLY.includes(item.chargingUnit);
    const showAmount =
        item !== undefined &&
        (item.allowOverride || item.defaultAmount === null);
    const fields = (values: typeof data) => ({
        charge_item_id: values.charge_item_id,
        quantity: item?.chargingUnit === 'per_item' ? values.quantity : '',
        service_date: isNightly ? '' : values.service_date,
        unit_amount: showAmount ? values.unit_amount : '',
    });
    const { preview } = useUnitPreview<Preview>(
        groupPreview.url({ reservation: reservationId }),
        open && isGroup && item !== undefined && picked.length > 0,
        {
            ...fields(data),
            unit_ids: picked.map((one) => one.id),
        },
        300,
    );
    const arrivals = picked.map((one) => one.arrival).sort();
    const departures = picked.map((one) => one.departure).sort();
    const unitItems = [
        { value: ALL, label: `All units (${units.length})` },
        ...units.map((one) => ({ value: String(one.id), label: one.label })),
    ];

    const pickUnits = (next: string[]) => {
        if (next.includes(ALL) && !allPicked) {
            form.setData('unit_ids', unitIds.map(String));

            return;
        }

        if (allPicked) {
            const chosen = next.filter((value) => value !== ALL);

            // From All units, picking one unit keeps only that unit; removing the All chip clears the pick.
            form.setData(
                'unit_ids',
                next.includes(ALL)
                    ? chosen.filter((value) => !data.unit_ids.includes(value))
                    : [],
            );

            return;
        }

        form.setData(
            'unit_ids',
            next.filter((value) => value !== ALL),
        );
    };

    const submit = () => {
        const single = picked.length === 1 ? picked[0] : null;

        form.transform((values) =>
            single === null
                ? { ...fields(values), unit_ids: picked.map((one) => one.id) }
                : fields(values),
        );
        form.post(
            single === null
                ? groupStore.url({ reservation: reservationId })
                : store.url({ reservation: reservationId, unit: single.id }),
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    const inputs = (
        <div className="flex min-w-0 flex-col gap-4">
            {isGroup && (
                <Field data-invalid={!!errors.unit_ids}>
                    <FieldLabel htmlFor="add-on-unit_ids">Units</FieldLabel>
                    <MultiCombobox
                        id="add-on-unit_ids"
                        name="unit_ids"
                        items={unitItems}
                        value={allPicked ? [ALL] : data.unit_ids}
                        onValueChange={pickUnits}
                        placeholder="Choose the units, e.g. F1, F2"
                        emptyLabel="No matching units."
                        invalid={!!errors.unit_ids}
                        maxChips={2}
                    />
                    <FieldError>{errors.unit_ids}</FieldError>
                </Field>
            )}
            <Field data-invalid={!!errors.charge_item_id}>
                <FieldLabel htmlFor="add-on-charge_item_id">
                    Charge item
                </FieldLabel>
                <EnumSelect
                    id="add-on-charge_item_id"
                    options={chargeItems.map((one) => ({
                        value: String(one.id),
                        label: `${one.name} · ${one.chargingUnitLabel}`,
                    }))}
                    value={data.charge_item_id}
                    placeholder="Choose a charge item"
                    aria-invalid={!!errors.charge_item_id}
                    onValueChange={(value) => {
                        const next = chargeItems.find(
                            (one) => String(one.id) === value,
                        );

                        form.setData((current) => ({
                            ...current,
                            charge_item_id: value,
                            unit_amount: next?.defaultAmount ?? '',
                        }));
                    }}
                />
                <FieldError>{errors.charge_item_id}</FieldError>
            </Field>
            {item?.chargingUnit === 'per_item' && (
                <Field data-invalid={!!errors.quantity}>
                    <FieldLabel htmlFor="add-on-quantity">Quantity</FieldLabel>
                    <Input
                        id="add-on-quantity"
                        type="number"
                        min={1}
                        max={999}
                        placeholder="e.g. 2"
                        value={data.quantity}
                        onChange={(event) =>
                            form.setData('quantity', event.target.value)
                        }
                        aria-invalid={!!errors.quantity}
                    />
                    {isGroup && (
                        <FieldDescription>For each unit.</FieldDescription>
                    )}
                    <FieldError>{errors.quantity}</FieldError>
                </Field>
            )}
            {item !== undefined && !isNightly && (
                <Field data-invalid={!!errors.service_date}>
                    <FieldLabel htmlFor="add-on-service_date">
                        Service date
                    </FieldLabel>
                    <DatePicker
                        id="add-on-service_date"
                        value={data.service_date}
                        min={arrivals[0]}
                        max={departures[departures.length - 1]}
                        onChange={(value) =>
                            form.setData('service_date', value)
                        }
                        placeholder="Optional"
                        aria-invalid={!!errors.service_date}
                    />
                    <FieldDescription>
                        Leave empty to charge it at check-in.
                    </FieldDescription>
                    <FieldError>{errors.service_date}</FieldError>
                </Field>
            )}
            {isNightly && (
                <p className="text-sm text-muted-foreground">
                    This item is charged for each night of the stay.
                </p>
            )}
            {showAmount && (
                <Field data-invalid={!!errors.unit_amount}>
                    <FieldLabel htmlFor="add-on-unit_amount">
                        Unit amount
                    </FieldLabel>
                    <MoneyInput
                        id="add-on-unit_amount"
                        value={data.unit_amount}
                        onChange={(value) => form.setData('unit_amount', value)}
                        currency={currency}
                        invalid={!!errors.unit_amount}
                    />
                    {item.defaultAmount !== null && (
                        <FieldDescription>
                            The catalogue price is {item.defaultAmount}.
                        </FieldDescription>
                    )}
                    <FieldError>{errors.unit_amount}</FieldError>
                </Field>
            )}
        </div>
    );

    return (
        <FormOverlay
            variant="dialog"
            size={isGroup ? 'lg' : 'md'}
            open={open}
            onOpenChange={onOpenChange}
            title="Add add-on"
            description="An extra sold with a unit, such as breakfast or an airport transfer."
            errors={errors}
            onSubmit={submit}
            submitLabel={
                picked.length > 1
                    ? `Add to ${picked.length} units`
                    : 'Add add-on'
            }
            processing={form.processing}
            submitDisabled={item === undefined || picked.length === 0}
            loading={loading}
        >
            {isGroup ? (
                <div className="grid gap-4 lg:min-h-80 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] lg:gap-6">
                    {inputs}
                    <PreviewPanel
                        preview={item === undefined ? null : preview}
                        currency={currency}
                    />
                </div>
            ) : (
                inputs
            )}
        </FormOverlay>
    );
}

/**
 * Adds a charge item to one unit, or to several units of a group at once. The fields change with the item's charging
 * unit. On a group the Dialog has two columns, with a preview of each unit on the right.
 */
export function AddAddOnDialog(props: Props) {
    return (
        <AddAddOnForm
            key={useFreshOnOpen(props.open)}
            {...props}
            loading={props.chargeItems === undefined}
        />
    );
}
