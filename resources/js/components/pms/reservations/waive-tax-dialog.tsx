import { router, useForm } from '@inertiajs/react';
import { Building2, ReceiptText } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MultiSelectWithAll } from '@/components/pms/multi-select-with-all';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store as storeGroup } from '@/routes/reservations/tax-waivers';
import { destroy, store } from '@/routes/reservations/units/tax-waivers';

type Unit = App.Data.Reservations.ReservationUnitData;
type Options = App.Data.Reservations.UnitTaxWaiverFormData;

type Fields = {
    tax_ids: string[];
    reason: App.Enums.TaxWaiverReason;
    paid_to: string;
    reference: string;
};

const REASON_ICONS: Record<App.Enums.TaxWaiverReason, LucideIcon> = {
    paid_elsewhere: Building2,
    guest_receipt: ReceiptText,
};

/** The unit statuses that can take a waiver, as `UnitAction::WaiveTax` allows. */
const WAIVABLE = ['confirmed', 'checked_in', 'checked_out'];

/** True when at least one room can take a waiver, so More offers Taxes paid elsewhere. */
export function hasWaivableRoom(
    units: App.Data.Reservations.ReservationUnitOptionData[],
): boolean {
    return units.some((unit) => WAIVABLE.includes(unit.status.value));
}

function initialFields(options: Options | null | undefined): Fields {
    const taxes = options?.taxes ?? [];

    return {
        tax_ids: taxes.length === 1 ? [taxes[0].value] : [],
        reason: 'paid_elsewhere',
        paid_to: options?.paidTo ?? '',
        reference: options?.reference ?? '',
    };
}

function isIncomplete(data: Fields, options: Options | null | undefined) {
    return (
        (options?.taxes ?? []).length === 0 ||
        data.tax_ids.length === 0 ||
        data.reference.trim() === '' ||
        (data.reason === 'paid_elsewhere' && data.paid_to.trim() === '')
    );
}

/** The taxes, Paid by, Paid to and the reference, shared by the one-room and the many-room Dialogs. */
function TaxWaiverFields({
    idPrefix,
    options,
    data,
    errors,
    setData,
}: {
    idPrefix: string;
    options: Options | null | undefined;
    data: Fields;
    errors: Partial<Record<string, string>>;
    setData: <K extends keyof Fields>(key: K, value: Fields[K]) => void;
}) {
    const paidElsewhere = data.reason === 'paid_elsewhere';

    return (
        <>
            <FieldSet data-invalid={!!errors.tax_ids}>
                <FieldLegend variant="label">Taxes</FieldLegend>
                <div className="flex flex-wrap gap-x-6 gap-y-2">
                    {(options?.taxes ?? []).map((tax) => (
                        <Field
                            key={tax.value}
                            orientation="horizontal"
                            className="w-auto"
                        >
                            <Checkbox
                                id={`${idPrefix}-tax-${tax.value}`}
                                checked={data.tax_ids.includes(tax.value)}
                                onCheckedChange={(checked) =>
                                    setData(
                                        'tax_ids',
                                        checked
                                            ? [...data.tax_ids, tax.value]
                                            : data.tax_ids.filter(
                                                  (id) => id !== tax.value,
                                              ),
                                    )
                                }
                            />
                            <FieldLabel
                                htmlFor={`${idPrefix}-tax-${tax.value}`}
                                className="font-normal"
                            >
                                {tax.label}
                            </FieldLabel>
                        </Field>
                    ))}
                </div>
                <FieldError>{errors.tax_ids}</FieldError>
            </FieldSet>
            <ChoiceCards
                id={`${idPrefix}-reason`}
                legend="Paid by"
                choices={(options?.reasons ?? []).map((reason) => ({
                    value: reason.value,
                    label: reason.label,
                    description: reason.description,
                    icon: REASON_ICONS[
                        reason.value as App.Enums.TaxWaiverReason
                    ],
                }))}
                value={data.reason}
                onValueChange={(value) =>
                    setData('reason', value as App.Enums.TaxWaiverReason)
                }
                error={errors.reason}
            />
            {paidElsewhere && (
                <Field data-invalid={!!errors.paid_to}>
                    <FieldLabel htmlFor={`${idPrefix}-paid-to`}>
                        Paid to
                    </FieldLabel>
                    <Input
                        id={`${idPrefix}-paid-to`}
                        maxLength={60}
                        placeholder="e.g. Trip.com"
                        value={data.paid_to}
                        onChange={(event) =>
                            setData('paid_to', event.target.value)
                        }
                        aria-invalid={!!errors.paid_to}
                    />
                    <FieldError>{errors.paid_to}</FieldError>
                </Field>
            )}
            <Field data-invalid={!!errors.reference}>
                <FieldLabel htmlFor={`${idPrefix}-reference`}>
                    {paidElsewhere ? 'Booking number' : 'Receipt number'}
                </FieldLabel>
                <Input
                    id={`${idPrefix}-reference`}
                    maxLength={60}
                    placeholder={
                        paidElsewhere
                            ? 'e.g. 1128000012345678'
                            : 'e.g. TTX-88231'
                    }
                    value={data.reference}
                    onChange={(event) =>
                        setData('reference', event.target.value)
                    }
                    aria-invalid={!!errors.reference}
                />
                <FieldError>{errors.reference}</FieldError>
            </Field>
        </>
    );
}

