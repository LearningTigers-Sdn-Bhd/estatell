import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    store as storeReason,
    update as updateReason,
} from '@/routes/settings/pos/void-reasons';

export type VoidReasonRow = {
    id: number;
    label: string;
    requires_manager_pin: boolean;
    default_return_stock: boolean;
    active: boolean;
};

export function PosVoidReasonForm({
    row,
    onClose,
}: {
    row: VoidReasonRow | null;
    onClose: () => void;
}) {
    const form = useForm({
        label: row?.label ?? '',
        requires_manager_pin: row?.requires_manager_pin ?? true,
        default_return_stock: row?.default_return_stock ?? false,
        active: row?.active ?? true,
        sort_order: 0,
    });

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? 'Edit void reason' : 'Add void reason'}
            description="Cashiers pick a reason whenever they void a fired dish or cancel an open check."
            errors={form.errors}
            processing={form.processing}
            submitLabel={row ? 'Save reason' : 'Create reason'}
            onSubmit={() => {
                const options = { preserveScroll: true, onSuccess: onClose };

                if (row) {
                    form.put(updateReason.url({ reason: row.id }), options);
                } else {
                    form.post(storeReason.url(), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.label}>
                <FieldLabel htmlFor="reason-label">Reason label</FieldLabel>
                <Input
                    id="reason-label"
                    maxLength={80}
                    placeholder="e.g. Guest changed mind"
                    value={form.data.label}
                    onChange={(event) =>
                        form.setData('label', event.target.value)
                    }
                    autoFocus
                />
                <FieldError>{form.errors.label}</FieldError>
            </Field>

            <div className="divide-y overflow-hidden rounded-xl border bg-card">
                <div className="flex items-center justify-between gap-4 px-4 py-3">
                    <div className="flex flex-col gap-0.5">
                        <FieldLabel
                            htmlFor="reason-pin"
                            className="text-sm font-medium text-foreground"
                        >
                            Require manager PIN
                        </FieldLabel>
                        <p className="text-xs text-muted-foreground">
                            Prompt for a manager override before completing this
                            void.
                        </p>
                    </div>
                    <Switch
                        id="reason-pin"
                        checked={form.data.requires_manager_pin}
                        onCheckedChange={(checked) =>
                            form.setData('requires_manager_pin', checked)
                        }
                    />
                </div>

                <div className="flex items-center justify-between gap-4 px-4 py-3">
                    <div className="flex flex-col gap-0.5">
                        <FieldLabel
                            htmlFor="reason-stock"
                            className="text-sm font-medium text-foreground"
                        >
                            Return stock by default
                        </FieldLabel>
                        <p className="text-xs text-muted-foreground">
                            Put recipe ingredients back into inventory (turn off
                            for kitchen spoilage).
                        </p>
                    </div>
                    <Switch
                        id="reason-stock"
                        checked={form.data.default_return_stock}
                        onCheckedChange={(checked) =>
                            form.setData('default_return_stock', checked)
                        }
                    />
                </div>

                <div className="flex items-center justify-between gap-4 px-4 py-3">
                    <div className="flex flex-col gap-0.5">
                        <FieldLabel
                            htmlFor="reason-active"
                            className="text-sm font-medium text-foreground"
                        >
                            Reason active
                        </FieldLabel>
                        <p className="text-xs text-muted-foreground">
                            Show this option in the cashier void picker.
                        </p>
                    </div>
                    <Switch
                        id="reason-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                </div>
            </div>
        </FormOverlay>
    );
}
