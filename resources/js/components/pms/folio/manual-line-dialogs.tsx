import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { taxLabel } from '@/components/pms/charge-item-taxes-field';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { useRetainedValue } from '@/components/pms/use-retained-value';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { split } from '@/routes/reservations/folios/lines';
import {
    add,
    recalculate,
    remove,
} from '@/routes/reservations/folios/lines/tax';

type Row = App.Data.Folio.FolioRowData;
/** What the Dialogs need from the page that holds them: the manual folio page or the folio tab. */
export type ManualLineContext = {
    reservationId: number;
    folioId: number;
    currency: App.Data.Property.CurrencyData | null;
    folios: App.Data.Folio.ManualFolioOptionData[];
    taxes: App.Data.Catalogues.ChargeItemTaxData[];
};

type Line = App.Data.Folio.ManualLineData;

export type ManualLineAction =
    | 'split'
    | 'remove_tax'
    | 'add_tax'
    | 'recalculate_tax';

type Target = { row: Row; line: Line; action: ManualLineAction };

type FormProps = {
    open: boolean;
    onClose: () => void;
    context: ManualLineContext;
    reason: string;
    row: Row;
    line: Line;
};

const cents = (amount: string): number => Math.round(Number(amount) * 100);

function ReasonField({
    id,
    value,
    onChange,
    error,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor={id}>Reason</FieldLabel>
            <Textarea
                id={id}
                rows={2}
                maxLength={500}
                placeholder="e.g. Guest paid cash to the night staff"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={!!error}
            />
            <FieldError>{error}</FieldError>
        </Field>
    );
}

/**
 * The Dialog's UUID is the batch key, so a second click writes nothing twice.
 */
function useBatchKey(): string {
    const [key] = useState(() => crypto.randomUUID());

    return key;
}

/** Posts at once and keeps the page's draft rows. */
function postOptions(onClose: () => void) {
    return {
        preserveScroll: true,
        preserveState: true,
        except: KEEP_OPTIONS,
        onSuccess: onClose,
    };
}

function SplitForm({ open, onClose, context, reason, row, line }: FormProps) {
    const key = useBatchKey();
    const form = useForm({
        reason,
        parts: [
            { folio_id: String(context.folioId), amount: '' },
            {
                folio_id: String(
                    context.folios.find((one) => one.id !== context.folioId)
                        ?.id ?? context.folioId,
                ),
                amount: '',
            },
        ],
    });
    const errors = form.errors as Partial<Record<string, string>>;
    const parts = form.data.parts;
    const left =
        cents(line.total) -
        parts.reduce((sum, part) => sum + cents(part.amount || '0'), 0);
    const ready =
        left === 0 &&
        form.data.reason.trim() !== '' &&
        parts.every((part) => cents(part.amount || '0') > 0);
    const options = context.folios.map((one) => ({
        value: String(one.id),
        label: one.label,
    }));
    const setPart = (index: number, patch: Partial<(typeof parts)[number]>) =>
        form.setData(
            'parts',
            parts.map((part, at) =>
                at === index ? { ...part, ...patch } : part,
            ),
        );

    return (
        <FormOverlay
            variant="dialog"
            size="md"
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title={`Split ${row.description}`}
            description="The line is reversed and posted again in parts. Tax is worked out again on each part, and a flat tax goes to the first part."
            errors={errors}
            onSubmit={() => {
                form.transform((values) => ({
                    ...values,
                    idempotency_key: key,
                }));
                form.post(
                    split.url({
                        reservation: context.reservationId,
                        folio: context.folioId,
                        line: line.lineId,
                    }),
                    postOptions(onClose),
                );
            }}
            submitLabel="Split line"
            processing={form.processing}
            submitDisabled={!ready}
        >
            <Field>
                <FieldLabel>Parts</FieldLabel>
                <div className="flex flex-col gap-2">
                    {parts.map((part, index) => (
                        <div key={index} className="flex items-start gap-2">
                            <div className="min-w-0 flex-1">
                                <EnumSelect
                                    className="w-full"
                                    aria-label={`Folio of part ${index + 1}`}
                                    options={options}
                                    value={part.folio_id}
                                    onValueChange={(value) =>
                                        setPart(index, { folio_id: value })
                                    }
                                />
                            </div>
                            <div className="w-36">
                                <MoneyInput
                                    id={`split-amount-${index}`}
                                    value={part.amount}
                                    onChange={(value) =>
                                        setPart(index, { amount: value })
                                    }
                                    currency={context.currency}
                                    invalid={!!errors[`parts.${index}.amount`]}
                                />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={`Remove part ${index + 1}`}
                                disabled={parts.length <= 2}
                                onClick={() =>
                                    form.setData(
                                        'parts',
                                        parts.filter((_, at) => at !== index),
                                    )
                                }
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    ))}
                </div>
                <div className="flex items-center justify-between gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={parts.length >= 20}
                        onClick={() =>
                            form.setData('parts', [
                                ...parts,
                                {
                                    folio_id: String(context.folioId),
                                    amount: '',
                                },
                            ])
                        }
                    >
                        <Plus data-icon="inline-start" />
                        Add folio
                    </Button>
                    <FieldDescription>
                        Line total{' '}
                        <MoneyText
                            amount={line.total}
                            currency={context.currency}
                        />
                        {left !== 0 && (
                            <>
                                {' · '}
                                {left > 0 ? 'left' : 'over'}{' '}
                                <MoneyText
                                    amount={(Math.abs(left) / 100).toFixed(2)}
                                    currency={context.currency}
                                />
                            </>
                        )}
                    </FieldDescription>
                </div>
            </Field>
            <ReasonField
                id="split-reason"
                value={form.data.reason}
                onChange={(value) => form.setData('reason', value)}
                error={errors.reason}
            />
        </FormOverlay>
    );
}