function WaivedRow({
    waiver,
    reservationId,
    unitId,
}: {
    waiver: App.Data.Reservations.UnitTaxWaiverData;
    reservationId: number;
    unitId: number;
}) {
    const [removing, setRemoving] = useState(false);

    return (
        <li className="flex items-center gap-3 rounded-lg border bg-card px-3 py-1.5 text-sm">
            <span className="flex min-w-0 flex-col">
                <span className="font-medium">{waiver.taxName}</span>
                <span className="truncate text-muted-foreground">
                    {waiver.summary}
                </span>
            </span>
            <Button
                variant="outline"
                size="sm"
                className="ml-auto"
                disabled={removing}
                onClick={() =>
                    router.delete(
                        destroy.url({
                            reservation: reservationId,
                            unit: unitId,
                            taxId: waiver.taxId,
                        }),
                        {
                            preserveScroll: true,
                            onStart: () => setRemoving(true),
                            onFinish: () => setRemoving(false),
                        },
                    )
                }
            >
                {removing ? 'Removing…' : 'Remove'}
            </Button>
        </li>
    );
}

type UnitProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
};

function WaiveTaxForm({ open, onOpenChange, reservationId, unit }: UnitProps) {
    const options = unit.taxWaiverForm;
    const form = useForm<Fields>(initialFields(options));
    const { data, errors } = form;

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Taxes paid elsewhere"
            description="Stop charging a tax for this room. Taxes already posted on its nights are reversed."
            errors={errors}
            dirty={form.isDirty}
            onSubmit={() =>
                form.post(
                    store.url({ reservation: reservationId, unit: unit.id }),
                    {
                        preserveScroll: true,
                        onSuccess: () => onOpenChange(false),
                    },
                )
            }
            submitLabel="Mark as paid"
            processing={form.processing}
            submitDisabled={isIncomplete(data, options)}
        >
            {unit.taxWaivers.length > 0 && (
                <FieldSet>
                    <FieldLegend variant="label">Already waived</FieldLegend>
                    <ul className="flex flex-col gap-2">
                        {unit.taxWaivers.map((waiver) => (
                            <WaivedRow
                                key={waiver.taxId}
                                waiver={waiver}
                                reservationId={reservationId}
                                unitId={unit.id}
                            />
                        ))}
                    </ul>
                </FieldSet>
            )}
            {(options?.taxes ?? []).length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Every tax is already waived for this room.
                </p>
            ) : (
                <TaxWaiverFields
                    idPrefix="waive-tax"
                    options={options}
                    data={data}
                    errors={errors}
                    setData={form.setData}
                />
            )}
        </FormOverlay>
    );
}

/**
 * Taxes paid elsewhere for one room, from its ⋯ menu: stop charging a tax because an OTA, agent or company collected
 * it, or the guest has a receipt. It also lists the taxes already waived, each with Remove. See
 * docs/06-catalogues/03-taxes.md#tax-waivers.
 */
export function WaiveTaxDialog(props: UnitProps) {
    return <WaiveTaxForm key={useFreshOnOpen(props.open)} {...props} />;
}

type GroupProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    units: App.Data.Reservations.ReservationUnitOptionData[];
    options?: Options | null;
};

function WaiveGroupTaxForm({
    open,
    onOpenChange,
    reservationId,
    units,
    options,
}: GroupProps) {
    useFormOptions(open, options !== undefined, 'taxWaiverOptions');

    const choices = units
        .filter((unit) => WAIVABLE.includes(unit.status.value))
        .map((unit) => ({ id: unit.id, label: unit.label }));
    const form = useForm<Fields & { unit_ids: number[] }>({
        unit_ids: choices.map((choice) => choice.id),
        ...initialFields(options),
    });
    const { data, errors } = form;
    const isGroup = choices.length > 1;

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Taxes paid elsewhere"
            description={
                isGroup
                    ? 'Stop charging a tax for the rooms you choose. Taxes already posted on their nights are reversed. A room that already has the tax waived keeps it.'
                    : 'Stop charging a tax for this room. Taxes already posted on its nights are reversed.'
            }
            errors={errors}
            dirty={form.isDirty}
            loading={options === undefined}
            onSubmit={() =>
                form.post(storeGroup.url({ reservation: reservationId }), {
                    preserveScroll: true,
                    except: KEEP_OPTIONS,
                    onSuccess: () => onOpenChange(false),
                })
            }
            submitLabel="Mark as paid"
            processing={form.processing}
            submitDisabled={
                data.unit_ids.length === 0 || isIncomplete(data, options)
            }
        >
            {isGroup && (
                <Field data-invalid={!!errors.unit_ids}>
                    <FieldLabel htmlFor="waive-group-tax-unit_ids">
                        Rooms
                    </FieldLabel>
                    <MultiSelectWithAll
                        id="waive-group-tax-unit_ids"
                        name="unit_ids"
                        choices={choices}
                        allLabel="All rooms"
                        placeholder="Choose the rooms, e.g. 101, 102"
                        emptyLabel="No matching rooms."
                        value={data.unit_ids}
                        onValueChange={(value) =>
                            form.setData('unit_ids', value)
                        }
                        invalid={!!errors.unit_ids}
                    />
                    <FieldError>{errors.unit_ids}</FieldError>
                </Field>
            )}
            {(options?.taxes ?? []).length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    The property has no taxes turned on.
                </p>
            ) : (
                <TaxWaiverFields
                    idPrefix="waive-group-tax"
                    options={options}
                    data={data}
                    errors={errors}
                    setData={(key, value) =>
                        form.setData((current) => ({
                            ...current,
                            [key]: value,
                        }))
                    }
                />
            )}
        </FormOverlay>
    );
}

/**
 * Taxes paid elsewhere for several rooms of the reservation at once, from More. The rooms list shows only on a group;
 * every room that can take a waiver is picked by default. Remove stays on each room's own Dialog.
 */
export function WaiveGroupTaxDialog(props: GroupProps) {
    return (
        <WaiveGroupTaxForm
            key={`${useFreshOnOpen(props.open)}-${props.options === undefined ? 0 : 1}`}
            {...props}
        />
    );
}