function RemoveTaxForm({
    open,
    onClose,
    context,
    reason,
    row,
    line,
}: FormProps) {
    const key = useBatchKey();
    const form = useForm({ reason, tax_ids: [] as string[] });
    const errors = form.errors as Partial<Record<string, string>>;

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title={`Remove tax from ${row.description}`}
            description="Only the tax lines you choose are reversed. The charge stays. A room night's tax is fixed for that night only: the night audit charges the next nights with the normal taxes."
            errors={errors}
            onSubmit={() => {
                form.transform((values) => ({
                    ...values,
                    idempotency_key: key,
                }));
                form.post(
                    remove.url({
                        reservation: context.reservationId,
                        folio: context.folioId,
                        line: line.lineId,
                    }),
                    postOptions(onClose),
                );
            }}
            submitLabel="Remove tax"
            submitVariant="destructive"
            processing={form.processing}
            submitDisabled={
                form.data.tax_ids.length === 0 || form.data.reason.trim() === ''
            }
        >
            <Field data-invalid={!!errors.tax_ids}>
                <FieldLabel htmlFor="remove-tax-ids">
                    Taxes to remove
                </FieldLabel>
                <MultiCombobox
                    id="remove-tax-ids"
                    name="tax_ids"
                    items={line.taxes.map((tax) => ({
                        value: String(tax.lineId),
                        label: `${tax.name} · ${tax.amount}`,
                    }))}
                    value={form.data.tax_ids}
                    onValueChange={(value) => form.setData('tax_ids', value)}
                    placeholder="Choose taxes"
                    invalid={!!errors.tax_ids}
                />
                <FieldError>{errors.tax_ids}</FieldError>
            </Field>
            <ReasonField
                id="remove-tax-reason"
                value={form.data.reason}
                onChange={(value) => form.setData('reason', value)}
                error={errors.reason}
            />
        </FormOverlay>
    );
}

function AddTaxForm({ open, onClose, context, reason, row, line }: FormProps) {
    const key = useBatchKey();
    const form = useForm({ reason, tax_ids: [] as string[] });
    const errors = form.errors as Partial<Record<string, string>>;
    const onLine = new Set(line.taxes.map((tax) => tax.taxId));
    const options = context.taxes
        .filter((tax) => tax.enabled && !tax.isInclusive && !onLine.has(tax.id))
        .map((tax) => ({
            value: String(tax.id),
            label: context.currency
                ? taxLabel(tax, context.currency)
                : tax.name,
        }));

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title={`Add tax to ${row.description}`}
            description="A new tax line is posted under the charge. The charge itself does not change."
            errors={errors}
            onSubmit={() => {
                form.transform((values) => ({
                    ...values,
                    idempotency_key: key,
                }));
                form.post(
                    add.url({
                        reservation: context.reservationId,
                        folio: context.folioId,
                        line: line.lineId,
                    }),
                    postOptions(onClose),
                );
            }}
            submitLabel="Add tax"
            processing={form.processing}
            submitDisabled={
                form.data.tax_ids.length === 0 || form.data.reason.trim() === ''
            }
        >
            <Field data-invalid={!!errors.tax_ids}>
                <FieldLabel htmlFor="add-tax-ids">Taxes to add</FieldLabel>
                <MultiCombobox
                    id="add-tax-ids"
                    name="tax_ids"
                    items={options}
                    value={form.data.tax_ids}
                    onValueChange={(value) => form.setData('tax_ids', value)}
                    placeholder="Choose taxes"
                    emptyLabel="No other taxes can be added."
                    invalid={!!errors.tax_ids}
                />
                <FieldError>{errors.tax_ids}</FieldError>
            </Field>
            <ReasonField
                id="add-tax-reason"
                value={form.data.reason}
                onChange={(value) => form.setData('reason', value)}
                error={errors.reason}
            />
        </FormOverlay>
    );
}

function RecalculateTaxForm({
    open,
    onClose,
    context,
    reason,
    row,
    line,
}: FormProps) {
    const key = useBatchKey();
    const form = useForm({ reason });
    const errors = form.errors as Partial<Record<string, string>>;

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title={`Recalculate tax on ${row.description}`}
            description="The current tax lines are reversed and new ones are worked out with the taxes of the item and the guest's nationality today."
            errors={errors}
            onSubmit={() => {
                form.transform((values) => ({
                    ...values,
                    idempotency_key: key,
                }));
                form.post(
                    recalculate.url({
                        reservation: context.reservationId,
                        folio: context.folioId,
                        line: line.lineId,
                    }),
                    postOptions(onClose),
                );
            }}
            submitLabel="Recalculate tax"
            processing={form.processing}
            submitDisabled={form.data.reason.trim() === ''}
        >
            <ReasonField
                id="recalculate-tax-reason"
                value={form.data.reason}
                onChange={(value) => form.setData('reason', value)}
                error={errors.reason}
            />
        </FormOverlay>
    );
}

/**
 * The Dialogs of the manual folio page's row menu: Split, Remove tax, Add tax and Recalculate tax. Each posts at once
 * with its own reason, which starts as the page's reason. See docs/13-folio/08-manual-folio.md#row-menu-on-posted-lines.
 */
export function ManualLineDialogs({
    context,
    reason,
    target,
    onClose,
}: {
    context: ManualLineContext;
    reason: string;
    target: Target | null;
    onClose: () => void;
}) {
    const open = target !== null;
    const kept = useRetainedValue(target);
    const fresh = useFreshOnOpen(open);

    if (kept === null) {
        return null;
    }

    const props = {
        key: `${kept.line.lineId}-${kept.action}-${fresh}`,
        open,
        onClose,
        context,
        reason,
        row: kept.row,
        line: kept.line,
    };

    switch (kept.action) {
        case 'split':
            return <SplitForm {...props} />;
        case 'remove_tax':
            return <RemoveTaxForm {...props} />;
        case 'add_tax':
            return <AddTaxForm {...props} />;
        default:
            return <RecalculateTaxForm {...props} />;
    }
}
